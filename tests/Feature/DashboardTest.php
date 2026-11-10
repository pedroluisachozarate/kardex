<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_scopes_counts_dates_and_filters_by_role(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(12, 0));
        $tech = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
        $other = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'active' => true, 'must_change_password' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true, 'must_change_password' => false]);
        $client = Client::create(['name' => 'Entidad']);
        $project = Project::create(['description' => 'Servicio', 'client_id' => $client->id, 'name' => 'Cámaras']);
        $foreign = Project::create(['description' => 'Servicio', 'client_id' => $client->id, 'name' => 'Otro proyecto']);
        $project->users()->attach([$tech->id, $other->id, $supervisor->id]);
        $makeAsset = function ($p, $number) {
            $template = $p->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);

            return $p->assets()->create(['template_id' => $template->id, 'equipment_id' => Equipment::create(['name' => 'Equipo'])->id, 'number' => $number, 'technical_data' => []]);
        };
        $this->actingAs($tech)->get(route('projects.index'))->assertOk()->assertSee('Mis proyectos')->assertViewHas('projects', fn ($rows) => $rows->pluck('id')->all() === [$project->id]);
        $this->actingAs($admin)->get(route('projects.index'))->assertOk()->assertViewHas('projects', fn ($rows) => $rows->count() === 2);
        $asset = $makeAsset($project, 1);
        $foreignAsset = $makeAsset($foreign, 1);
        foreach ([['2026-10-18', 'revision', $tech], ['2026-10-18', 'finalizado', $tech], ['2026-10-17', 'borrador', $tech], ['2026-10-16', 'observado', $other]] as $index => $row) {
            $asset->interventions()->create(['number' => $index + 1, 'date' => $row[0], 'status' => $row[1], 'created_by' => $row[2]->id, 'type' => 'preventivo']);
        }
        $foreignAsset->interventions()->create(['number' => 1, 'date' => '2026-10-18', 'status' => 'finalizado', 'created_by' => $other->id, 'type' => 'preventivo']);
        $this->actingAs($tech)->get('/')->assertOk()->assertViewHas('equipmentCount', 1)
            ->assertViewHas('interventions', fn ($rows) => $rows->count() === 2)
            ->assertViewHas('availableDates', fn ($dates) => $dates->all() === ['2026-10-17', '2026-10-18'])
            ->assertViewHas('summary', fn ($rows) => $rows->first()['total'] === 2 && $rows->first()['finalizado'] === 1);
        $this->get('/?from=2026-10-17&to=2026-10-18')->assertOk()->assertViewHas('interventions', fn ($rows) => $rows->count() === 3)->assertViewHas('equipmentCount', 1);
        $this->get('/?from=2026-10-16&to=2026-10-16')->assertSessionHasErrors('from');
        $this->get('/?project_id='.$foreign->id)->assertSessionHasErrors('project_id');
        $this->actingAs($supervisor)->get('/?from=2026-10-16&to=2026-10-18')->assertOk()->assertViewHas('interventions', fn ($rows) => $rows->count() === 4);
        $this->get('/?technician_id='.$other->id.'&from=2026-10-16&to=2026-10-16')->assertOk()->assertViewHas('interventions', fn ($rows) => $rows->count() === 1);
        $this->get('/?technician_id='.$admin->id)->assertForbidden();
        $this->actingAs($admin)->get('/')->assertOk()->assertViewHas('equipmentCount', 2)->assertViewHas('interventions', fn ($rows) => $rows->count() === 3);
        $this->get('/?project_id='.$foreign->id)->assertOk()->assertViewHas('summary', fn ($rows) => $rows->count() === 1);
        $this->get('/?from=2026-10-18&to=2026-10-17')->assertSessionHasErrors('to');
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->get('/')->assertOk()->assertSee('Hoy no hay mantenimientos registrados.')->assertViewHas('equipmentCount',0);
    }
}
