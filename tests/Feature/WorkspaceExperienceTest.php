<?php

namespace Tests\Feature;

use App\Livewire\MobileWorkspace;
use App\Livewire\ProjectWorkspace;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\MobileDraft;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_recovery_rechecks_access_and_does_not_save_or_change_record_identity(): void
    {
        $user = User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $project = Project::create(['client_id'=>Client::create(['name'=>'Entidad'])->id,'name'=>'Proyecto','description'=>'Servicio']);
        $project->users()->attach($user);
        $template = $project->templates()->create(['name'=>'Equipo','fields'=>[],'preventive'=>[],'corrective'=>[]]);
        $asset = $project->assets()->create(['template_id'=>$template->id,'equipment_id'=>Equipment::create(['name'=>'Equipo'])->id,'number'=>1,'technical_data'=>[]]);
        $record = $asset->interventions()->create(['number'=>1,'date'=>'2026-11-23','type'=>'preventivo','status'=>'borrador','created_by'=>$user->id]);
        $this->actingAs($user);
        $recovery = ['mode'=>'edit','id'=>$record->id,'values'=>['form'=>['observations'=>'Texto recuperado','template_id'=>999,'type'=>'correctivo']]];
        Livewire::test(ProjectWorkspace::class,['project'=>$project])->call('restoreRecovery',$recovery)
            ->assertSet('modal','edit')->assertSet('form.observations','Texto recuperado')
            ->assertSet('form.template_id',$template->id)->assertSet('form.type','preventivo');
        $this->assertNull($record->fresh()->observations);
        $record->update(['status'=>'finalizado']);
        Livewire::test(ProjectWorkspace::class,['project'=>$project])->call('restoreRecovery',$recovery)->assertStatus(409);
        $this->actingAs(User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]));
        Livewire::test(ProjectWorkspace::class,['project'=>$project])->assertForbidden();
    }

    public function test_offline_refresh_preserves_filters_and_local_drafts_when_server_fails(): void
    {
        config(['mobile.enabled'=>true,'mobile.server_url'=>'https://kardex.example']);
        Http::fake(['*'=>Http::response([],503)]);
        $catalog = [['id'=>1,'name'=>'Proyecto','templates'=>[],'equipment'=>[]]];
        $this->withSession(['mobile.owner'=>42,'mobile.catalog'=>$catalog,'mobile.token'=>Crypt::encryptString('test-token')]);
        $draft=MobileDraft::create(['id'=>'local-draft','owner_id'=>42,'payload'=>['project_id'=>1,'date'=>'2026-11-23'],'photos'=>[],'sync_status'=>'local']);
        Livewire::test(MobileWorkspace::class)->set('summaryProject','1')->set('summaryFrom','2026-11-01')->set('summaryTo','2026-11-23')
            ->call('applySummaryFilters')->call('showSummary','maintenance','borrador')->call('refreshWorkspace')
            ->assertSet('catalog',$catalog)->assertSet('summaryState','borrador')->assertSet('from','2026-11-01')->assertSet('summaryProject','1')
            ->assertSet('serverAvailable',false)->assertDispatched('kardex-notice');
        $this->assertSame('local',$draft->fresh()->sync_status);
        Http::assertSentCount(1);
    }

    public function test_refresh_updates_copy_but_does_not_run_during_editing(): void
    {
        config(['mobile.enabled'=>true,'mobile.server_url'=>'https://kardex.example']);
        $catalog=[['id'=>1,'name'=>'Actualizado','templates'=>[],'equipment'=>[]]];
        Http::fake(['*/health'=>Http::response(['service'=>'kardex']), '*/catalog'=>Http::response(['projects'=>$catalog])]);
        $this->withSession(['mobile.owner'=>42,'mobile.catalog'=>[],'mobile.token'=>Crypt::encryptString('test-token')]);
        Livewire::test(MobileWorkspace::class)->call('navigate','projects')->set('search','Actualizado')->call('refreshWorkspace')
            ->assertSet('page','projects')->assertSet('search','Actualizado')->assertSet('catalog',$catalog);
        $this->assertNotEmpty(session('mobile.downloaded_at'));
        Http::assertSentCount(2);
        Livewire::test(MobileWorkspace::class)->call('newDraft')->set('form.observations','Sin perder')
            ->call('refreshWorkspace')->assertSet('form.observations','Sin perder');
        Http::assertSentCount(2);
    }

    public function test_local_save_keeps_editor_and_saved_photo_preview_private(): void
    {
        config(['mobile.enabled'=>true]);
        Http::preventStrayRequests();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('tmp-for-tests');
        $catalog=[['id'=>1,'name'=>'Proyecto','templates'=>[['id'=>2,'name'=>'Cámara','fields'=>[], 'version'=>'test-version','preventive'=>['Limpiar'],'corrective'=>[]]],'equipment'=>[]]];
        $this->withSession(['mobile.owner'=>42,'mobile.catalog'=>$catalog]);
        $draft=MobileDraft::create(['id'=>'editing-draft','owner_id'=>42,'payload'=>['project_id'=>1,'template_id'=>2,'type'=>'preventivo','date'=>'2026-11-25','technical'=>[],'activities'=>[['result'=>'realizado','notes'=>'Comprobado']]],'photos'=>[],'sync_status'=>'local']);
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $screen=Livewire::test(MobileWorkspace::class)->call('edit',$draft->id)
            ->set('uploads.0',[\Illuminate\Http\UploadedFile::fake()->createWithContent('foto.png',$png)])
            ->call('save')->assertHasNoErrors()->assertSet('editing',$draft->id)->assertSee('Guardada')->assertDispatched('kardex-saved');
        $photo=$draft->fresh()->photos[0];
        $screen->call('save')->assertHasNoErrors()->assertSet('editing',$draft->id);
        $this->assertCount(1,$draft->fresh()->photos);
        $url=route('mobile.draft-photo',[$draft->id,$photo['uuid']]);
        $this->get($url)->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->withSession(['mobile.owner'=>99])->get($url)->assertNotFound();
        $this->withSession(['mobile.owner'=>null])->get($url)->assertNotFound();
        config(['mobile.enabled'=>false]);
        $this->withSession(['mobile.owner'=>42])->get($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_local_recovery_stays_local_and_rejects_already_synchronized_draft(): void
    {
        config(['mobile.enabled'=>true]);Http::preventStrayRequests();
        $catalog=[['id'=>1,'name'=>'Proyecto','templates'=>[['id'=>2,'name'=>'Cámara','fields'=>[],'preventive'=>[],'corrective'=>[]]],'equipment'=>[]]];
        $this->withSession(['mobile.owner'=>42,'mobile.catalog'=>$catalog]);
        $recovery=['id'=>'unfinished','values'=>['form'=>['project_id'=>1,'template_id'=>2,'observations'=>'Texto incompleto']]];
        Livewire::test(MobileWorkspace::class)->call('restoreRecovery',$recovery)->assertSet('form.observations','Texto incompleto');
        $this->assertDatabaseCount('mobile_drafts',0);
        MobileDraft::create(['id'=>'unfinished','owner_id'=>42,'payload'=>[],'photos'=>[],'sync_status'=>'synced','receipt'=>['intervention_id'=>1]]);
        Livewire::test(MobileWorkspace::class)->call('restoreRecovery',$recovery)->assertStatus(409);
        $recovery['values']['form']['project_id']=999;
        Livewire::test(MobileWorkspace::class)->call('restoreRecovery',$recovery)->assertStatus(422);
        Http::assertNothingSent();
    }
}
