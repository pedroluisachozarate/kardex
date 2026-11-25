<?php

namespace App\Livewire;

use App\Models\MobileDraft;
use App\Services\MobileGateway;
use App\Services\SyncMobileDraft;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class MobileWorkspace extends Component
{
    use WithFileUploads;

    public string $email = '';

    public string $password = '';

    #[Locked]
    public array $catalog = [];

    #[Locked]
    public ?int $owner = null;

    #[Locked]
    public ?string $editing = null;

    public array $form = [];

    public array $uploads = [];

    public string $message = '';

    public string $page = 'summary';

    #[Locked]
    public ?int $projectId = null;

    public string $search = '';

    public array $states = ['borrador', 'observado', 'revision'];

    public string $from = '';

    public string $to = '';

    public string $summaryFrom = '';

    public string $summaryTo = '';

    public string $summaryProject = '';

    #[Locked]
    public string $appliedSummaryProject = '';

    #[Locked]
    public string $summarySection = 'overview';

    #[Locked]
    public ?array $overviewFilters = null;

    #[Locked]
    public array $summaryHistory = [];

    public string $summaryState = '';

    #[Locked]
    public int $summaryResultPage = 1;

    public function showSummary(string $section = 'overview', string $state = '', ?int $project = null): void
    {
        abort_unless(in_array($section, ['overview', 'projects', 'maintenance'], true), 404);
        abort_unless(in_array($state, ['', 'borrador', 'revision', 'observado', 'finalizado'], true), 404);
        if ($section !== 'overview' && $section !== $this->summarySection) {
            $this->summaryHistory[] = ['section' => $this->summarySection, 'state' => $this->summaryState, 'from' => $this->from, 'to' => $this->to, 'project' => $this->appliedSummaryProject, 'resultPage' => $this->summaryResultPage, 'overview' => $this->overviewFilters];
        } elseif ($section === 'overview') {
            $this->summaryHistory = [];
        }
        if ($this->summarySection === 'overview' && $section !== 'overview') {
            $this->overviewFilters = ['from' => $this->from, 'to' => $this->to, 'project' => $this->appliedSummaryProject];
        } elseif ($section === 'overview' && $this->overviewFilters !== null) {
            $this->from = $this->overviewFilters['from'];
            $this->to = $this->overviewFilters['to'];
            $this->summaryProject = $this->appliedSummaryProject = $this->overviewFilters['project'];
            $this->overviewFilters = null;
        }
        if ($project !== null) {
            abort_unless(collect($this->catalog)->contains('id', $project), 404);
            $this->summaryProject = $this->appliedSummaryProject = (string) $project;
        }
        $this->page = 'summary';
        $this->projectId = null;
        $this->summarySection = $section;
        $this->summaryState = $state;
        $this->summaryResultPage = 1;
        $this->summaryFrom = $this->from;
        $this->summaryTo = $this->to;
    }

    public function backSummary(): void
    {
        $previous = array_pop($this->summaryHistory);
        if (!$previous) {
            $this->showSummary();
            return;
        }
        $this->summarySection = $previous['section'];
        $this->summaryState = $previous['state'];
        $this->from = $this->summaryFrom = $previous['from'];
        $this->to = $this->summaryTo = $previous['to'];
        $this->appliedSummaryProject = $this->summaryProject = $previous['project'];
        $this->summaryResultPage = $previous['resultPage'];
        $this->overviewFilters = $previous['overview'];
    }

    public function updatedSummaryState(): void
    {
        $this->validate(['summaryState' => ['nullable', Rule::in(['borrador', 'revision', 'observado', 'finalizado'])]]);
        $this->summaryResultPage = 1;
    }

    public function changeSummaryPage(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 404);
        $this->summaryResultPage = max(1, $this->summaryResultPage + $direction);
    }

    public function applySummaryFilters(): void
    {
        $this->validate([
            'summaryFrom' => 'required|date_format:Y-m-d',
            'summaryTo' => 'required|date_format:Y-m-d|after_or_equal:summaryFrom',
            'summaryProject' => ['nullable', Rule::in(collect($this->catalog)->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ]);
        $this->from = $this->summaryFrom;
        $this->to = $this->summaryTo;
        $this->appliedSummaryProject = $this->summaryProject;
        $this->summaryResultPage = 1;
    }

    public function summaryToday(): void
    {
        $this->overviewFilters = null;
        $this->summaryHistory = [];
        $this->summarySection = 'overview';
        $this->summaryState = '';
        $this->summaryFrom = $this->summaryTo = now('America/La_Paz')->toDateString();
        $this->summaryProject = '';
        $this->applySummaryFilters();
    }

    public ?bool $serverAvailable = null;

    public function checkConnection(): void
    {
        try {
            $data = app(MobileGateway::class)->request()->connectTimeout(2)->timeout(3)->get('health')->throw()->json();
            $this->serverAvailable = ($data['service'] ?? '') === 'kardex';
        } catch (\Throwable $e) {
            $this->serverAvailable = false;
        }
    }

    public function openSystem(string $path = '/'): void
    {
        abort_unless(preg_match('#^/(?:proyectos(?:/[0-9]+)?|intervenciones/[0-9]+(?:/imprimir)?|administracion|password)?$#', $path), 404);
        $this->checkConnection();
        if (! $this->serverAvailable) {
            $this->dispatch('mobile-toast', message: 'No se pudo conectar con tu PC. Puedes seguir trabajando aquí.');
            return;
        }
        $this->redirect(route('mobile.start', ['path' => $path]));
    }

    public function updatedFrom(): void
    {
        $this->validate(['from' => 'date_format:Y-m-d']);
        if ($this->from > $this->to) {
            $this->to = $this->from;
        }
    }

    public function updatedTo(): void
    {
        $this->validate(['to' => 'date_format:Y-m-d']);
        if ($this->to < $this->from) {
            $this->from = $this->to;
        }
    }

    public function navigate(string $page): void
    {
        abort_unless(in_array($page, ['summary', 'projects', 'drafts']), 404);
        $this->page = $page;
        if ($page === 'summary') {
            $this->showSummary();
        }
        $this->projectId = null;
        $this->search = '';
    }

    public function showProject(int $id): void
    {
        abort_unless(collect($this->catalog)->contains('id', $id), 404);
        $this->projectId = $id;
        $this->page = 'project';
        $this->search = '';
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->uploads = [];
    }

    public function mount(): void
    {
        abort_unless(config('mobile.enabled'), 404);
        $this->owner = session('mobile.owner');
        $this->catalog = session('mobile.catalog', []);
        $this->summaryFrom = $this->summaryTo = $this->from = $this->to = now('America/La_Paz')->toDateString();
        $this->message = session('mobile_ready') ? 'Proyectos descargados. Puedes guardar borradores sin conexión.' : (string) session('mobile_error', '');
    }

    public function hydrate(): void
    {
        abort_unless(config('mobile.enabled'), 404);
        abort_unless($this->owner === session('mobile.owner'), 403);
    }

    public function login(): void
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string'], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);
        try {
            $gateway = app(MobileGateway::class);
            $auth = $gateway->request()->post('login', ['email' => $this->email, 'password' => $this->password])->throw()->json();
            $catalog = $gateway->request($auth['token'])->get('catalog')->throw()->json();
            session()->regenerate();
            session(['mobile.owner' => $auth['user']['id'], 'mobile.token' => Crypt::encryptString($auth['token']), 'mobile.catalog' => $catalog['projects']]);
            $this->owner = $auth['user']['id'];
            $this->catalog = $catalog['projects'];
            $this->editing = null;
            $this->message = 'Proyectos descargados. Puedes trabajar sin conexión.';
        } catch (\Throwable $e) {
            $this->addError('connection', 'No se pudo iniciar sesión. Revisa la conexión, tus credenciales y la dirección del servidor.');
        } finally {
            $this->password = '';
        }
    }

    public function restoreRecovery(array $recovery): void
    {
        abort_unless($this->owner && $this->owner === session('mobile.owner'), 403);
        abort_unless(strlen(json_encode($recovery)) <= 262144, 422);
        $form = $recovery['values']['form'] ?? null;
        abort_unless(is_array($form), 422);
        if (!empty($form['project_id'])) {
            $project = collect($this->catalog)->firstWhere('id', $form['project_id']);
            abort_unless($project, 422);
            if (!empty($form['template_id'])) {
                abort_unless(collect($project['templates'] ?? [])->contains('id', $form['template_id']), 422);
            }
            if (!empty($form['asset_id'])) {
                abort_unless(collect($project['equipment'] ?? [])->contains('id', $form['asset_id']), 422);
            }
        }
        $id = $recovery['id'] ?? null;
        if ($id && MobileDraft::where('owner_id', $this->owner)->whereKey($id)->exists()) {
            $this->edit($id);
        } else {
            $this->newDraft();
        }
        $this->form = array_replace($this->form, $form);
        $this->dispatch('kardex-notice', message: 'Texto recuperado. Revisa los datos y guarda el borrador.');
    }

    public function refreshWorkspace(): void
    {
        if ($this->editing) {
            $this->dispatch('kardex-notice', message: 'Guarda o cierra el formulario antes de actualizar.');
            return;
        }
        $this->checkConnection();
        if (!$this->serverAvailable) {
            $this->dispatch('kardex-notice', message: 'Sin conexión. Conservamos tus datos locales.');
            return;
        }
        if ($this->owner) {
            $this->resetErrorBag('connection');
            $this->syncAll();
            $this->refreshCatalog();
            $this->dispatch('kardex-notice', message: $this->getErrorBag()->has('connection') ? 'Actualización incompleta. Revisa los pendientes de sincronización.' : 'Datos actualizados.');
        }
    }

    public function refreshCatalog(): void
    {
        if ($this->editing) {
            return;
        }
        try {
            $data = app(MobileGateway::class)->request(Crypt::decryptString(session('mobile.token')))->get('catalog')->throw()->json();
            abort_unless(is_array($data['projects'] ?? null), 422);
            $this->serverAvailable = true;
            $this->catalog = $data['projects'];
            session(['mobile.downloaded_at' => now('America/La_Paz')->format('d/m/Y H:i')]);
            session(['mobile.catalog' => $this->catalog]);
            $this->message = 'Proyectos actualizados.';
        } catch (\Throwable $e) {
            $this->addError('connection', 'No se pudo actualizar. Conservamos los proyectos descargados.');
        }
    }

    public function logout(): void
    {
        session()->forget(['mobile.owner', 'mobile.user', 'mobile.token', 'mobile.catalog', 'mobile.downloaded_at']);
        $this->owner = null;
        $this->catalog = [];
        $this->editing = null;
    }

    public function newDraft(?int $projectId = null, ?int $assetId = null): void
    {
        abort_unless($this->owner, 403);
        $this->editing = (string) Str::uuid();
        $this->form = ['project_id' => '', 'template_id' => '', 'type' => 'preventivo', 'date' => now('America/La_Paz')->toDateString(), 'technical' => [], 'activities' => []];
        $this->uploads = [];
        if ($projectId) {
            $project = collect($this->catalog)->firstWhere('id', $projectId);
            abort_unless($project, 404);
            $this->form['project_id'] = $projectId;
            if ($assetId) {
                abort_unless(collect($project['equipment'] ?? [])->contains('id', $assetId), 404);
                $this->form['asset_id'] = $assetId;
                $this->updatedFormAssetId();
            } elseif (count($project['templates']) === 1) {
                $this->form['template_id'] = $project['templates'][0]['id'];
                $this->resetActivities();
            }
        }
    }

    private function template(): ?array
    {
        foreach ($this->catalog as $project) {
            if ($project['id'] == ($this->form['project_id'] ?? null)) {
                foreach ($project['templates'] as $template) {
                    if ($template['id'] == ($this->form['template_id'] ?? null)) {
                        return $template;
                    }
                }
            }
        }

        return null;
    }

    public function updatedFormProjectId(): void
    {
        $this->form['template_id'] = '';
        $this->form['asset_id'] = '';
        $this->form['technical'] = [];
        $this->form['activities'] = [];
        $this->uploads = [];
    }

    public function updatedFormTemplateId(): void
    {
        $this->form['asset_id'] = '';
        $this->resetActivities();
    }

    public function updatedFormAssetId(): void
    {
        if (empty($this->form['asset_id'])) {
            return;
        }
        foreach ($this->catalog as $project) {
            if ($project['id'] == ($this->form['project_id'] ?? null)) {
                foreach ($project['equipment'] ?? [] as $asset) {
                    if ($asset['id'] == $this->form['asset_id']) {
                        $this->form['template_id'] = $asset['template_id'];
                        $this->resetActivities();

                        return;
                    }
                }
            }
        }
    }

    public function updatedFormType(): void
    {
        $this->resetActivities();
    }

    private function resetActivities(): void
    {
        $this->form['activities'] = [];
        $this->form['technical'] = [];
        $this->uploads = [];
        $template = $this->template();
        if (! $template) {
            return;
        }
        if (! empty($this->form['asset_id'])) {
            $project = collect($this->catalog)->firstWhere('id', $this->form['project_id']);
            $asset = collect($project['equipment'] ?? [])->firstWhere('id', $this->form['asset_id']);
            foreach ($template['fields'] as $index => $field) {
                $this->form['technical'][$index] = (string) ($asset['technical'][$field['name']]['value'] ?? '');
            }
        }
        foreach ($template[($this->form['type'] ?? 'preventivo') === 'preventivo' ? 'preventive' : 'corrective'] as $line) {
            $this->form['activities'][] = ['result' => 'pendiente', 'notes' => '', 'performed_on' => now('America/La_Paz')->toDateString()];
        }
    }

    public function edit(string $id): void
    {
        $draft = MobileDraft::where('owner_id', $this->owner)->findOrFail($id);
        abort_unless($draft->sync_status === 'local' && ! $draft->receipt, 409);
        $this->editing = $id;
        $this->form = $draft->payload;
        $this->uploads = [];
    }

    public function save(): void
    {
        abort_unless($this->owner && $this->editing, 403);
        $template = $this->template();
        abort_unless($template, 422);
        $this->validate(['form.date' => 'required|date_format:Y-m-d', 'form.type' => ['required', Rule::in(['preventivo', 'correctivo'])], 'form.technical' => 'array', 'form.technical.*' => 'nullable|string|max:1000', 'form.activities' => 'array|max:100', 'form.activities.*.result' => ['required', Rule::in(['pendiente', 'realizado', 'no_realizado', 'no_aplica'])], 'form.activities.*.notes' => 'nullable|string|max:10000', 'form.activities.*.performed_on' => 'nullable|date_format:Y-m-d', 'form.location_text' => 'nullable|string|max:500', 'form.observations' => 'nullable|string|max:10000', 'form.conclusions' => 'nullable|string|max:10000', 'form.recommendations' => 'nullable|string|max:10000', 'uploads' => 'array', 'uploads.*' => 'array|max:10', 'uploads.*.*' => 'image|mimes:jpg,jpeg,png,webp|max:8192']);
        $draft = MobileDraft::where('owner_id', $this->owner)->find($this->editing);
        abort_if($draft && ($draft->receipt || $draft->sync_status !== 'local'), 409);
        $photos = $draft?->photos ?? [];
        $paths = [];
        try {
            foreach ($this->uploads as $activity => $files) {
                abort_unless(isset($this->form['activities'][$activity]), 422);
                abort_if(count(array_filter($photos, fn ($photo) => $photo['activity_index'] == $activity)) + count($files) > 10, 422);
                foreach ($files as $file) {
                    $path = $file->store('mobile-local/'.$this->owner, 'local');
                    if (! $path) {
                        throw new \RuntimeException('No se pudo guardar la foto local.');
                    }$paths[] = $path;
                    $photos[] = ['uuid' => (string) Str::uuid(), 'activity_index' => (int) $activity, 'path' => $path, 'name' => $file->getClientOriginalName()];
                }
            }
            MobileDraft::updateOrCreate(['id' => $this->editing], ['owner_id' => $this->owner, 'payload' => array_replace($this->form, ['template_version' => $template['version']]), 'photos' => $photos, 'sync_status' => 'local']);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }
        $this->uploads = [];
        $this->message = 'Borrador guardado en este dispositivo.';
        $this->dispatch('kardex-saved');
        $this->dispatch('kardex-notice', message: $this->message, duration: 1500);
    }

    public function sync(string $id): void
    {
        $draft = MobileDraft::where('owner_id', $this->owner)->findOrFail($id);
        try {
            app(SyncMobileDraft::class)->run($draft, Crypt::decryptString(session('mobile.token')));
            session(['mobile.synced_at' => now('America/La_Paz')->format('d/m/Y H:i')]);
            $this->message = 'Borrador y fotos sincronizados. El envío a revisión se realiza por separado.';
            $this->dispatch('kardex-notice', message: $this->message, duration: 1500);
        } catch (\Throwable $e) {
            $this->addError('connection', $draft->fresh()->sync_error ?? 'No se pudo sincronizar. Conservamos tus datos.');
        }
    }

    public function syncAll(): void
    {
        if (! $this->owner || $this->editing) {
            return;
        }
        foreach (MobileDraft::where('owner_id', $this->owner)->where('sync_status', '!=', 'synced')->get() as $draft) {
            $this->sync($draft->id);
            if ($this->getErrorBag()->isNotEmpty()) {
                break;
            }
        }
    }

    public function render()
    {
        $drafts = $this->owner ? MobileDraft::where('owner_id', $this->owner)->latest()->get() : collect();
        $records = collect();
        foreach ($this->catalog as $project) {
            foreach ($project['equipment'] ?? [] as $asset) {
                foreach ($asset['interventions'] ?? [] as $item) {
                    $records->push(array_merge($item, ['project_id' => $project['id'], 'project' => $project['name'], 'client' => $project['client'] ?? '', 'asset_id' => $asset['id'], 'kardex' => $asset['kardex'], 'equipment' => $asset['identity']['name']]));
                }
            }
        }
        foreach ($drafts as $draft) {
            if ($draft->sync_status === 'synced' && $records->contains('id', $draft->receipt['intervention_id'] ?? null)) {
                continue;
            }
            $project = collect($this->catalog)->firstWhere('id', $draft->payload['project_id'] ?? null);
            $records->push(['id' => null, 'local_draft_id' => $draft->sync_status === 'local' && ! $draft->receipt ? $draft->id : null, 'project_id' => $project['id'] ?? null, 'project' => $project['name'] ?? 'Proyecto', 'client' => $project['client'] ?? '', 'asset_id' => $draft->payload['asset_id'] ?? 'local-'.$draft->id, 'kardex' => $draft->receipt['kardex'] ?? 'Código al sincronizar', 'equipment' => 'Borrador del celular', 'number' => $draft->receipt['number'] ?? null, 'date' => $draft->payload['date'] ?? '', 'type' => $draft->payload['type'] ?? 'preventivo', 'status' => 'borrador', 'created_by' => $this->owner, 'progress' => 0]);
        }
        $ownRecords = $records->where('created_by', $this->owner);
        $availableDates = $ownRecords->filter(fn ($r) => $this->summaryProject === '' || (string) $r['project_id'] === $this->summaryProject)->pluck('date')->filter()->unique()->sort()->values()->all();
        $period = $ownRecords->filter(fn ($r) => $r['date'] >= $this->from && $r['date'] <= $this->to && ($this->appliedSummaryProject === '' || (string) $r['project_id'] === $this->appliedSummaryProject));
        $summaryGroups = $period->groupBy('project_id');
        $summaryMaintenance = $period->filter(fn ($r) => $this->summaryState === '' || $r['status'] === $this->summaryState)
            ->sortByDesc(fn ($r) => $r['date'].'-'.sprintf('%010d', $r['number'] ?? 0))->values();
        $resultCount = $this->summarySection === 'projects' ? $summaryGroups->count() : $summaryMaintenance->count();
        $summaryPages = max(1, (int) ceil($resultCount / 10));
        $currentSummaryPage = min($this->summaryResultPage, $summaryPages);
        $summaryProjects = $summaryGroups->slice(($currentSummaryPage - 1) * 10, 10);
        $summaryRows = $summaryMaintenance->slice(($currentSummaryPage - 1) * 10, 10);
        $project = collect($this->catalog)->firstWhere('id', $this->projectId);
        $assets = collect($project['equipment'] ?? [])->filter(function ($asset) {
            $text = mb_strtolower(implode(' ', array_values($asset['identity'])).' '.$asset['kardex']);
            if ($this->search && ! str_contains($text, mb_strtolower(mb_substr($this->search, 0, 200)))) {
                return false;
            }

            return collect($asset['interventions'] ?? [])->contains(fn ($i) => in_array($i['status'], $this->states));
        })->sortByDesc(fn ($asset) => $asset['number'] ?? $asset['id']);

        return view('livewire.mobile-workspace', ['editingPhotos' => $this->editing ? (MobileDraft::where('owner_id', $this->owner)->find($this->editing)?->photos ?? []) : [], 'drafts' => $drafts, 'template' => $this->template(), 'project' => $project, 'assets' => $assets, 'records' => $records, 'period' => $period, 'availableDates' => $availableDates, 'summaryProjects' => $summaryProjects, 'summaryRows' => $summaryRows, 'summaryPages' => $summaryPages, 'currentSummaryPage' => $currentSummaryPage, 'summaryResultCount' => $resultCount]);
    }
}
