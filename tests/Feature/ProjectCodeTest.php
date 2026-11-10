<?php

namespace Tests\Feature;

use App\Livewire\ProjectWorkspace;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_have_unique_permanent_codes_and_independent_numbers(): void
    {
        $client = Client::create(['name' => 'Entidad']);
        $first = Project::create(['client_id' => $client->id, 'name' => 'Uno', 'description' => 'Servicio']);
        $second = Project::create(['client_id' => $client->id, 'name' => 'Dos', 'description' => 'Servicio']);
        $this->assertSame('AA', $first->code);
        $this->assertSame('AB', $second->code);
        $first->update(['code' => 'ZZ']);
        $this->assertSame('AA', $first->fresh()->code);
        $tech = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false]);
        $this->actingAs($tech);
        foreach ([$first, $second] as $project) {
            $project->users()->attach($tech);
            $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
            Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'asset')->call('saveAsset')->assertHasNoErrors();
        }
        $this->assertSame('AA-001', $first->assets()->first()->kardex_code);
        $this->assertSame('AB-001', $second->assets()->first()->kardex_code);
        Livewire::test(ProjectWorkspace::class, ['project' => $first])->call('open', 'asset')->call('saveAsset')->assertHasNoErrors();
        $this->assertSame('AA-002', $first->assets()->orderByDesc('number')->first()->kardex_code);
        $asset = $first->assets()->first();
        $record = $asset->interventions()->first();
        $record->update(['status' => 'finalizado']);
        Livewire::test(ProjectWorkspace::class, ['project' => $first])->assertSet('statusFilters', ['borrador', 'observado', 'revision'])->assertDontSee('AA-001')->set('statusFilters', ['borrador', 'observado', 'revision', 'finalizado'])->assertSee('AA-001');
        $this->get(route('interventions.print', $record))->assertOk();
    }
    public function test_equipment_pagination_resets_when_search_or_states_change(): void
    {
        $project=Project::create(['client_id'=>Client::create(['name'=>'Cliente'])->id,'name'=>'Proyecto','description'=>'Servicio']);
        $user=User::factory()->create(['role'=>'admin','active'=>true,'must_change_password'=>false]);
        $template=$project->templates()->create(['name'=>'Cámara','fields'=>[],'preventive'=>[],'corrective'=>[]]);
        for($number=1;$number<=18;$number++) {
            $project->assets()->create(['template_id'=>$template->id,'equipment_id'=>\App\Models\Equipment::create(['name'=>'Equipo '.$number])->id,'number'=>$number,'technical_data'=>[]]);
        }
        $this->actingAs($user);
        $table=Livewire::test(ProjectWorkspace::class,['project'=>$project])->set('statusFilters',['borrador','observado','revision','finalizado']);
        $table->assertViewHas('assets',fn($rows)=>$rows->count()===15 && $rows->total()===18)
            ->call('nextPage','equipmentPage')->assertViewHas('assets',fn($rows)=>$rows->currentPage()===2 && $rows->count()===3)
            ->set('search','Equipo 18')->assertViewHas('assets',fn($rows)=>$rows->currentPage()===1 && $rows->total()===1)
            ->set('statusFilters',['revision'])->assertViewHas('assets',fn($rows)=>$rows->total()===0);
    }

}
