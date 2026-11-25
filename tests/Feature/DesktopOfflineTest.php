<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesktopOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pc_buttons_require_a_technician_and_explicit_runtime_configuration(): void
    {
        config(['mobile.desktop_origin'=>'http://192.168.10.2:8003']);
        $this->post('/desktop/offline',['state'=>Str::random(64)])->assertRedirect('/login');
        foreach(['admin','supervisor','consulta'] as $role){
            $user=User::factory()->create(['role'=>$role,'active'=>true,'must_change_password'=>false]);
            $this->actingAs($user)->get('/password')->assertDontSee('data-desktop-download',false);
            $this->post('/desktop/offline',['state'=>Str::random(64)])->assertForbidden();
        }
        $tech=User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $this->actingAs($tech)->get('/password')->assertOk()->assertSee('Descargar mis proyectos')->assertSee('Borradores del celular')->assertSee('data-desktop-frame',false);
        config(['mobile.desktop_origin'=>null]);
        $this->get('/password')->assertDontSee('data-desktop-download',false);
        $this->post('/desktop/offline',['state'=>Str::random(64)])->assertForbidden();
    }

    public function test_desktop_download_uses_state_bound_one_time_handoff_for_the_current_user(): void
    {
        config(['mobile.desktop_origin'=>'http://192.168.10.2:8003']);
        $tech=User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $p=Project::create(['client_id'=>Client::create(['name'=>'Cliente'])->id,'name'=>'Proyecto','description'=>'Servicio']);$p->users()->attach($tech);
        $state=Str::random(64);
        $response=$this->actingAs($tech)->post('/desktop/offline',['state'=>$state])->assertOk()->assertSessionHas('native_app_device','desktop');
        $url=$response->viewData('deviceUrl');$this->assertStringStartsWith('http://192.168.10.2:8003/mobile/handoff?',$url);parse_str(parse_url($url,PHP_URL_QUERY),$data);
        $this->postJson('/api/mobile/handoff',array_merge($data,['state'=>Str::random(64)]))->assertUnauthorized();
        $this->postJson('/api/mobile/handoff',$data)->assertOk()->assertJsonPath('user.id',$tech->id)->assertJsonCount(1,'projects');
        $this->postJson('/api/mobile/handoff',$data)->assertUnauthorized();
        $this->post('/desktop/offline',['state'=>'bad'])->assertSessionHasErrors('state');
    }

    public function test_desktop_bootstrap_is_only_exposed_by_the_device_runtime(): void
    {
        config(['mobile.desktop_runtime'=>true,'mobile.enabled'=>false,'mobile.server_url'=>'https://kardex.example']);
        $this->get('/mobile/bootstrap')->assertNotFound();
        config(['mobile.enabled'=>true]);
        $response=$this->get('/mobile/bootstrap')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Content-Security-Policy','frame-ancestors https://kardex.example');
        $this->assertSame(64,strlen($response->viewData('state')));
        $this->assertSame($response->viewData('state'),session('mobile.app_state'));
        $this->get('/mobile/offline')->assertOk()->assertSeeLivewire(\App\Livewire\MobileWorkspace::class);
        config(['mobile.desktop_runtime'=>false]);
        $this->get('/mobile/bootstrap')->assertNotFound();
    }

    public function test_android_handoff_is_still_localhost_and_desktop_entry_does_not_accept_an_arbitrary_origin(): void
    {
        config(['mobile.desktop_origin'=>'http://192.168.10.2:8003']);
        $state=Str::random(64);$tech=User::factory()->create(['role'=>'tecnico','active'=>true,'must_change_password'=>false]);
        $this->actingAs($tech)->get('/native/entry?state='.$state.'&device=desktop')->assertRedirect('/')->assertSessionHas('native_app_device','desktop');
        $this->get('/native/entry?state='.$state.'&device=https://other.example')->assertRedirect('/')->assertSessionMissing('native_app_device');
        $r=$this->post('/native/offline')->assertOk();$this->assertStringStartsWith('http://127.0.0.1/mobile/handoff?',$r->viewData('deviceUrl'));
    }
}
