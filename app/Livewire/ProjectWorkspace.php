<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Intervention;
use App\Models\Photo;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProjectWorkspace extends Component
{
    use WithFileUploads;
    use \Livewire\WithPagination;

    public array $activityData = [];

    public array $photoFiles = [];

    public array $incomingPhotos = [];

    public array $templateActivities = ['preventive' => [], 'corrective' => []];

    #[Locked]
    public int $projectId;

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $editing = null;

    #[Locked]
    public ?int $interventionId = null;

    public string $section = 'equipment';

    public string $search = '';

    public array $statusFilters = ['borrador', 'observado', 'revision'];

    public string $message = '';

    public array $form = [];

    public array $fields = [];

    public array $technical = [];

    public array $members = [];

    public string $source = '';

    public function mount(Project $project, ?Intervention $intervention = null): void
    {
        $this->projectId = $project->id;
        $this->access();
        if ($intervention?->exists) {
            $this->openRecord($intervention->id);

            return;
        }
        if (auth()->user()->role === 'admin' && ! $project->templates()->exists()) {
            $this->section = 'types';
        }
    }

    public function hydrate(): void
    {
        $this->access();
    }

    private function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    public function deleteMaintenance(int $interventionId): void
    {
        $this->access();
        abort_unless(auth()->user()->role === 'supervisor', 403);
        $paths = [];
        DB::transaction(function () use ($interventionId, &$paths) {
            $record = Intervention::whereHas('asset', fn ($q) => $q->where('project_id', $this->projectId))->findOrFail($interventionId);
            $asset = $this->project()->assets()->lockForUpdate()->findOrFail($record->project_equipment_id);
            $record = $asset->interventions()->lockForUpdate()->findOrFail($interventionId);
            $activities = $record->activities()->pluck('id');
            $photos = Photo::whereIn('activity_id', $activities);
            $paths = (clone $photos)->pluck('path')->all();
            $photos->delete();
            $record->activities()->delete();
            AuditLog::where('intervention_id', $record->id)->update(['intervention_id' => null]);
            $this->audit('Mantenimiento eliminado', ['intervention_id' => $record->id, 'project_id' => $this->projectId, 'kardex' => $asset->number, 'number' => $record->number, 'type' => $record->type]);
            $record->delete();
            if (! $asset->interventions()->exists()) {
                $asset->delete();
            }
        });
        Storage::disk('local')->delete($paths);
        $this->done('Mantenimiento eliminado.', 'equipment');
    }

    public function deleteEmptyKardex(int $assetId): void
    {
        $this->access();
        abort_unless(auth()->user()->role === 'supervisor', 403);
        DB::transaction(function () use ($assetId) {
            $asset = $this->project()->assets()->lockForUpdate()->findOrFail($assetId);
            if ($asset->interventions()->exists()) {
                throw ValidationException::withMessages(['delete' => 'Elimina primero los mantenimientos de este Kardex.']);
            }
            $this->audit('Kardex vacío eliminado', ['project_id' => $this->projectId, 'kardex' => $asset->number]);
            $asset->delete();
        });
        $this->done('Kardex eliminado.', 'equipment');
    }

    private function access(bool $admin = false): void
    {
        $u = auth()->user();
        abort_unless($u && $u->active && ! $u->must_change_password, 403);
        abort_unless($u->role === 'admin' || (! $admin && $this->project()->users()->where('users.id', $u->id)->exists()), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: 'equipmentPage');
    }

    public function updatedStatusFilters(): void
    {
        $this->resetPage(pageName: 'equipmentPage');
    }

    public function selectSection(string $section): void
    {
        $this->access($section !== 'equipment');
        abort_unless(in_array($section, ['types', 'equipment', 'members']), 404);
        $this->section = $section;
        $this->search = '';
    }

    public function closeModal(): void
    {
        if ($this->modal === 'edit') {
            $this->redirectRoute('projects.show', ['project' => $this->projectId]);
        }
        $this->modal = '';
        $this->resetValidation();
    }

    public function openRecord(int $id): void
    {
        $this->access();
        $record = Intervention::whereHas('asset', fn ($query) => $query->where('project_id', $this->projectId))->findOrFail($id);
        $this->open(in_array($record->status, ['borrador', 'observado']) && auth()->user()->role === 'tecnico' ? 'edit' : 'view', $id);
    }

    private function viewedIntervention(): Intervention
    {
        $this->access();

        return Intervention::whereHas('asset', fn ($query) => $query->where('project_id', $this->projectId))
            ->with('asset.equipment', 'asset.project.client', 'activities.photos', 'activities.performer')->findOrFail($this->interventionId);
    }

    public function open(string $modal, ?int $id = null): void
    {
        $this->access(! in_array($modal, ['asset', 'intervention', 'edit', 'view']));
        abort_unless(in_array($modal, ['type', 'copy', 'asset', 'members', 'intervention', 'edit', 'view']), 404);
        if ($modal === 'asset') {
            abort_unless(auth()->user()->role === 'tecnico', 403);
        }
        if (in_array($modal, ['asset', 'intervention'])) {
            abort_unless(auth()->user()->role === 'tecnico', 403);
        }
        $this->resetValidation();
        $this->editing = $id;
        $this->interventionId = in_array($modal, ['edit', 'view']) ? $id : null;
        if ($modal === 'view') {
            $this->viewedIntervention();
        }
        $this->source = '';
        $this->fields = [];
        $this->technical = [];
        $this->activityData = [];
        $this->photoFiles = [];
        $this->incomingPhotos = [];
        $this->templateActivities = ['preventive' => [], 'corrective' => []];
        $this->form = ['name' => '', 'preventive' => '', 'corrective' => '', 'template_id' => '', 'equipment_id' => '', 'brand' => '', 'model' => '', 'serial' => '', 'code' => '', 'type' => 'preventivo', 'date' => now()->toDateString()];
        if ($modal === 'type' && $id) {
            $t = $this->project()->templates()->findOrFail($id);
            abort_if($t->fullyLocked(), 409, 'Este tipo de equipo ya tiene Kardex y no se puede editar.');
            $this->form = ['name' => $t->name, 'preventive' => implode("\n", $t->preventive), 'corrective' => implode("\n", $t->corrective)];
            $this->fields = array_map(fn ($f) => [...$f, 'options' => implode(', ', $f['options'])], array_values(array_filter($t->fields, fn ($f) => ! Template::identityColumn($f['name']))));
            foreach (['preventive', 'corrective'] as $kind) {
                $this->templateActivities[$kind] = array_map(function ($line) {
                    $parts = explode('|', $line, 2);

                    return ['key' => (string) Str::uuid(), 'name' => trim($parts[0]), 'legacy' => trim($parts[1] ?? '')];
                }, $t->$kind);
            }
        }
        if ($modal === 'asset') {
            abort_unless($this->project()->templates()->exists(), 422, 'Primero agrega un tipo de equipo.');
            if ($this->project()->templates()->count() === 1) {
                $this->form['template_id'] = $this->project()->templates()->first()->id;
            }
        }
        if ($modal === 'intervention') {
            $this->project()->assets()->findOrFail($id);
        }
        if ($modal === 'members') {
            $this->members = $this->project()->users()->pluck('users.id')->all();
        }
        if ($modal === 'edit') {
            $i = $this->editableIntervention();
            $this->form = $i->only('type', 'location_text', 'fault', 'diagnosis', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent');
            $this->form += ['date' => $i->date->toDateString(), 'template_id' => $i->asset->template_id];
            $template = $this->editorTemplate($i);
            foreach ($template->fields as $key => $field) {
                $value = $i->asset->technical_data[$field['name']]['value'] ?? null;
                if ($column = $this->identityField($field['name'])) {
                    foreach ($i->asset->technical_data ?? [] as $oldName => $oldValue) {
                        if (Template::identityColumn($oldName) === $column && filled($oldValue['value'] ?? null)) {
                            $value ??= $oldValue['value'];
                        }
                    }
                    $value ??= $i->asset->equipment->{$column};
                }
                $this->technical[$key] = $value;
            }
            $bucket = $template->id.'_'.$i->type;
            foreach ($i->activities as $key => $activity) {
                $this->activityData[$bucket][$key] = $activity->only('result', 'notes', 'procedure') + ['performed_on' => $activity->performed_on ?: now()->toDateString()];
            }
        }
        $this->modal = $modal;
        $this->prepareActivityDates();
    }

    private function editableIntervention(bool $lock = false): Intervention
    {
        $this->access();
        abort_unless(auth()->user()->role === 'tecnico', 403);
        $query = Intervention::whereHas('asset', fn ($query) => $query->where('project_id', $this->projectId));
        $i = $query->when($lock, fn ($query) => $query->lockForUpdate())->with('asset.template', 'activities.photos')->findOrFail($this->interventionId);
        abort_unless(in_array($i->status, ['borrador', 'observado']), 409, 'Solo se puede editar un borrador o un Kardex observado.');

        return $i;
    }

    private function editorTemplate(Intervention $i): Template
    {
        $template = clone $i->asset->template;
        $fields = $template->fields;
        foreach ($i->asset->technical_data ?? [] as $name => $value) {
            if (! Template::identityColumn($name) && ! in_array($name, array_column($fields, 'name'))) {
                $fields[] = ['name' => $name, 'type' => is_numeric($value['value'] ?? null) ? 'numero' : 'texto', 'unit' => $value['unit'] ?? '', 'required' => false, 'options' => []];
            }
        }
        $template->fields = $fields;
        $template->setAttribute($i->type === 'preventivo' ? 'preventive' : 'corrective', $i->activities->pluck('name')->all());

        return $template;
    }

    public function addField(): void
    {
        $this->access(true);
        $this->fields[] = ['name' => '', 'type' => 'texto', 'unit' => '', 'required' => false, 'options' => ''];
    }

    public function addTemplateActivity(string $kind): void
    {
        $this->access(true);
        abort_unless($this->modal === 'type' && in_array($kind, ['preventive', 'corrective']), 403);
        $this->templateActivities[$kind][] = ['key' => (string) Str::uuid(), 'name' => '', 'legacy' => ''];
    }

    public function removeTemplateActivity(string $kind, int $index): void
    {
        $this->access(true);
        abort_unless($this->modal === 'type' && in_array($kind, ['preventive', 'corrective']), 403);
        unset($this->templateActivities[$kind][$index]);
        $this->templateActivities[$kind] = array_values($this->templateActivities[$kind]);
    }

    public function removeField(int $index): void
    {
        $this->access(true);
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function updatedFormTemplateId(): void
    {
        $this->technical = [];
        $this->resetValidation();
        $this->prepareActivityDates();
    }

    public function updatedFormType(): void
    {
        $this->prepareActivityDates();
    }

    private function prepareActivityDates(): void
    {
        if ($this->modal !== 'asset' || empty($this->form['template_id']) || ! in_array($this->form['type'] ?? '', ['preventivo', 'correctivo'])) {
            return;
        }
        $template = $this->project()->templates()->findOrFail($this->form['template_id']);
        $bucket = $template->id.'_'.$this->form['type'];
        foreach (($this->form['type'] === 'preventivo' ? $template->preventive : $template->corrective) as $key => $line) {
            if (! array_key_exists('performed_on', $this->activityData[$bucket][$key] ?? [])) {
                $this->activityData[$bucket][$key]['performed_on'] = now()->toDateString();
            }
        }
    }

    public function updatedIncomingPhotos($value, string $key): void
    {
        $this->access();
        $this->authorizePhotoEditor();
        $segments = explode('.', $key);
        [$bucket, $index] = array_pad($segments, 2, null);
        abort_unless($bucket && ctype_digit((string) $index), 422);
        $editingIntervention = $this->modal === 'edit' ? $this->editableIntervention() : null;
        $template = $editingIntervention ? $this->editorTemplate($editingIntervention) : $this->project()->templates()->findOrFail($this->form['template_id']);
        $kind = $this->form['type'];
        abort_unless(in_array($kind, ['preventivo', 'correctivo']) && $bucket === $template->id.'_'.$kind, 422);
        $lines = $kind === 'preventivo' ? $template->preventive : $template->corrective;
        abort_unless(array_key_exists($index, $lines), 422);
        $path = "incomingPhotos.$bucket.$index";
        $this->resetValidation($path);
        $this->validate([$path => 'required|array|max:10', "$path.*" => 'image|mimes:jpg,jpeg,png,webp|max:8192']);
        $photos = array_merge($this->photoFiles[$bucket][$index] ?? [], $this->incomingPhotos[$bucket][$index]);
        $existingCount = $editingIntervention?->activities[$index]?->photos->count() ?? 0;
        if (count($photos) + $existingCount > 10) {
            throw ValidationException::withMessages([$path => 'Puedes agregar hasta 10 fotos por actividad. Las fotos anteriores se conservan.']);
        }
        $this->photoFiles[$bucket][$index] = $photos;
        $this->incomingPhotos[$bucket][$index] = [];
    }

    public function acceptUploadedPhotos(string $token): array
    {
        $this->access();
        $this->authorizePhotoEditor();
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'La carga no es válida. Selecciona las fotos nuevamente.'];
        }
        abort_unless(($payload['user_id'] ?? null) === auth()->id() && ($payload['project_id'] ?? null) === $this->projectId, 403);
        abort_unless(($payload['intervention_id'] ?? null) === $this->interventionId, 403);
        if (($payload['expires'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'message' => 'La carga venció. Selecciona las fotos nuevamente.'];
        }
        $this->cleanupOldUploads();
        $bucket = $payload['bucket'];
        $index = $payload['index'];
        $files = [];
        foreach ($payload['references'] as $reference) {
            $path = TemporaryUploadedFile::extractPathFromSignedPath($reference);
            abort_if($path === false, 403);
            $file = TemporaryUploadedFile::createFromLivewire($path);
            if (! $file->exists()) {
                return ['ok' => false, 'message' => 'No se encontró una de las fotos. Vuelve a seleccionarla.'];
            }
            if (! collect($this->photoFiles[$bucket][$index] ?? [])->contains(fn ($existing) => $existing->getFilename() === $file->getFilename())) {
                $files[] = $file;
            }
        }
        if (! $files) {
            return ['ok' => true];
        }
        $this->incomingPhotos[$bucket][$index] = $files;
        try {
            $this->updatedIncomingPhotos($files, $bucket.'.'.$index);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return ['ok' => false, 'message' => $e->validator->errors()->first()];
        } finally {
            $this->incomingPhotos[$bucket][$index] = [];
        }

        return ['ok' => true];
    }

    public function removePendingPhoto(string $bucket, int $activityIndex, string $filename): void
    {
        $this->access();
        $this->authorizePhotoEditor();
        $template = $this->modal === 'edit' ? $this->editorTemplate($this->editableIntervention()) : $this->project()->templates()->findOrFail($this->form['template_id']);
        $kind = $this->form['type'];
        abort_unless(in_array($kind, ['preventivo', 'correctivo']) && $bucket === $template->id.'_'.$kind, 422);
        $lines = $kind === 'preventivo' ? $template->preventive : $template->corrective;
        abort_unless(array_key_exists($activityIndex, $lines), 422);
        $this->photoFiles[$bucket][$activityIndex] = array_values(array_filter(
            $this->photoFiles[$bucket][$activityIndex] ?? [],
            fn ($file) => $file->getFilename() !== $filename,
        ));
        $this->resetValidation("incomingPhotos.$bucket.$activityIndex");
    }

    private function authorizePhotoEditor(): void
    {
        if ($this->modal === 'edit') {
            $this->editableIntervention();
        } else {
            abort_unless($this->modal === 'asset' && auth()->user()->role === 'tecnico', 403);
        }
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value))));
    }

    private function audit(string $action, array $details = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'details' => ['project_id' => $this->projectId, ...$details]]);
    }

    private function done(string $message, string $section): void
    {
        $this->message = $message;
        $this->section = $section;
        $this->closeModal();
    }

    public function saveType(): void
    {
        $this->access(true);
        abort_unless($this->modal === 'type', 403);
        $this->validate([
            'templateActivities' => 'required|array:preventive,corrective',
            'templateActivities.*' => 'array|max:100',
            'templateActivities.*.*.name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n|]/'],
            'templateActivities.*.*.legacy' => 'nullable|string|max:5000',
        ], ['templateActivities.*.*.name.required' => 'Escribe la descripción de cada actividad.']);
        $this->validate(['form.name' => 'required|string|max:200', 'form.preventive' => 'nullable|string|max:10000', 'form.corrective' => 'nullable|string|max:10000', 'fields' => 'array|max:50', 'fields.*.name' => 'required|string|max:100|distinct', 'fields.*.type' => ['required', Rule::in(['texto', 'numero', 'fecha', 'lista'])], 'fields.*.unit' => 'nullable|string|max:50', 'fields.*.required' => 'boolean', 'fields.*.options' => 'nullable|string|max:1000']);
        $fields = [];
        foreach ($this->fields as $key => $field) {
            if (Template::identityColumn($field['name'])) {
                $this->addError('fields.'.$key.'.name', 'Este campo ya existe como campo base.');

                return;
            }
            $options = array_values(array_filter(array_map('trim', explode(',', $field['options'] ?? ''))));
            if ($field['type'] === 'lista' && ! $options) {
                $this->addError('fields.'.$key.'.options', 'Escribe al menos una opción.');

                return;
            }
            $fields[] = [...$field, 'options' => $options];
        }
        $data = ['name' => $this->form['name'], 'fields' => $fields];
        foreach (['preventive', 'corrective'] as $kind) {
            $data[$kind] = array_map(fn ($row) => trim($row['name']).(filled($row['legacy'] ?? '') ? '|'.$row['legacy'] : ''), $this->templateActivities[$kind]);
        }
        DB::transaction(function () use ($data) {
            Project::lockForUpdate()->findOrFail($this->projectId);
            if ($this->editing) {
                $template = $this->project()->templates()->findOrFail($this->editing);
                $template->updateUnlockedData($data);
            } else {
                $this->project()->templates()->create($data);
            }
        });
        $this->audit('Tipo de equipo guardado');
        $this->done('Tipo de equipo guardado.', 'types');
    }

    public function copyType(): void
    {
        $this->access(true);
        abort_unless($this->modal === 'copy', 403);
        $this->validate(['source' => ['required', Rule::exists('templates', 'id')->where(fn ($q) => $q->where('project_id', '!=', $this->projectId))]]);
        $t = Template::findOrFail($this->source);
        $this->project()->templates()->create($t->only('name', 'fields', 'preventive', 'corrective'));
        $this->audit('Tipo de equipo copiado');
        $this->done('Tipo copiado al proyecto.', 'types');
    }

    public function saveMembers(): void
    {
        $this->access(true);
        abort_unless($this->modal === 'members', 403);
        $this->validate(['members' => 'array', 'members.*' => 'integer|exists:users,id']);
        $this->project()->users()->sync($this->members);
        $this->audit('Personal actualizado');
        $this->done('Personal actualizado.', 'members');
    }

    public function saveAsset(bool $sendToReview = false): void
    {
        $this->access();
        abort_unless(auth()->user()->role === 'tecnico', 403);
        abort_unless($this->modal === 'asset', 403);
        $this->validate(['form.template_id' => ['required', Rule::exists('templates', 'id')->where('project_id', $this->projectId)], 'form.equipment_id' => 'nullable|exists:equipment,id']);
        $template = $this->project()->templates()->findOrFail($this->form['template_id']);
        if (! empty($this->form['equipment_id'])) {
            $this->availableEquipment()->findOrFail($this->form['equipment_id']);
        }
        $rules = [];
        $attributes = [];
        foreach ($template->fields as $key => $field) {
            $attributes['technical.'.$key] = $field['name'];
            $rules['technical.'.$key] = [$sendToReview && $field['required'] ? 'required' : 'nullable', match ($field['type']) {
                'numero' => 'numeric','fecha' => 'date',default => 'string'
            }];
            if ($field['type'] === 'lista') {
                $rules['technical.'.$key][] = Rule::in($field['options']);
            }
            if ($identity = $this->identityField($field['name'])) {
                $rules['technical.'.$key][] = 'string';
                $rules['technical.'.$key][] = 'max:'.(in_array($identity, ['name', 'serial']) ? 200 : 100);
            }
        }
        if ($rules) {
            $this->validate($rules, ['required' => 'Completa el campo :attribute.', 'numeric' => 'El campo :attribute debe ser un número.', 'date' => 'El campo :attribute debe ser una fecha válida.', 'string' => 'El campo :attribute debe ser texto.', 'in' => 'Selecciona una opción válida para :attribute.'], $attributes);
        }
        $this->validate([
            'form.type' => ['required', Rule::in(['preventivo', 'correctivo'])],
            'form.date' => 'required|date',
            'form.location_text' => 'nullable|string|max:500',
            'form.fault' => 'nullable|string|max:10000', 'form.diagnosis' => 'nullable|string|max:10000',
            'form.observations' => 'nullable|string|max:10000', 'form.conclusions' => 'nullable|string|max:10000',
            'form.recommendations' => 'nullable|string|max:10000',
            'form.fiscal' => 'nullable|string|max:255', 'form.agent' => 'nullable|string|max:255',
        ]);
        $bucket = $template->id.'_'.$this->form['type'];
        $activityLines = $this->form['type'] === 'preventivo' ? $template->preventive : $template->corrective;
        foreach ($activityLines as $key => $line) {
            $this->validate([
                "activityData.$bucket.$key.result" => ['nullable', Rule::in(['pendiente', 'realizado', 'no_realizado', 'no_aplica'])],
                "activityData.$bucket.$key.notes" => [Rule::requiredIf(($this->activityData[$bucket][$key]['result'] ?? '') === 'no_realizado'), 'nullable', 'string', 'max:10000'],
                "activityData.$bucket.$key.procedure" => 'nullable|string|max:10000',
                "activityData.$bucket.$key.performed_on" => 'nullable|date',
                "photoFiles.$bucket.$key" => 'nullable|array|max:10',
                "photoFiles.$bucket.$key.*" => 'image|mimes:jpg,jpeg,png,webp|max:8192',
            ]);
        }
        $storedPhotos = [];
        try {
            DB::transaction(function () use ($template, $bucket, $activityLines, $sendToReview, &$storedPhotos) {
                $p = Project::lockForUpdate()->findOrFail($this->projectId);
                $identityData = ['name' => $template->name.' #'.$p->code.'-'.sprintf('%03d', $p->next_kardex)];
                foreach ($template->fields as $key => $field) {
                    $identity = $this->identityField($field['name']);
                    if ($identity && filled($this->technical[$key] ?? null)) {
                        $identityData[$identity] = $this->technical[$key];
                    }
                }
                $equipment = empty($this->form['equipment_id']) ? Equipment::create($identityData) : Equipment::findOrFail($this->form['equipment_id']);
                if ($p->assets()->where('equipment_id', $equipment->id)->exists()) {
                    throw ValidationException::withMessages(['form.equipment_id' => 'El equipo ya pertenece a este proyecto.']);
                }
                $data = [];
                foreach ($template->fields as $key => $field) {
                    $data[$field['name']] = ['value' => $this->technical[$key] ?? null, 'unit' => $field['unit']];
                }
                $asset = $p->assets()->create(['equipment_id' => $equipment->id, 'template_id' => $template->id, 'technical_data' => $data, 'number' => $p->next_kardex, 'next_intervention' => 2]);
                $values = collect($this->form)->only('date', 'type', 'location_text', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent')->all();
                if ($this->form['type'] === 'correctivo') {
                    $values += collect($this->form)->only('fault', 'diagnosis')->all();
                }
                $intervention = $asset->interventions()->create($values + ['created_by' => auth()->id(), 'number' => 1, 'status' => 'borrador']);
                foreach ($activityLines as $key => $line) {
                    $parts = explode('|', $line, 2);
                    $entry = $this->activityData[$bucket][$key] ?? [];
                    $activity = $intervention->activities()->create([
                        'name' => trim($parts[0]), 'procedure' => $entry['procedure'] ?? trim($parts[1] ?? ''),
                        'result' => $entry['result'] ?? 'pendiente', 'notes' => $entry['notes'] ?? null,
                        'performed_on' => filled($entry['performed_on'] ?? null) ? $entry['performed_on'] : null,
                        'performed_by' => ($entry['result'] ?? 'pendiente') !== 'pendiente' ? auth()->id() : null,
                    ]);
                    foreach ($this->photoFiles[$bucket][$key] ?? [] as $file) {
                        $path = $file->store('photos', 'local');
                        if (! $path) {
                            throw new \RuntimeException('No se pudo guardar la fotografía.');
                        }
                        $storedPhotos[] = $path;
                        $activity->photos()->create(['uploaded_by' => auth()->id(), 'path' => $path]);
                    }
                }
                if ($sendToReview) {
                    if ($message = $intervention->reviewValidationMessage()) {
                        throw ValidationException::withMessages(['review' => $message]);
                    }
                    $intervention->update(['status' => 'revision']);
                }
                AuditLog::create(['user_id' => auth()->id(), 'intervention_id' => $intervention->id, 'action' => $sendToReview ? 'Kardex registrado y enviado a revisión' : 'Kardex registrado como borrador']);
                $p->increment('next_kardex');
                $this->audit('Equipo asignado', ['equipment_id' => $equipment->id]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPhotos);
            throw $exception;
        }
        $this->photoFiles = [];
        $this->done($sendToReview ? 'Kardex guardado y enviado a revisión.' : 'Equipo y Kardex guardados como borrador.', 'equipment');
    }

    public function createIntervention()
    {
        $this->access();
        abort_if(auth()->user()->role !== 'tecnico' || $this->modal !== 'intervention', 403);
        $this->validate(['form.type' => ['required', Rule::in(['preventivo', 'correctivo'])], 'form.date' => 'required|date']);
        $i = DB::transaction(function () {
            $a = $this->project()->assets()->lockForUpdate()->findOrFail($this->editing);
            $i = $a->interventions()->create(['created_by' => auth()->id(), 'number' => $a->next_intervention, 'type' => $this->form['type'], 'date' => $this->form['date']]);
            $a->increment('next_intervention');
            foreach (($i->type === 'preventivo' ? $a->template->preventive : $a->template->corrective) as $line) {
                $parts = explode('|', $line, 2);
                $i->activities()->create(['name' => trim($parts[0]), 'procedure' => trim($parts[1] ?? '')]);
            }
            AuditLog::create(['user_id' => auth()->id(), 'intervention_id' => $i->id, 'action' => 'Intervención creada']);

            return $i;
        });

        return redirect()->route('interventions.show', $i);
    }

    public function saveEditedIntervention(bool $sendToReview = false): void
    {
        abort_unless($this->modal === 'edit', 403);
        $i = $this->editableIntervention();
        $template = $this->editorTemplate($i);
        $this->validate([
            'form.template_id' => ['required', Rule::in([$i->asset->template_id])],
            'form.type' => ['required', Rule::in([$i->type])],
            'form.date' => 'required|date', 'form.location_text' => 'nullable|string|max:500',
            'form.fault' => 'nullable|string|max:10000', 'form.diagnosis' => 'nullable|string|max:10000',
            'form.observations' => 'nullable|string|max:10000', 'form.conclusions' => 'nullable|string|max:10000',
            'form.recommendations' => 'nullable|string|max:10000', 'form.fiscal' => 'nullable|string|max:255', 'form.agent' => 'nullable|string|max:255',
        ]);
        $rules = [];
        $attributes = [];
        foreach ($template->fields as $key => $field) {
            $attributes['technical.'.$key] = $field['name'];
            $rules['technical.'.$key] = [$sendToReview && $field['required'] ? 'required' : 'nullable', match ($field['type']) {
                'numero' => 'numeric', 'fecha' => 'date', default => 'string',
            }];
            if ($field['type'] === 'lista') {
                $rules['technical.'.$key][] = Rule::in($field['options']);
            }
            if ($identity = $this->identityField($field['name'])) {
                $rules['technical.'.$key][] = 'max:'.(in_array($identity, ['name', 'serial']) ? 200 : 100);
            }
        }
        $bucket = $template->id.'_'.$i->type;
        foreach ($i->activities as $key => $activity) {
            $rules["activityData.$bucket.$key.result"] = ['required', Rule::in(['pendiente', 'realizado', 'no_realizado', 'no_aplica'])];
            $rules["activityData.$bucket.$key.notes"] = [Rule::requiredIf(($this->activityData[$bucket][$key]['result'] ?? '') === 'no_realizado'), 'nullable', 'string', 'max:10000'];
            $rules["activityData.$bucket.$key.performed_on"] = ['nullable', 'date'];
            $rules["photoFiles.$bucket.$key"] = 'nullable|array|max:'.max(0, 10 - $activity->photos->count());
            $rules["photoFiles.$bucket.$key.*"] = 'image|mimes:jpg,jpeg,png,webp|max:8192';
        }
        if ($rules) {
            $this->validate($rules, ['required' => 'Completa el campo :attribute.', 'numeric' => 'El campo :attribute debe ser un número.', 'date' => 'El campo :attribute debe ser una fecha válida.', 'string' => 'El campo :attribute debe ser texto.', 'in' => 'Selecciona una opción válida para :attribute.'], $attributes);
        }
        $storedPhotos = [];
        try {
            DB::transaction(function () use ($template, $bucket, $sendToReview, &$storedPhotos) {
                $i = $this->editableIntervention(true);
                $asset = $this->project()->assets()->lockForUpdate()->findOrFail($i->project_equipment_id);
                $technical = $asset->technical_data ?? [];
                $identity = [];
                foreach ($template->fields as $key => $field) {
                    if ($baseColumn = Template::identityColumn($field['name'])) {
                        foreach (array_keys($technical) as $oldName) {
                            if ($oldName !== $field['name'] && Template::identityColumn($oldName) === $baseColumn) {
                                unset($technical[$oldName]);
                            }
                        }
                    }
                    $technical[$field['name']] = ['value' => $this->technical[$key] ?? null, 'unit' => $field['unit'] ?? ''];
                    if ($column = $this->identityField($field['name'])) {
                        $value = $this->technical[$key] ?? null;
                        if ($column !== 'name' || filled($value)) {
                            $identity[$column] = $value;
                        }
                    }
                }
                $asset->update(['technical_data' => $technical]);
                if ($identity) {
                    $asset->equipment->update($identity);
                }
                $i->update(collect($this->form)->only('date', 'location_text', 'fault', 'diagnosis', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent')->all());
                foreach ($i->activities as $key => $activity) {
                    if ($activity->photos()->count() + count($this->photoFiles[$bucket][$key] ?? []) > 10) {
                        throw ValidationException::withMessages(["photoFiles.$bucket.$key" => 'Puedes agregar hasta 10 fotos por actividad.']);
                    }
                    $entry = $this->activityData[$bucket][$key];
                    $values = collect($entry)->only('result', 'notes', 'performed_on')->all();
                    if (collect($values)->contains(fn ($value, $column) => $activity->getAttribute($column) != $value)) {
                        $values['performed_by'] = auth()->id();
                    }
                    $activity->update($values);
                    foreach ($this->photoFiles[$bucket][$key] ?? [] as $file) {
                        $path = $file->store('photos', 'local');
                        if (! $path) {
                            throw new \RuntimeException('No se pudo guardar la fotografía.');
                        }
                        $storedPhotos[] = $path;
                        $activity->photos()->create(['uploaded_by' => auth()->id(), 'path' => $path]);
                    }
                }
                if (! $sendToReview) {
                    AuditLog::create(['user_id' => auth()->id(), 'intervention_id' => $i->id, 'action' => $i->status === 'observado' ? 'Correcciones guardadas' : 'Borrador guardado']);
                }
                if ($sendToReview) {
                    $i->unsetRelation('asset');
                    if ($message = $i->reviewValidationMessage()) {
                        throw ValidationException::withMessages(['review' => $message]);
                    }
                    $previousStatus = $i->status;
                    $i->update(['status' => 'revision', 'review_note' => null]);
                    AuditLog::create(['user_id' => auth()->id(), 'intervention_id' => $i->id, 'action' => 'Estado: '.$previousStatus.' → revision']);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPhotos);
            throw $exception;
        }
        if ($sendToReview) {
            $this->modal = '';
            $this->redirectRoute('interventions.show', ['intervention' => $this->interventionId]);

            return;
        }
        $this->open('edit', $this->interventionId);
        $this->message = 'Cambios guardados.';
    }

    public function readyForReview(): bool
    {
        if (! in_array($this->modal, ['asset', 'edit']) || blank($this->form['conclusions'] ?? null)) {
            return false;
        }
        $template = $this->modal === 'edit' ? $this->editorTemplate($this->editableIntervention()) : $this->project()->templates()->find($this->form['template_id'] ?? null);
        if (! $template) {
            return false;
        }
        if (blank($this->form['date'] ?? null)) {
            return false;
        }
        foreach ($template->fields as $key => $field) {
            if ($field['required'] && blank($this->technical[$key] ?? null)) {
                return false;
            }
        }
        $type = $this->form['type'] ?? 'preventivo';
        $lines = $type === 'preventivo' ? $template->preventive : $template->corrective;
        if (! $lines) {
            return false;
        }
        foreach ($lines as $key => $line) {
            $entry = $this->activityData[$template->id.'_'.$type][$key] ?? [];
            if (! in_array($entry['result'] ?? '', ['realizado', 'no_realizado', 'no_aplica']) || (($entry['result'] ?? '') === 'no_realizado' && blank($entry['notes'] ?? null))) {
                return false;
            }
        }

        return true;
    }

    public function sendCurrentMaintenance(): void
    {
        $this->access();
        abort_unless(auth()->user()->role === 'tecnico', 403);
        if (! $this->readyForReview()) {
            throw ValidationException::withMessages(['review' => 'Completa el Kardex antes de enviarlo a revisión.']);
        }
        $this->saveCurrentMaintenance(true);
    }

    public function saveCurrentMaintenance(bool $sendToReview = false): void
    {
        if ($this->modal === 'edit') {
            $this->saveEditedIntervention($sendToReview);
        } else {
            $this->saveAsset($sendToReview);
        }
    }

    public function render()
    {
        $this->access($this->section !== 'equipment');
        $project = $this->project()->load('client', 'templates', 'users');
        abort_unless(count($this->statusFilters) <= 4 && ! array_diff($this->statusFilters, ['borrador', 'observado', 'revision', 'finalizado']), 422);
        $states = array_values(array_unique($this->statusFilters));
        $assets = $project->assets()->with('equipment', 'project')->orderByDesc('number')
            ->with(['interventions' => fn ($q) => $q->when(count($states) !== 4, fn ($q) => $q->whereIn('status', $states))
                ->with(['activities' => fn ($q) => $q->withCount('photos')->with(['photos' => fn ($q) => $q->orderBy('id')->limit(1)])])])
            ->when(count($states) !== 4, fn ($q) => $q->whereHas('interventions', fn ($q) => $q->whereIn('status', $states)))
            ->when($this->search !== '', fn ($q) => $q->whereHas('equipment', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('serial', 'like', '%'.$this->search.'%')))
            ->paginate(15, pageName: 'equipmentPage');
        $isAdmin = auth()->user()->role === 'admin';

        $selectedType = $project->templates->firstWhere('id', $this->form['template_id'] ?? null);
        $editingIntervention = $this->modal === 'edit' ? $this->editableIntervention() : null;
        if ($editingIntervention) {
            $selectedType = $this->editorTemplate($editingIntervention);
        }

        return view('livewire.project-workspace', ['project' => $project, 'assets' => $assets, 'isAdmin' => $isAdmin, 'canRegister' => auth()->user()->role === 'tecnico', 'equipment' => auth()->user()->role === 'tecnico' ? $this->availableEquipment()->get() : collect(), 'users' => $isAdmin ? User::orderBy('name')->get() : collect(), 'otherTypes' => $isAdmin ? Template::where('project_id', '!=', $this->projectId)->get() : collect(), 'designerType' => $this->modal === 'type' && $this->editing ? $project->templates->find($this->editing) : null, 'selectedType' => $selectedType, 'editingIntervention' => $editingIntervention, 'viewedIntervention' => $this->modal === 'view' ? $this->viewedIntervention() : null]);
    }

    private function identityField(string $name): ?string
    {
        return Template::identityColumn($name);
    }

    private function availableEquipment()
    {
        return Equipment::query()->when(auth()->user()->role !== 'admin', fn ($query) => $query->whereIn('id', DB::table('project_equipment')->select('equipment_id')->whereIn('project_id', DB::table('project_user')->select('project_id')->where('user_id', auth()->id()))));
    }
}
