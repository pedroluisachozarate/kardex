<?php

namespace Tests\Feature;

use App\Http\Controllers\MobileSyncController;
use App\Livewire\MobileWorkspace;
use App\Models\Client;
use App\Models\MobileDraft;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    private function technician(): User
    {
        return User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
    }

    private function ticket(User $user, string $state): array
    {
        $response = $this->actingAs($user)->withSession(['native_app_state' => $state])->post('/native/offline')->assertOk()->assertViewIs('mobile-return');
        parse_str(parse_url($response->viewData('deviceUrl'), PHP_URL_QUERY), $data);
        $this->assertSame('127.0.0.1', parse_url($response->viewData('deviceUrl'), PHP_URL_HOST));

        return $data;
    }

    public function test_full_system_entry_preserves_existing_roles_and_offline_preparation_is_technician_only(): void
    {
        $state = Str::random(64);
        $this->get('/native/entry?'.http_build_query(['state' => $state]))->assertRedirect('/')->assertSessionHas('native_app_state', $state);
        $this->get('/')->assertRedirect('/login');
        foreach (['admin', 'supervisor', 'tecnico', 'consulta'] as $role) {
            $user = User::factory()->create(['role' => $role, 'active' => true, 'must_change_password' => false]);
            $page = $this->actingAs($user)->withSession(['native_app_state' => $state])->get('/');
            $page->assertOk()->assertSee('Resumen');
            if ($role === 'admin') {
                $page->assertSee('Administración')->assertDontSee('Borradores del celular')
                    ->assertDontSee('Descarga una copia para crear Kardex sin conexión.');
            } elseif ($role === 'tecnico') {
                $page->assertSee('Borradores del celular');
            } else {
                $page->assertDontSee('Borradores del celular');
            }
            if ($role !== 'tecnico') {
                $this->post('/native/offline')->assertForbidden();
            }
        }
        $this->getJson('/native/entry?'.http_build_query(['state' => $state, 'path' => '//evil.example']))->assertUnprocessable();
    }

    public function test_handoff_is_single_use_state_bound_expiring_and_downloads_only_assigned_projects(): void
    {
        $user = $this->technician();
        $client = Client::create(['name' => 'Entidad']);
        $project = Project::create(['client_id' => $client->id, 'name' => 'Asignado', 'description' => 'Servicio']);
        $project->users()->attach($user);
        Project::create(['client_id' => $client->id, 'name' => 'Privado', 'description' => 'Servicio']);
        $state = Str::random(64);
        $data = $this->ticket($user, $state);
        $this->postJson('/api/mobile/handoff', array_replace($data, ['state' => Str::random(64)]))->assertUnauthorized();
        $this->assertSame(1, DB::table('mobile_handoffs')->count());
        $auth = $this->postJson('/api/mobile/handoff', $data)->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.name', 'Asignado')->json();
        $this->assertSame(hash('sha256', $state), DB::table('mobile_tokens')->where('token_hash', hash('sha256', $auth['token']))->value('app_state_hash'));
        $this->postJson('/api/mobile/handoff', $data)->assertUnauthorized();
        $expired = $this->ticket($user, $state);
        $this->travel(2)->minutes();
        $this->postJson('/api/mobile/handoff', $expired)->assertUnauthorized();
        $this->travelBack();
        $revoked = $this->ticket($user, $state);
        $user->update(['active' => false]);
        $this->postJson('/api/mobile/handoff', $revoked)->assertForbidden();
    }

    public function test_device_opens_the_full_server_and_offline_copy_remains_available_without_requests(): void
    {
        config(['mobile.enabled' => true, 'mobile.server_url' => 'https://kardex.example']);
        Http::preventStrayRequests();
        $response = $this->get('/mobile?'.http_build_query(['path' => '/proyectos/2']))->assertOk()->assertViewIs('mobile-entry');
        $url = $response->viewData('serverEntry');
        $this->assertSame('kardex.example', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('/proyectos/2', $query['path']);
        $this->assertSame(session('mobile.app_state'), $query['state']);
        $this->withSession(['mobile.owner' => 42, 'mobile.catalog' => [['id' => 1, 'name' => 'Cache', 'templates' => []]]])->get('/mobile/offline')->assertOk()->assertSee('Sistema completo')->assertSessionHas('mobile.owner', 42);
        Http::assertNothingSent();
    }

    public function test_device_handoff_rejects_another_session_and_stores_encrypted_credentials(): void
    {
        config(['mobile.enabled' => true, 'mobile.server_url' => 'https://kardex.example']);
        $state = Str::random(64);
        $code = Str::random(64);
        Http::fake(['*' => Http::response(['user' => ['id' => 7, 'name' => 'Técnico', 'email' => 'tech@example.test'], 'token' => 'secret-token', 'projects' => []])]);
        $this->withSession(['mobile.app_state' => $state, 'mobile.owner' => 42])->get('/mobile/handoff?'.http_build_query(['code' => $code, 'state' => Str::random(64)]))->assertForbidden();
        Http::assertNothingSent();
        $this->get('/mobile/handoff?'.http_build_query(['code' => $code, 'state' => $state]))->assertRedirect('/mobile/offline')->assertSessionHas('mobile.owner', 7);
        $this->assertSame('secret-token', Crypt::decryptString(session('mobile.token')));
        $this->assertNotSame('secret-token', session('mobile.token'));
        Http::assertSentCount(1);
    }

    public function test_logout_revokes_this_app_only_and_clears_local_identity_without_deleting_drafts(): void
    {
        $user = $this->technician();
        $state = Str::random(64);
        $service = app(MobileSyncController::class);
        $auth = $service->credentials($user, $state);
        $other = $service->credentials($user, Str::random(64));
        $this->actingAs($user)->withSession(['native_app_state' => $state])->post('/logout')->assertOk()->assertViewIs('mobile-return')->assertViewHas('deviceUrl', 'http://127.0.0.1/mobile/signout?state='.$state);
        $this->assertDatabaseMissing('mobile_tokens', ['token_hash' => hash('sha256', $auth['token'])]);
        $this->assertDatabaseHas('mobile_tokens', ['token_hash' => hash('sha256', $other['token'])]);
        config(['mobile.enabled' => true]);
        $draft = MobileDraft::create(['id' => (string) Str::uuid(), 'owner_id' => $user->id, 'payload' => ['date' => '2026-11-21'], 'photos' => []]);
        $this->withSession(['mobile.app_state' => $state, 'mobile.owner' => $user->id, 'mobile.catalog' => []])->get('/mobile/signout?state='.Str::random(64))->assertForbidden()->assertSessionHas('mobile.owner', $user->id);
        $this->get('/mobile/signout?state='.$state)->assertRedirect('/mobile')->assertSessionMissing('mobile.owner');
        $this->assertDatabaseHas('mobile_drafts', ['id' => $draft->id]);
    }

    public function test_downloaded_project_navigation_filters_history_and_preserves_equipment_values(): void
    {
        config(['mobile.enabled' => true]);
        Http::preventStrayRequests();
        $catalog = [['id' => 1, 'name' => 'Proyecto', 'client' => 'Entidad', 'templates' => [['id' => 2, 'name' => 'Cámara', 'version' => str_repeat('a', 64), 'fields' => [['name' => 'Marca', 'type' => 'texto', 'required' => false]], 'preventive' => ['Limpieza'], 'corrective' => []]], 'equipment' => [['id' => 3, 'number' => 1, 'kardex' => 'AA-001', 'template_id' => 2, 'identity' => ['name' => 'Cámara AA-001', 'brand' => 'Dahua'], 'technical' => ['Marca' => ['value' => 'Dahua']], 'interventions' => [['id' => 4, 'number' => 1, 'date' => now('America/La_Paz')->toDateString(), 'type' => 'preventivo', 'status' => 'finalizado', 'created_by' => 42, 'progress' => 100]]]]]];
        $this->withSession(['mobile.owner' => 42, 'mobile.catalog' => $catalog]);
        Livewire::test(MobileWorkspace::class)->assertSee('Proyectos del período')->assertSee('Kardex que requieren atención')->call('navigate', 'projects')->assertSee('Proyecto')->call('showProject', 1)->assertDontSee('AA-001')->set('states', ['finalizado'])->assertSee('AA-001')->call('newDraft', 1, 3)->assertSet('form.technical.0', 'Dahua')->call('cancelEdit')->call('navigate', 'drafts')->assertSee('Borradores del celular');
        Http::assertNothingSent();
        Livewire::test(MobileWorkspace::class)->call('showProject', 999)->assertNotFound();
    }
    public function test_summary_filters_downloaded_records_and_prioritizes_local_drafts_without_server_requests(): void
    {
        config(['mobile.enabled' => true]);
        Http::preventStrayRequests();
        $today = now('America/La_Paz')->toDateString();
        $catalog = collect([1, 2])->map(fn ($id) => [
            'id' => $id, 'name' => 'Proyecto '.$id, 'client' => 'Entidad', 'templates' => [],
            'equipment' => [['id' => $id, 'kardex' => 'AC-00'.$id, 'identity' => ['name' => 'Equipo '.$id], 'interventions' => [
                ['id' => $id, 'number' => 1, 'date' => $today, 'type' => 'preventivo', 'status' => 'observado', 'created_by' => 42, 'progress' => 100],
                ['id' => 100 + $id, 'number' => 2, 'date' => $today, 'type' => 'preventivo', 'status' => 'borrador', 'created_by' => 99, 'progress' => 0],
            ]]],
        ])->all();
        $draft = MobileDraft::create(['id' => (string) Str::uuid(), 'owner_id' => 42, 'payload' => ['project_id' => 1, 'date' => $today, 'type' => 'preventivo'], 'photos' => [], 'sync_status' => 'local']);
        $this->withSession(['mobile.owner' => 42, 'mobile.catalog' => $catalog]);
        $screen = Livewire::test(MobileWorkspace::class)
            ->assertSee('Los cambios se guardan como borradores')
            ->assertViewHas('period', fn ($period) => $period->count() === 3)
            ->set('summaryProject', '1')
            ->assertViewHas('period', fn ($period) => $period->count() === 3)
            ->call('applySummaryFilters')
            ->assertViewHas('period', fn ($period) => $period->count() === 2 && $period->every(fn ($r) => $r['project_id'] === 1))
            ->set('summaryFrom', now('America/La_Paz')->subDay()->toDateString())
            ->set('summaryTo', now('America/La_Paz')->subDay()->toDateString())
            ->call('applySummaryFilters')->assertViewHas('period', fn ($period) => $period->isEmpty())
            ->call('summaryToday')->assertSet('summaryProject', '')
            ->assertViewHas('period', fn ($period) => $period->count() === 3)
            ->call('showSummary', 'maintenance', 'borrador')->call('edit', $draft->id)->assertSet('editing', $draft->id)
            ->call('cancelEdit')->assertSet('summarySection', 'maintenance')->assertSet('summaryState', 'borrador')->assertSet('from', $today)->assertSet('appliedSummaryProject', '');
        $screen->call('cancelEdit')->set('summaryProject', '999')->call('applySummaryFilters')->assertHasErrors('summaryProject');
        Http::assertNothingSent();
    }

    public function test_summary_state_cards_and_lists_keep_date_project_filters_and_paginate_offline(): void
    {
        config(['mobile.enabled' => true]);
        Http::preventStrayRequests();
        $today = now('America/La_Paz')->toDateString();
        $yesterday = now('America/La_Paz')->subDay()->toDateString();
        $catalog = collect([1, 2])->map(function ($project) use ($today, $yesterday) {
            $items = [];
            foreach (['borrador', 'revision', 'observado', 'finalizado'] as $index => $status) {
                foreach (range(1, 12) as $number) {
                    $items[] = ['id' => $project * 1000 + $index * 100 + $number, 'number' => $index * 100 + $number, 'date' => $today, 'type' => 'preventivo', 'status' => $status, 'created_by' => 42, 'progress' => 100];
                }
            }
            $items[] = ['id' => $project * 1000 + 900, 'number' => 900, 'date' => $yesterday, 'type' => 'preventivo', 'status' => 'borrador', 'created_by' => 42, 'progress' => 0];
            $items[] = ['id' => $project * 1000 + 901, 'number' => 901, 'date' => $today, 'type' => 'preventivo', 'status' => 'borrador', 'created_by' => 99, 'progress' => 0];
            return ['id' => $project, 'name' => 'Proyecto '.$project, 'client' => 'Entidad', 'templates' => [], 'equipment' => [['id' => $project, 'kardex' => 'AC-00'.$project, 'identity' => ['name' => 'Equipo '.$project], 'interventions' => $items]]];
        })->all();
        $this->withSession(['mobile.owner' => 42, 'mobile.catalog' => $catalog]);
        $screen = Livewire::test(MobileWorkspace::class)->set('summaryProject', '1')->call('applySummaryFilters');
        foreach (['borrador', 'revision', 'observado', 'finalizado'] as $state) {
            $screen->call('showSummary')->assertSeeHtml("wire:click.prevent=\"showSummary('maintenance','".$state."')\"")
                ->call('showSummary', 'maintenance', $state)
                ->assertSet('page', 'summary')->assertSet('summarySection', 'maintenance')->assertSet('summaryState', $state)
                ->assertSet('from', $today)->assertSet('to', $today)->assertSet('appliedSummaryProject', '1')
                ->assertSee('Todos los mantenimientos')->assertSee('Volver a la pantalla anterior')
                ->assertViewHas('summaryResultCount', 12)->assertViewHas('summaryPages', 2)
                ->assertViewHas('summaryRows', fn ($rows) => $rows->count() === 10 && $rows->every(fn ($r) => $r['project_id'] === 1 && $r['date'] === $today && $r['status'] === $state && $r['created_by'] === 42))
                ->call('changeSummaryPage', 1)->assertViewHas('currentSummaryPage', 2)->assertViewHas('summaryRows', fn ($rows) => $rows->count() === 2)
                ->set('summaryState', '')->assertSet('summaryResultPage', 1)->assertViewHas('summaryResultCount', 48);
        }
        $screen->call('showSummary', 'projects')->assertSee('Todos los proyectos del período')
            ->assertViewHas('summaryProjects', fn ($groups) => $groups->count() === 1)
            ->call('showSummary', 'maintenance', '', 2)->assertSet('appliedSummaryProject', '2')->assertViewHas('summaryResultCount', 48)
            ->call('showSummary')->assertSet('summarySection', 'overview')->assertSet('summaryState', '');
        Livewire::test(MobileWorkspace::class)
            ->set('summaryFrom', $yesterday)->set('summaryTo', $today)->call('applySummaryFilters')
            ->call('showSummary', 'projects')->call('showSummary', 'maintenance', '', 2)
            ->assertSet('appliedSummaryProject', '2')
            ->set('summaryFrom', $today)->call('applySummaryFilters')
            ->call('showSummary')->assertSet('appliedSummaryProject', '')->assertSet('summaryProject', '')
            ->assertSet('from', $yesterday)->assertSet('summaryFrom', $yesterday)->assertSet('to', $today)
            ->assertViewHas('summaryResultCount', 98);
        Livewire::test(MobileWorkspace::class)
            ->set('summaryFrom', $yesterday)->set('summaryTo', $today)->call('applySummaryFilters')
            ->call('showSummary', 'projects')->call('showSummary', 'maintenance', '', 2)
            ->set('summaryState', 'revision')->call('backSummary')
            ->assertSet('summarySection', 'projects')->assertSet('summaryProject', '')
            ->assertSet('from', $yesterday)->assertSet('to', $today)
            ->call('backSummary')->assertSet('summarySection', 'overview')->assertSet('summaryProject', '')
            ->assertSet('from', $yesterday)->assertSet('to', $today);
        Http::assertNothingSent();
        Livewire::test(MobileWorkspace::class)->call('showSummary', 'maintenance', 'invalido')->assertNotFound();
        Livewire::test(MobileWorkspace::class)->call('showSummary', 'maintenance', '', 999)->assertNotFound();
    }

    public function test_opening_server_without_connection_keeps_local_editor_and_shows_notice(): void
    {
        config(['mobile.enabled' => true, 'mobile.server_url' => 'https://kardex.example']);
        $available = false;
        Http::fake(function () use (&$available) { return $available ? Http::response(['service' => 'kardex']) : Http::response([], 503); });
        $this->withSession(['mobile.owner' => 42]);
        Livewire::test(MobileWorkspace::class)->call('newDraft')->set('form.observations', 'Conservar este texto')->call('openSystem')->assertSet('form.observations', 'Conservar este texto')->assertSet('serverAvailable', false)->assertDispatched('mobile-toast')->assertNoRedirect();
        $available = true;
        Livewire::test(MobileWorkspace::class)->call('openSystem', '/proyectos/2')->assertRedirect('/mobile?path=%2Fproyectos%2F2');
        Livewire::test(MobileWorkspace::class)->call('openSystem', '//other.example')->assertNotFound();
    }

    public function test_offline_notice_does_not_conflict_with_native_mobile_blade_condition(): void
    {
        config(['mobile.enabled' => true]);
        \Illuminate\Support\Facades\Blade::if('mobile', fn () => true);
        $this->get('/mobile/offline')->assertOk()->assertSee('x-on:mobile-toast.window', false)
            ->assertSee('Se necesita conexión a internet')
            ->assertDontSee('Borradores del celular');
    }

}
