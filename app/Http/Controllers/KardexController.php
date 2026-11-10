<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Intervention;
use App\Models\Photo;
use App\Models\Project;
use App\Models\ProjectEquipment;
use App\Models\Template;
use App\Models\User;
use App\Services\KardexPdf;
use App\Services\OptimizedPhoto;
use App\Services\PhotoThumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KardexController extends Controller
{
    private function admin(): void
    {

        abort_unless(auth()->user()->role === 'admin', 403);

    }

    private function access(Project $project, bool $write = false): void
    {

        $u = auth()->user();

        abort_unless($u->role === 'admin' || $project->users()->where('users.id', $u->id)->exists(), 403);

        if ($write) {

            abort_unless($u->role === 'tecnico', 403);

        }

    }

    private function draft(Intervention $i): void
    {

        $this->access($i->asset->project, true);

        abort_unless(in_array($i->status, ['borrador', 'observado']), 409, 'Solo se puede editar un borrador.');

    }

    private function log(string $action, ?Intervention $i = null, array $details = []): void
    {

        AuditLog::create(['user_id' => auth()->id(), 'intervention_id' => $i?->id, 'action' => $action, 'details' => $details]);

    }

    private function projectQuery()
    {

        $q = Project::with('client');

        if (auth()->user()->role !== 'admin') {

            $q->whereHas('users', fn ($q) => $q->where('users.id', auth()->id()));

        }

        return $q;

    }

    public function dashboard(Request $request)
    {
        $projects = $this->projectQuery()->latest()->get();
        $role = auth()->user()->role;
        $data = $request->validate([
            'project_id' => ['nullable', Rule::in($projects->pluck('id')->all())],
            'client_id' => ['nullable', Rule::in($projects->pluck('client_id')->all())],
            'technician_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $base = Intervention::whereHas('asset', fn ($q) => $q->whereIn('project_id', $projects->pluck('id')));
        if ($role === 'tecnico') {
            $base->where('created_by', auth()->id());
        }
        $technicians = User::whereIn('id', (clone $base)->select('created_by'))->orderBy('name')->get();
        if ($role !== 'tecnico' && filled($data['technician_id'] ?? null)) {
            abort_unless($technicians->contains('id', $data['technician_id']), 403);
            $base->where('created_by', $data['technician_id']);
        }
        if (filled($data['project_id'] ?? null)) {
            $base->whereHas('asset', fn ($q) => $q->where('project_id', $data['project_id']));
        }
        if ($role === 'admin' && filled($data['client_id'] ?? null)) {
            $base->whereHas('asset.project', fn ($q) => $q->where('client_id', $data['client_id']));
        }
        $availableDates = (clone $base)->select('date')->distinct()->orderBy('date')->pluck('date')->map(fn ($date) => substr((string) $date, 0, 10))->values();
        $today = now('America/La_Paz')->toDateString();
        $from = $data['from'] ?? $today;
        $to = $data['to'] ?? $from;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }
        foreach ([$from, $to] as $date) {
            if ($date !== $today && ! $availableDates->contains($date)) {
                throw ValidationException::withMessages(['from' => 'Selecciona un día con Kardex para estos filtros.']);
            }
        }
        $interventions = (clone $base)->with('asset.equipment', 'asset.project.client', 'activities')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->orderByDesc('id')->get();
        $summary = $interventions->groupBy(fn ($i) => $i->asset->project_id)->map(function ($items) {
            return ['project' => $items->first()->asset->project, 'equipment' => $items->pluck('asset.equipment_id')->unique()->count(),
                'total' => $items->count(), 'borrador' => $items->where('status', 'borrador')->count(),
                'revision' => $items->where('status', 'revision')->count(), 'observado' => $items->where('status', 'observado')->count(),
                'finalizado' => $items->where('status', 'finalizado')->count()];
        });
        $equipmentCount = $interventions->pluck('asset.equipment_id')->unique()->count();
        $clients = $projects->pluck('client')->unique('id')->sortBy('name');

        return view('dashboard', compact('projects', 'interventions', 'summary', 'equipmentCount', 'availableDates', 'technicians', 'clients', 'from', 'to', 'today', 'role'));
    }

    public function projects()
    {
        $projects = $this->projectQuery()->orderBy('name')->get();

        return view('projects', compact('projects'));
    }

    public function administration()
    {

        $this->admin();

        return view('administration');

    }

    public function client(Request $r)
    {

        $this->admin();

        Client::create($r->validate(['name' => 'required|string|max:200', 'contact' => 'nullable|string|max:255']));

        return back()->with('success', 'Cliente registrado.');

    }

    public function user(Request $r)
    {

        $this->admin();

        $data = $r->validate(['name' => 'required|string|max:200', 'email' => 'required|email|max:255|unique:users', 'phone' => 'nullable|string|max:50', 'role' => ['required', Rule::in(['admin', 'supervisor', 'tecnico', 'consulta'])], 'password' => 'required|string|min:10|max:100']);

        User::create([...$data, 'must_change_password' => true]);

        $this->log('Usuario creado', null, ['email' => $data['email']]);

        return back()->with('success', 'Usuario creado. Debe cambiar su contraseña al ingresar.');

    }

    public function updateUser(Request $r, User $user)
    {

        $this->admin();

        abort_if($user->id === auth()->id(), 422, 'No puedes modificar tu propio acceso desde este formulario.');

        $data = $r->validate(['role' => ['required', Rule::in(['admin', 'supervisor', 'tecnico', 'consulta'])], 'active' => 'required|boolean', 'password' => 'nullable|string|min:10|max:100']);

        if (empty($data['password'])) {

            unset($data['password']);

        } else {

            $data['must_change_password'] = true;

        }

        $user->update($data);

        $this->log('Acceso de usuario actualizado', null, ['user_id' => $user->id]);

        return back()->with('success', 'Usuario actualizado.');

    }

    public function createProject(Request $r)
    {

        $this->admin();

        $p = Project::create($r->validate(['client_id' => 'required|exists:clients,id', 'name' => 'required|string|max:200']) + ['description' => '']);

        return redirect()->route('projects.show', $p)->with('success', 'Proyecto creado. Configura sus plantillas y equipos.');

    }

    public function project(Project $project)
    {

        $this->access($project);

        $project->load(['client', 'users', 'templates', 'assets.equipment', 'assets.location', 'assets.interventions.activities']);

        return view('project', ['project' => $project, 'users' => auth()->user()->role === 'admin' ? User::where('active', true)->get() : collect(), 'equipment' => auth()->user()->role === 'admin' ? Equipment::all() : collect()]);

    }

    public function members(Request $r, Project $project)
    {

        $this->admin();

        $d = $r->validate(['users' => 'array', 'users.*' => 'integer|exists:users,id']);

        $project->users()->sync($d['users'] ?? []);

        $this->log('Asignación de proyecto actualizada', null, ['project_id' => $project->id]);

        return back()->with('success', 'Personal asignado.');

    }

    private function lines(?string $value): array
    {

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value ?? ''))));

    }

    public function editTemplate(Project $project, Template $template)
    {

        $this->admin();

        abort_unless($template->project_id === $project->id, 404);
        abort_if($template->fullyLocked(), 409, 'Este tipo de equipo ya tiene Kardex y no se puede editar.');

        return view('template-edit', compact('project', 'template'));

    }

    public function template(Request $r, Project $project, ?Template $template = null)
    {

        $this->admin();

        $d = $r->validate(['name' => 'required|string|max:200', 'fields' => 'nullable|string|max:10000', 'preventive' => 'nullable|string|max:10000', 'corrective' => 'nullable|string|max:10000']);

        $fields = [];

        foreach ($this->lines($d['fields'] ?? '') as $line) {

            $parts = array_pad(array_map('trim', explode('|', $line)), 5, '');

            [$name,$type,$unit,$required,$options] = $parts;

            abort_if($name === '' || in_array($name, array_column($fields, 'name')), 422, 'Los nombres de campos deben ser únicos y no vacíos.');

            abort_unless(in_array($type, ['texto', 'numero', 'fecha', 'lista']), 422, 'Tipo de campo inválido. Usa texto, numero, fecha o lista.');

            $fields[] = ['name' => $name, 'type' => $type, 'unit' => $unit, 'required' => $required === 'si', 'options' => array_values(array_filter(array_map('trim', explode(',', $options))))];

        }

        $data = ['project_id' => $project->id, 'name' => $d['name'], 'fields' => $fields, 'preventive' => $this->lines($d['preventive'] ?? ''), 'corrective' => $this->lines($d['corrective'] ?? '')];

        if ($template?->exists) {

            abort_unless($template->project_id === $project->id, 404);

            DB::transaction(function () use ($project, $template, $data) {
                Project::lockForUpdate()->findOrFail($project->id);
                $template->refresh()->updateUnlockedData($data);
            });

        } else {

            Template::create($data);

        }

        return redirect()->route('projects.show', $project)->with('success', 'Plantilla guardada. Los cambios se aplicarán a los próximos registros.');

    }

    public function copyTemplate(Request $r, Project $project)
    {

        $this->admin();

        $d = $r->validate(['template_id' => 'required|exists:templates,id']);

        $source = Template::findOrFail($d['template_id']);

        Template::create(['project_id' => $project->id, 'name' => $source->name, 'fields' => $source->fields, 'preventive' => $source->preventive, 'corrective' => $source->corrective]);

        return back()->with('success', 'Plantilla copiada al proyecto.');

    }

    public function assetForm(Project $project)
    {

        $this->access($project, true);

        return redirect()->route('projects.show', $project);

    }

    public function asset(Request $r, Project $project)
    {

        $this->access($project, true);

        abort_unless(auth()->user()->role === 'tecnico', 403);

        abort(410, 'Registra el equipo desde el formulario del proyecto.');

    }

    public function createIntervention(Request $r, ProjectEquipment $asset)
    {

        $this->access($asset->project, true);

        $d = $r->validate(['type' => ['required', Rule::in(['preventivo', 'correctivo'])], 'date' => 'required|date']);

        $i = DB::transaction(function () use ($asset, $d) {

            $a = ProjectEquipment::lockForUpdate()->findOrFail($asset->id);

            $i = Intervention::create(['project_equipment_id' => $a->id, 'created_by' => auth()->id(), 'number' => $a->next_intervention, ...$d]);

            $a->increment('next_intervention');

            $list = $d['type'] === 'preventivo' ? $a->template->preventive : $a->template->corrective;

            foreach ($list as $line) {

                $parts = explode('|', $line, 2);

                $i->activities()->create(['name' => trim($parts[0]), 'procedure' => trim($parts[1] ?? '')]);

            }

            $this->log('Intervención creada', $i);

            return $i;

        });

        return redirect()->route('interventions.show', $i);

    }

    public function intervention(Intervention $intervention)
    {

        $this->access($intervention->asset->project);

        return view('intervention-edit', ['i' => $intervention]);

    }

    public function save(Request $r, Intervention $intervention)
    {

        $this->draft($intervention);

        $d = $r->validate(['date' => 'required|date', 'location_text' => 'nullable|string|max:500', 'fault' => 'nullable|string|max:10000', 'diagnosis' => 'nullable|string|max:10000', 'observations' => 'nullable|string|max:10000', 'conclusions' => 'nullable|string|max:10000', 'recommendations' => 'nullable|string|max:10000', 'fiscal' => 'nullable|string|max:200', 'agent' => 'nullable|string|max:200', 'measurement_names' => 'nullable|array', 'measurement_names.*' => 'nullable|string|max:150', 'measurement_values' => 'nullable|array', 'measurement_values.*' => 'nullable|string|max:150', 'measurement_units' => 'nullable|array', 'measurement_units.*' => 'nullable|string|max:50', 'part_names' => 'nullable|array', 'part_names.*' => 'nullable|string|max:200', 'part_quantities' => 'nullable|array', 'part_quantities.*' => 'nullable|numeric|min:0.01', 'part_states' => 'nullable|array', 'part_states.*' => [Rule::in(['cambiado', 'pendiente', 'no_aplica'])]]);

        $measurements = [];

        foreach ($d['measurement_names'] ?? [] as $k => $name) {

            if (trim($name ?? '') !== '') {

                $measurements[] = ['name' => $name, 'value' => $d['measurement_values'][$k] ?? '', 'unit' => $d['measurement_units'][$k] ?? ''];

            }

        }

        $parts = [];

        foreach ($d['part_names'] ?? [] as $k => $name) {

            if (trim($name ?? '') !== '') {

                $parts[] = ['name' => $name, 'quantity' => $d['part_quantities'][$k] ?? 1, 'status' => $d['part_states'][$k] ?? 'pendiente'];

            }

        }

        DB::transaction(function () use ($intervention, $d, $measurements, $parts) {

            $i = Intervention::lockForUpdate()->findOrFail($intervention->id);

            $this->draft($i);

            $i->update([...collect($d)->only(['date', 'location_text', 'fault', 'diagnosis', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent'])->all(), 'measurements' => $measurements, 'parts' => $parts]);

            $this->log('Datos actualizados', $i);

        });

        return back()->with('success', 'Borrador guardado.');

    }

    public function activity(Request $r, Intervention $intervention)
    {

        $this->draft($intervention);

        $d = $r->validate(['name' => 'required|string|max:255', 'procedure' => 'nullable|string|max:5000']);

        DB::transaction(function () use ($intervention, $d) {

            $i = Intervention::lockForUpdate()->findOrFail($intervention->id);

            $this->draft($i);

            $i->activities()->create($d);

            $this->log('Actividad agregada', $i, $d);

        });

        return back()->with('success', 'Actividad agregada.');

    }

    public function updateActivity(Request $r, Activity $activity)
    {

        $this->draft($activity->intervention);

        $d = $r->validate(['result' => ['required', Rule::in(['pendiente', 'realizado', 'no_realizado', 'no_aplica'])], 'notes' => 'required_if:result,no_realizado|nullable|string|max:5000', 'performed_on' => 'nullable|date', 'procedure' => 'nullable|string|max:5000']);

        if ($d['result'] !== 'pendiente') {

            $r->validate(['performed_on' => 'required|date']);

        }

        DB::transaction(function () use ($activity, $d) {

            $i = Intervention::lockForUpdate()->findOrFail($activity->intervention_id);

            $this->draft($i);

            $activity->update([...$d, 'performed_by' => auth()->id()]);

            $this->log('Actividad actualizada', $i, ['activity_id' => $activity->id, ...$d]);

        });

        return back()->with('success', 'Avance guardado.');

    }

    public function photo(Request $r, Activity $activity)
    {

        $this->draft($activity->intervention);

        $d = $r->validate(['photos' => 'required|array|max:10', 'photos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192', 'description' => 'nullable|string|max:255', 'stage' => ['nullable', Rule::in(['antes', 'durante', 'despues'])]]);

        $paths = [];

        try {

            DB::transaction(function () use ($r, $activity, $d, &$paths) {

                $i = Intervention::lockForUpdate()->findOrFail($activity->intervention_id);

                $this->draft($i);

                if ($activity->photos()->count() + count($r->file('photos')) > 10) {
                    throw ValidationException::withMessages(['photos' => 'Puedes agregar hasta 10 fotos por actividad.']);
                }
                foreach ($r->file('photos') as $file) {

                    $path = $file->store('evidence', 'local');
                    if (! $path) {
                        throw new \RuntimeException('No se pudo guardar la fotografía.');
                    }

                    $paths[] = $path;

                    $activity->photos()->create(['path' => $path, 'uploaded_by' => auth()->id(), 'description' => $d['description'] ?? null, 'stage' => $d['stage'] ?? null]);

                }

                $this->log('Fotografías agregadas', $i, ['activity_id' => $activity->id, 'count' => count($paths)]);

            });

        } catch (\Throwable $e) {

            foreach ($paths as $path) {

                Storage::disk('local')->delete($path);

            } throw $e;
        }

        return back()->with('success', 'Fotografías guardadas.');

    }

    public function gallery(Intervention $intervention)
    {
        $this->access($intervention->asset->project);
        $intervention->load('activities.photos');

        return response()->json($intervention->activities->flatMap(fn ($activity) => $activity->photos->map(fn ($photo) => [
            'url' => route('photos.optimized', $photo), 'thumbnail' => route('photos.thumbnail', $photo), 'caption' => $activity->name,
        ]))->values())->header('Cache-Control', 'private, no-store');
    }

    public function optimizedPhoto(Photo $photo)
    {
        $this->access($photo->activity->intervention->asset->project);

        return response()->file(app(OptimizedPhoto::class)->path($photo), ['Cache-Control' => 'private, max-age=3600']);
    }

    public function thumbnail(Photo $photo)
    {
        $this->access($photo->activity->intervention->asset->project);

        return response()->file(app(PhotoThumbnail::class)->path($photo), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function showPhoto(Photo $photo)
    {

        $this->access($photo->activity->intervention->asset->project);
        abort_unless(Storage::disk('local')->exists($photo->path), 404, 'La fotografía ya no está disponible.');

        return response()->file(Storage::disk('local')->path($photo->path), ['Cache-Control' => 'private, no-store']);

    }

    public function transition(Request $r, Intervention $intervention)
    {

        $this->access($intervention->asset->project);

        $d = $r->validate(['status' => ['required', Rule::in(['borrador', 'revision', 'observado', 'finalizado'])], 'review_note' => 'nullable|string|max:5000']);

        DB::transaction(function () use ($intervention, $d) {

            $i = Intervention::lockForUpdate()->findOrFail($intervention->id);

            $role = auth()->user()->role;

            $target = $d['status'];

            $allowed = (in_array($i->status, ['borrador', 'observado']) && $target === 'revision' && $role === 'tecnico') || ($i->status === 'revision' && in_array($target, ['observado', 'finalizado']) && $role === 'supervisor') || ($i->status === 'finalizado' && $target === 'observado' && $role === 'supervisor');

            abort_unless($allowed, 403, 'Transición no permitida.');

            if ($target === 'revision' || $target === 'finalizado') {

                $message = $i->reviewValidationMessage();

                if ($message) {
                    throw ValidationException::withMessages(['review' => $message])->redirectTo(route('interventions.show', $i));
                }

            }

            if (in_array($target, ['borrador', 'observado'])) {

                if (blank($d['review_note'] ?? null)) {
                    throw ValidationException::withMessages(['review_note' => 'Indica el motivo de devolución o reapertura.'])->redirectTo(route('interventions.show', $i));
                }

            }

            $data = ['status' => $target, 'review_note' => $d['review_note'] ?? null];

            if ($i->status === 'finalizado' && $target === 'observado') {
                $data['snapshot'] = null;
                $data['finalized_at'] = null;
            }

            if ($target === 'finalizado') {

                $a = $i->asset;

                $data['snapshot'] = ['equipment' => $a->equipment->toArray(), 'technical' => $a->technical_data, 'location' => $i->location_text, 'project' => $a->project->name, 'client' => $a->project->client->name, 'record' => $i->only(['date', 'location_text', 'type', 'number', 'fault', 'diagnosis', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent', 'measurements', 'parts']), 'activities' => $i->activities()->with('photos', 'performer')->get()->map(fn ($activity) => [...$activity->toArray(), 'performer_name' => $activity->performer?->name])->all()];

                $data['finalized_at'] = now();

            }

            $this->log('Estado: '.$i->status.' → '.$target, $i, ['note' => $d['review_note'] ?? null, 'previous_snapshot' => $i->snapshot]);

            $i->update($data);

        });

        return back()->with('success', 'Estado actualizado.');

    }

    public function print(Intervention $intervention)
    {

        $this->access($intervention->asset->project);

        abort_if(auth()->user()->role === 'consulta' && $intervention->status !== 'finalizado', 403);

        return app(KardexPdf::class)->render($intervention)

            ->stream(sprintf('Kardex-%s-%s-%03d.pdf', $intervention->asset->kardex_code, $intervention->type, $intervention->number));

    }
}
