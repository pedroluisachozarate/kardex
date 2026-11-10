<?php

namespace Tests\Feature;

use App\Livewire\Administration;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_requires_entity_and_name_and_saves_in_one_step(): void
    {
        $this->actingAs($this->admin());
        $client = Client::create(['name' => 'Entidad']);
        Livewire::test(Administration::class)->call('startCreate', 'proyectos')
            ->assertSee('Seleccionar entidad')->assertSee('Nombre del proyecto')
            ->assertDontSee('Continuar')->assertDontSee('Cliente existente')
            ->call('save')->assertHasErrors(['form.client_id', 'form.name'])
            ->set('form.client_id', 999)->set('form.name', 'Servicio anual')
            ->call('save')->assertHasErrors('form.client_id')
            ->set('form.client_id', $client->id)->call('save')
            ->assertHasNoErrors()->assertSet('showForm', false);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('projects', ['name' => 'Servicio anual', 'client_id' => $client->id]);
    }

    public function test_cancel_project_does_not_save(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(Administration::class)->call('startCreate', 'proyectos')
            ->set('form.name', 'No guardar')->call('closeForm')->assertSet('showForm', false);
        $this->assertDatabaseCount('projects', 0);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true, 'must_change_password' => false]);
    }

    public function test_create_edit_search_and_dependent_client_options(): void
    {
        $this->actingAs($this->admin());
        $component = Livewire::test(Administration::class)->call('openForm')->set('form.name', 'Gestora')->set('form.contact', 'Oficina central')->call('save')->assertHasNoErrors()->assertSet('showForm', false)->assertSee('Gestora');
        $client = Client::firstOrFail();
        $component->call('openForm', $client->id)->set('form.name', 'Gestora Pública')->call('save')->assertHasNoErrors()->set('search', 'inexistente')->assertSee('Sin coincidencias')->assertDontSee('Gestora Pública')->set('search', 'Gestora')->assertSee('Gestora Pública');
        $component->call('selectTab', 'proyectos')->call('openForm')->set('form.client_id', $client->id)->set('form.name', 'Mantenimiento')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('projects', ['name' => 'Mantenimiento', 'client_id' => $client->id]);
    }

    public function test_validation_keeps_form_and_user_changes_do_not_reset_password_unnecessarily(): void
    {
        $this->actingAs($this->admin());
        $component = Livewire::test(Administration::class)->call('selectTab', 'usuarios')->call('openForm')->call('save')->assertHasErrors(['form.name', 'form.email', 'form.password'])->assertSet('showForm', true);
        $component->set('form.name', 'Técnico')->set('form.email', 'tecnico@example.test')->set('form.password', 'Temporal12345')->call('save')->assertHasNoErrors();
        $u = User::where('email', 'tecnico@example.test')->firstOrFail();
        $hash = $u->password;
        $component->call('openForm', $u->id)->set('form.active', false)->set('form.role', 'consulta')->call('save')->assertHasNoErrors();
        $this->assertFalse($u->fresh()->active);
        $this->assertSame('consulta', $u->fresh()->role);
        $this->assertSame($hash, $u->fresh()->password);
        $this->assertTrue($u->fresh()->must_change_password);
    }

    public function test_admin_permissions_rechecked_on_livewire_requests(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $component = Livewire::test(Administration::class);
        $admin->update(['role' => 'tecnico']);
        $component->call('openForm')->assertForbidden();
        $this->assertSame(0, Client::count());
    }

    public function test_admin_cannot_edit_own_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(Administration::class)->call('selectTab', 'usuarios')->call('openForm', $admin->id)->assertForbidden();
    }
}
