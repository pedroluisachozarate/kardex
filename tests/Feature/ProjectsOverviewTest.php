<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_cards_count_equipment_once_and_all_date_team_pending_with_role_visibility(): void
    {
        $tech = User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $other = User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $supervisor = User::factory()->create(['role'=>'supervisor','active'=>true,'must_change_password'=>false]);
        $client = Client::create(['name'=>'Aduana Nacional']);
        $project = Project::create(['client_id'=>$client->id,'name'=>'Mantenimiento de cámaras','description'=>'Servicio']);
        $project->users()->attach([$tech->id,$supervisor->id]);
        $template = $project->templates()->create(['name'=>'Cámara','fields'=>[],'preventive'=>[],'corrective'=>[]]);
        $asset = $project->assets()->create(['template_id'=>$template->id,'equipment_id'=>Equipment::create(['name'=>'Equipo'])->id,'number'=>1,'technical_data'=>[]]);
        foreach (['observado','borrador','revision','finalizado'] as $n=>$state) {
            $asset->interventions()->create(['number'=>$n+1,'date'=>'2025-01-01','created_by'=>$other->id,'status'=>$state,'type'=>'preventivo']);
        }
        $this->actingAs($tech)->get('/proyectos')->assertOk()->assertSee('4 mantenimientos')->assertSee('Observados')->assertSee('Borradores')->assertSee('Finalizados')->assertDontSee('Abrir proyecto →')
            ->assertViewHas('projects',fn($p)=>$p->first()->assets_count===1 && !$p->first()->relationLoaded('assets'))
            ->assertViewHas('pendingCounts',fn($counts)=>(int)$counts[$project->id]->observado===1 && (int)$counts[$project->id]->revision===1 && (int)$counts[$project->id]->finalizado===1 && (int)$counts[$project->id]->total===4);
        $this->get('/proyectos?q=Aduana')->assertOk()->assertViewHas('projects',fn($p)=>$p->total()===1);
        $this->get('/proyectos?q=cámaras')->assertOk()->assertViewHas('projects',fn($p)=>$p->total()===1);
        $this->get('/proyectos?q=inexistente')->assertOk()->assertSee('No hay proyectos que coincidan')->assertViewHas('projects',fn($p)=>$p->total()===0);
        $this->actingAs($supervisor)->get('/proyectos')->assertOk()->assertSee('En revisión');
        $this->actingAs($other)->get('/proyectos?q=Aduana')->assertOk()->assertViewHas('projects',fn($p)=>$p->total()===0)->assertDontSee('Mantenimiento de cámaras');
    }

    public function test_search_and_pagination_keep_assigned_project_scope(): void
    {
        $tech = User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $client = Client::create(['name'=>'YPFB']);
        for($n=1;$n<=16;$n++) {
            $project = Project::create(['client_id'=>$client->id,'name'=>'Aires '.$n,'description'=>'Servicio']);
            $project->users()->attach($tech);
        }
        Project::create(['client_id'=>$client->id,'name'=>'Aires privado','description'=>'Privado']);
        $this->actingAs($tech)->get('/proyectos?q=Aires')->assertOk()->assertViewHas('projects',fn($p)=>$p->total()===16 && $p->count()===15 && str_contains($p->nextPageUrl(),'q=Aires'))->assertDontSee('Aires privado');
        $this->get('/proyectos?q=Aires&page=2')->assertOk()->assertViewHas('projects',fn($p)=>$p->count()===1);
        $this->get('/proyectos?q='.str_repeat('a',121))->assertSessionHasErrors('q');
    }
}
