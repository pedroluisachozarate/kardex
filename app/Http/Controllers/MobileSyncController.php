<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Intervention;
use App\Models\Photo;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileSyncController extends Controller
{
    public function login(Request $request)
    {
        abort_if(config('mobile.enabled'), 404);
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 401, 'Credenciales incorrectas.');
        abort_unless($user->active && ! $user->must_change_password && $user->role === 'tecnico', 403, 'La cuenta debe ser de técnico, estar activa y tener su contraseña actualizada.');

        return response()->json($this->credentials($user))->header('Cache-Control', 'no-store');
    }

    public function credentials(User $user, ?string $state = null): array
    {
        $plain = Str::random(64);
        $expires = now()->addDays(30);
        DB::table('mobile_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $plain), 'app_state_hash' => $state ? hash('sha256', $state) : null, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return ['token' => $plain, 'expires_at' => $expires->toIso8601String(), 'user' => $user->only('id', 'name', 'email', 'role')];
    }

    public function logout(Request $request)
    {
        DB::table('mobile_tokens')->where('id', $request->attributes->get('mobile_token_id'))->delete();

        return response()->noContent();
    }

    private function projects(Request $request)
    {
        return Project::whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id));
    }

    private function version(Template $template): string
    {
        return hash('sha256', json_encode([$template->fields, $template->preventive, $template->corrective], JSON_UNESCAPED_UNICODE));
    }

    public function catalog(Request $request)
    {
        $projects = $this->projects($request)->with('client', 'templates', 'assets.equipment', 'assets.interventions.activities')->get();

        return response()->json(['user_id' => $request->user()->id, 'projects' => $projects->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'client' => $p->client->name,
            'templates' => $p->templates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'fields' => $t->fields, 'preventive' => $t->preventive, 'corrective' => $t->corrective, 'version' => $this->version($t)]),
            'equipment' => $p->assets->map(fn ($a) => ['id' => $a->id, 'number' => $a->number, 'kardex' => $a->kardex_code, 'template_id' => $a->template_id, 'identity' => $a->identity_data, 'technical' => $a->technical_data, 'interventions' => $a->interventions->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'type' => $i->type, 'date' => $i->date->format('Y-m-d'), 'status' => $i->status, 'created_by' => $i->created_by, 'progress' => $i->progress])]),
        ])])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'uuid' => 'required|uuid', 'project_id' => 'required|integer', 'template_id' => 'required|integer', 'template_version' => 'required|string|size:64',
            'asset_id' => 'nullable|integer', 'type' => ['required', Rule::in(['preventivo', 'correctivo'])], 'date' => 'required|date_format:Y-m-d',
            'technical' => 'present|array|max:50', 'technical.*' => 'nullable|string|max:1000',
            'location_text' => 'nullable|string|max:500', 'observations' => 'nullable|string|max:10000', 'conclusions' => 'nullable|string|max:10000', 'recommendations' => 'nullable|string|max:10000',
            'activities' => 'present|array|max:100', 'activities.*.result' => ['required', Rule::in(['pendiente', 'realizado', 'no_realizado', 'no_aplica'])],
            'activities.*.notes' => 'nullable|string|max:10000', 'activities.*.performed_on' => 'nullable|date_format:Y-m-d',
        ]);
        $record = DB::transaction(function () use ($request, $data) {
            $project = $this->projects($request)->lockForUpdate()->findOrFail($data['project_id']);
            $existing = Intervention::where('mobile_uuid', $data['uuid'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $request->user()->id && $existing->asset->project_id === $project->id, 409, 'Identificador local ya utilizado.');

                return $existing;
            }
            $template = $project->templates()->findOrFail($data['template_id']);
            abort_unless(hash_equals($this->version($template), $data['template_version']), 409, 'La configuración cambió. Conserva el borrador local y actualiza el catálogo antes de sincronizar.');
            $lines = $data['type'] === 'preventivo' ? $template->preventive : $template->corrective;
            abort_unless(array_is_list($data['activities']) && count($data['activities']) === count($lines), 422, 'Las actividades no coinciden con la configuración descargada.');
            $asset = null;
            if (! empty($data['asset_id'])) {
                $asset = $project->assets()->lockForUpdate()->findOrFail($data['asset_id']);
                abort_unless($asset->template_id === $template->id, 422);
            } else {
                $technical = [];
                $identity = [];
                foreach ($template->fields as $fieldIndex => $field) {
                    $value = $data['technical'][$fieldIndex] ?? null;
                    validator(['value' => $value], ['value' => ['nullable', match ($field['type']) {
                        'numero' => 'numeric','fecha' => 'date',default => 'string'
                    }, ...($field['type'] === 'lista' ? [Rule::in($field['options'])] : [])]])->validate();
                    $technical[$field['name']] = ['value' => $value, 'unit' => $field['unit'] ?? ''];
                    if (($column = Template::identityColumn($field['name'])) && $column !== 'name') {
                        $identity[$column] = $value;
                    }
                }
                $identity['name'] = $template->name.' #'.$project->code.'-'.sprintf('%03d', $project->next_kardex);
                $equipment = Equipment::create($identity);
                $asset = $project->assets()->create(['equipment_id' => $equipment->id, 'template_id' => $template->id, 'number' => $project->next_kardex, 'technical_data' => $technical, 'next_intervention' => 1]);
                $project->increment('next_kardex');
            }
            $record = $asset->interventions()->create(['created_by' => $request->user()->id, 'mobile_uuid' => $data['uuid'], 'mobile_sync_complete' => false, 'number' => $asset->next_intervention, 'status' => 'borrador', ...collect($data)->only('date', 'type', 'location_text', 'observations', 'conclusions', 'recommendations')->all()]);
            $asset->increment('next_intervention');
            foreach ($lines as $index => $line) {
                $parts = explode('|', $line, 2);
                $entry = $data['activities'][$index];
                $record->activities()->create(['name' => trim($parts[0]), 'procedure' => trim($parts[1] ?? ''), 'result' => $entry['result'], 'notes' => $entry['notes'] ?? null, 'performed_on' => $entry['performed_on'] ?? null, 'performed_by' => $entry['result'] === 'pendiente' ? null : $request->user()->id]);
            }
            AuditLog::create(['user_id' => $request->user()->id, 'intervention_id' => $record->id, 'action' => 'Borrador sincronizado desde el celular']);

            return $record;
        });

        return response()->json(['uuid' => $data['uuid'], 'intervention_id' => $record->id, 'asset_id' => $record->project_equipment_id, 'kardex' => $record->asset->kardex_code, 'number' => $record->number, 'status' => $record->status, 'activities' => $record->activities()->orderBy('id')->pluck('id')]);
    }

    public function complete(Request $request, Intervention $intervention)
    {
        abort_unless($intervention->created_by === $request->user()->id && $this->projects($request)->whereKey($intervention->asset->project_id)->exists(), 403);
        $data = $request->validate(['photos' => 'present|array|max:1000', 'photos.*' => 'required|uuid|distinct']);
        DB::transaction(function () use ($intervention, $data) {
            $record = Intervention::lockForUpdate()->findOrFail($intervention->id);
            $confirmed = Photo::whereHas('activity', fn ($q) => $q->where('intervention_id', $record->id))->whereIn('mobile_uuid', $data['photos'])->count();
            abort_unless($confirmed === count($data['photos']), 409, 'Todavía faltan fotos por sincronizar.');
            $record->update(['mobile_sync_complete' => true]);
        });

        return response()->json(['complete' => true, 'intervention_id' => $intervention->id]);
    }

    public function photo(Request $request, Intervention $intervention)
    {
        abort_unless($intervention->created_by === $request->user()->id && $this->projects($request)->whereKey($intervention->asset->project_id)->exists(), 403);
        $data = $request->validate(['uuid' => 'required|uuid', 'activity_index' => 'required|integer|min:0', 'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192']);
        $stored = null;
        try {
            $photo = DB::transaction(function () use ($request, $intervention, $data, &$stored) {
                $record = Intervention::lockForUpdate()->findOrFail($intervention->id);
                $activity = $record->activities()->orderBy('id')->skip($data['activity_index'])->firstOrFail();
                if ($existing = Photo::where('mobile_uuid', $data['uuid'])->first()) {
                    abort_unless($existing->activity_id === $activity->id, 409);

                    return $existing;
                }
                abort_unless($record->status === 'borrador', 409, 'El Kardex ya no está en borrador. Conserva la foto local.');
                abort_if($activity->photos()->count() >= 10, 422, 'Máximo 10 fotos por actividad.');
                $stored = $request->file('photo')->store('photos', 'local');
                if (! $stored) {
                    throw new \RuntimeException('No se pudo guardar la foto.');
                }

                return $activity->photos()->create(['path' => $stored, 'uploaded_by' => $request->user()->id, 'mobile_uuid' => $data['uuid']]);
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }throw $e;
        }

        return response()->json(['uuid' => $data['uuid'], 'photo_id' => $photo->id]);
    }
}
