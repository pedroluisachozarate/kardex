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
            ->assertViewHas('totalCount', 2)
            ->assertViewHas('availableDates', fn ($dates) => $dates->all() === ['2026-10-17', '2026-10-18'])
            ->assertViewHas('summary', fn ($rows) => $rows->first()['total'] === 2 && $rows->first()['finalizado'] === 1);
        $this->get('/?from=2026-10-17&to=2026-10-18')->assertOk()->assertViewHas('totalCount', 3)->assertViewHas('equipmentCount', 1);
        $original = ['from' => '2026-10-17', 'to' => '2026-10-18'];
        $overview = $this->get(route('dashboard', $original))->assertOk();
        $drillFilters = [...$overview->viewData('filters'), 'view' => 'maintenance', 'project_id' => $project->id];
        $details = $this->get(route('dashboard', $drillFilters))->assertOk()
            ->assertViewHas('overviewFilters', $original)
            ->assertSee('href="'.e(route('dashboard', $original)).'" aria-label="Volver al resumen"', false);
        $changed = [...$details->viewData('filters'), 'view' => 'maintenance', 'state' => 'borrador', 'from' => '2026-10-17', 'to' => '2026-10-17'];
        $this->get(route('dashboard', $changed))->assertOk()->assertViewHas('overviewFilters', $original);
        $this->get(route('dashboard', $details->viewData('overviewFilters')))->assertOk()->assertViewHas('totalCount', 3)->assertViewHas('filters', fn ($filters) => !isset($filters['project_id']));
        $projectsFilters = [...$original, 'view' => 'projects', 'overview' => $original];
        $projectsOrigin = route('dashboard', $projectsFilters, false);
        $maintenanceFilters = [...$projectsFilters, 'view' => 'maintenance', 'project_id' => $project->id, 'parent' => $projectsOrigin];
        $maintenancePage = $this->get(route('dashboard', $maintenanceFilters))->assertOk()
            ->assertViewHas('backUrl', url($projectsOrigin))->assertViewHas('backLabel', 'Volver a proyectos del período');
        $projectsAgain = $this->get($maintenancePage->viewData('backUrl'))->assertOk()->assertViewHas('section', 'projects')
            ->assertViewHas('backUrl', route('dashboard', $original));
        $this->get($projectsAgain->viewData('backUrl'))->assertOk()->assertViewHas('section', 'overview')->assertViewHas('totalCount', 3);
        $this->get('/?from=2026-10-16&to=2026-10-16')->assertSessionHasErrors('from');
        $this->get('/?project_id='.$foreign->id)->assertSessionHasErrors('project_id');
        $this->actingAs($supervisor)->get('/?from=2026-10-16&to=2026-10-18')->assertOk()->assertViewHas('totalCount', 4);
        $this->get('/?technician_id='.$other->id.'&from=2026-10-16&to=2026-10-16')->assertOk()->assertViewHas('totalCount', 1);
        $this->get('/?technician_id='.$admin->id)->assertForbidden();
        $this->actingAs($admin)->get('/')->assertOk()->assertViewHas('equipmentCount', 2)->assertViewHas('totalCount', 3);
        $this->get('/?project_id='.$foreign->id)->assertOk()->assertViewHas('summary', fn ($rows) => $rows->count() === 1);
        $this->get('/?from=2026-10-18&to=2026-10-17')->assertSessionHasErrors('to');
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->get('/')->assertOk()->assertSee('Hoy no hay mantenimientos registrados.')->assertViewHas('equipmentCount',0);
    }
    public function test_overview_limits_rows_preserves_full_totals_and_paginates_filtered_lists(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(12, 0));
        $tech = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'active' => true, 'must_change_password' => false]);
        $other = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
        $client = Client::create(['name' => 'Entidad']);
        $first = null;
        for ($n = 1; $n <= 18; $n++) {
            $project = Project::create(['client_id' => $client->id, 'name' => 'Proyecto '.$n, 'description' => 'Servicio']);
            $project->users()->attach([$tech->id, $supervisor->id]);
            $template = $project->templates()->create(['name' => 'Tipo', 'fields' => [], 'preventive' => [], 'corrective' => []]);
            $asset = $project->assets()->create(['template_id' => $template->id, 'equipment_id' => Equipment::create(['name' => 'Equipo '.$n])->id, 'number' => 1, 'technical_data' => []]);
            foreach (['borrador', 'observado', 'revision', 'finalizado'] as $index => $state) {
                $asset->interventions()->create(['number' => $index + 1, 'date' => '2026-11-22', 'status' => $state, 'created_by' => $tech->id, 'type' => 'preventivo']);
            }
            if ($n === 1) {
                $first = $project;
                foreach ([5, 6] as $number) {
                    $asset->interventions()->create(['number' => $number, 'date' => '2026-11-22', 'status' => 'observado', 'created_by' => $tech->id, 'type' => 'preventivo']);
                }
                $asset->interventions()->create(['number' => 7, 'date' => '2026-11-22', 'status' => 'observado', 'created_by' => $other->id, 'type' => 'preventivo']);
            }
        }
        $foreign = Project::create(['client_id' => $client->id, 'name' => 'Proyecto ajeno', 'description' => 'Privado']);
        $this->actingAs($tech)->get('/')->assertOk()
            ->assertViewHas('totalCount', 74)->assertViewHas('equipmentCount', 18)->assertViewHas('projectCount', 18)->assertViewHas('attentionCount', 38)
            ->assertViewHas('counts', fn ($counts) => $counts['finalizado'] === 18 && $counts['revision'] === 18)
            ->assertViewHas('summary', fn ($rows) => $rows->count() === 5 && $rows->first()['project']->is($first) && $rows->first()['observado'] === 3)
            ->assertViewHas('interventions', fn ($rows) => $rows->count() === 5 && $rows->every(fn ($i) => $i->status === 'observado' && !$i->relationLoaded('activities') && !array_key_exists('snapshot', $i->getAttributes())))
            ->assertDontSee('Proyecto ajeno');
        $query = ['view' => 'maintenance', 'state' => 'finalizado', 'from' => '2026-11-22', 'to' => '2026-11-22'];
        $this->get('/?'.http_build_query($query))->assertOk()->assertViewHas('maintenancePagination', fn ($p) => $p->total() === 18 && $p->count() === 15 && str_contains($p->nextPageUrl(), 'state=finalizado') && str_contains($p->nextPageUrl(), 'from=2026-11-22'));
        $this->get('/?'.http_build_query($query+['page' => 2]))->assertOk()->assertViewHas('interventions', fn ($rows) => $rows->count() === 3 && $rows->every(fn ($i) => $i->status === 'finalizado'));
        $this->get('/?view=projects')->assertOk()->assertViewHas('projectPagination', fn ($p) => $p->total() === 18 && $p->count() === 15);
        $this->get('/?view=projects&page=2')->assertOk()->assertViewHas('summary', fn ($rows) => $rows->count() === 3);
        $this->get('/?view=maintenance&project_id='.$first->id)->assertOk()->assertViewHas('totalCount', 6)->assertViewHas('interventions', fn ($rows) => $rows->count() === 6);
        $this->get('/?view=maintenance&project_id='.$foreign->id)->assertSessionHasErrors('project_id');
        $this->get('/?view=maintenance&state=invalid')->assertSessionHasErrors('state');
        $this->actingAs($supervisor)->get('/')->assertOk()->assertViewHas('totalCount', 75)->assertViewHas('interventions', fn ($rows) => $rows->count() === 5 && $rows->every(fn ($i) => $i->status === 'revision'));
        $this->get('/?view=maintenance&technician_id='.$other->id)->assertOk()->assertViewHas('totalCount', 1)->assertViewHas('interventions', fn ($rows) => $rows->count() === 1 && $rows->first()->created_by === $other->id);
    }

}
