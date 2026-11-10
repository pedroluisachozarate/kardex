<?php



namespace App\Livewire;



use App\Models\AuditLog;

use App\Models\Client;

use App\Models\Project;

use App\Models\User;

use Illuminate\Support\Facades\DB;

use Illuminate\Validation\Rule;

use Livewire\Attributes\Locked;

use Livewire\Attributes\Url;

use Livewire\Component;

use Livewire\WithPagination;



class Administration extends Component

{

    use WithPagination;



    #[Url]

    public string $tab = 'clientes';



    public string $search = '';



    public string $clientFilter = '';



    #[Locked]

    public bool $showForm = false;



    #[Locked]

    public ?int $editing = null;



    #[Locked]

    public string $formType = 'clientes';



    public array $form = [];



    public string $message = '';



    #[Locked]

    public ?int $savedId = null;



    #[Locked]

    public string $savedType = '';



    public function startCreate(string $type, ?int $clientId = null): void

    {

        $this->selectTab($type);

        $this->openForm();

        if ($clientId) {

            $this->form['client_id'] = Client::findOrFail($clientId)->id;

        }

    }



    public function boot(): void

    {

        abort_unless(auth()->check() && auth()->user()->active && ! auth()->user()->must_change_password && auth()->user()->role === 'admin', 403);

    }



    private function model(string $type): string

    {

        return match ($type) {

            'clientes' => Client::class,

            'proyectos' => Project::class, 'usuarios' => User::class,

            default => abort(404),

        };

    }



    public function mount(): void

    {

        if (! in_array($this->tab, ['clientes', 'proyectos', 'usuarios'])) {

            $this->tab = 'clientes';

        }

    }



    public function selectTab(string $tab): void

    {

        $this->model($tab);

        $this->tab = $tab;

        $this->search = $this->clientFilter = '';

        $this->resetPage();

        $this->closeForm();

    }



    public function updatedSearch(): void

    {

        $this->resetPage();

    }



    public function updatedClientFilter(): void

    {

        $this->resetPage();

    }



    public function openForm(?int $id = null): void

    {

        $model = $this->model($this->tab);

        $this->resetValidation();

        $this->message = '';







        $this->formType = $this->tab;

        $this->editing = $id;

        $this->form = ['name' => '', 'contact' => '', 'client_id' => $this->clientFilter, 'building' => '', 'floor' => '', 'area' => '', 'email' => '', 'phone' => '', 'role' => 'tecnico', 'active' => true, 'password' => ''];

        if ($id) {

            $record = $model::findOrFail($id);

            abort_if($this->tab === 'usuarios' && $id === auth()->id(), 403);

            $fields = match ($this->tab) {

                'clientes' => ['name', 'contact'],

                'proyectos' => ['client_id', 'name'], 'usuarios' => ['name', 'email', 'phone', 'role', 'active'],

            };

            $this->form = array_replace($this->form, $record->only($fields));

        }

        $this->showForm = true;

    }



    public function closeForm(): void

    {

        $this->showForm = false;

        $this->editing = null;

        $this->form = [];



        $this->resetValidation();

    }



    protected function rules(): array

    {

        return match ($this->formType) {

            'clientes' => ['form.name' => 'required|string|max:200', 'form.contact' => 'nullable|string|max:255'],

            'proyectos' => ['form.client_id' => 'required|exists:clients,id', 'form.name' => 'required|string|max:200'],

            'usuarios' => ['form.name' => 'required|string|max:200', 'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing)], 'form.phone' => 'nullable|string|max:50', 'form.role' => ['required', Rule::in(['admin', 'supervisor', 'tecnico', 'consulta'])], 'form.active' => 'required|boolean', 'form.password' => [$this->editing ? 'nullable' : 'required', 'string', 'min:10', 'max:100']],

        };

    }



    protected function messages(): array

    {

        return ['required' => 'Este campo es obligatorio.', 'email' => 'Escribe un correo válido.', 'unique' => 'Este correo ya está registrado.', 'min' => 'La contraseña debe tener al menos 10 caracteres.', 'exists' => 'Selecciona un registro válido.'];

    }



    public function updated(string $property): void

    {

        if ($this->showForm && str_starts_with($property, 'form.') && isset($this->rules()[$property])) {

            $this->validateOnly($property);

        }

    }



    public function save(): void

    {

        abort_unless($this->showForm, 403);

        $data = $this->validate()['form'];

        if ($this->formType === 'proyectos' && ! $this->editing) {

            $data['description'] = '';

        }

        $model = $this->model($this->formType);

        $record = $this->editing ? $model::findOrFail($this->editing) : new $model;

        if ($this->formType === 'usuarios') {

            abort_if($this->editing === auth()->id(), 403);

            if (empty($data['password'])) {

                unset($data['password']);

            } else {

                $data['must_change_password'] = true;

            }

        }

        if ($this->editing && isset($data['client_id']) && (int) $data['client_id'] !== $record->client_id) {

            $used = $record->assets()->exists();

            if ($used) {

                $this->addError('form.client_id', 'Ya tiene equipos vinculados. Conserva el cliente para mantener su historial.');



                return;

            }

        }

        DB::transaction(function () use ($record, $data) {

            $record->fill($data)->save();

            AuditLog::create(['user_id' => auth()->id(), 'action' => ($this->editing ? 'Actualización' : 'Creación').' de '.$this->formType, 'details' => ['record_id' => $record->id]]);

        });

        $this->savedId = $record->id;

        $this->savedType = $this->formType;

        $noun = ['clientes' => 'Cliente', 'proyectos' => 'Proyecto', 'usuarios' => 'Usuario'][$this->formType];

        $this->message = $this->editing ? $noun.' actualizado correctamente.' : $noun.' creado correctamente.';

        $this->closeForm();

        $this->search = '';

        $this->resetPage();

    }



    public function render()

    {

        $q = ($this->model($this->tab))::query();

        $columns = match ($this->tab) {

            'clientes' => ['name', 'contact'],'proyectos' => ['name'],'usuarios' => ['name', 'email', 'phone']

        };

        if ($this->search !== '') {

            $q->where(function ($query) use ($columns) {

                foreach ($columns as $column) {

                    $query->orWhere($column, 'like', '%'.$this->search.'%');

                }

            });

        }

        if ($this->clientFilter !== '' && in_array($this->tab, ['proyectos'])) {

            $q->where('client_id', $this->clientFilter);

        }

        $clients = Client::orderBy('name')->get(['id', 'name']);



        return view('livewire.administration', ['records' => $q->latest('id')->paginate(10), 'clients' => $clients, 'counts' => ['clientes' => Client::count(), 'proyectos' => Project::count(), 'usuarios' => User::count()]]);

    }

}

