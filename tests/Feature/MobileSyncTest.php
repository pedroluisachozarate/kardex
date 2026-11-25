<?php

namespace Tests\Feature;

use App\Livewire\MobileWorkspace;
use App\Models\Client;
use App\Models\Intervention;
use App\Models\MobileDraft;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use App\Services\MobileGateway;
use App\Services\SyncMobileDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MobileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_gateway_only_allows_https_or_explicit_private_wifi_http(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $gateway = new MobileGateway;
        foreach (['http://example.com', 'http://192.168.10.2:8002', 'ftp://192.168.10.2', 'https://user:pass@example.com'] as $url) {
            config(['mobile.server_url' => $url, 'mobile.allow_local_http' => false]);
            try {
                $gateway->request();
                $this->fail('An unapproved server URL was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        config(['mobile.allow_local_http' => true]);
        foreach (['http://example.com', 'http://8.8.8.8', 'ftp://192.168.10.2'] as $url) {
            config(['mobile.server_url' => $url]);
            try {
                $gateway->request();
                $this->fail('A non-private HTTP server was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        foreach (['https://example.com', 'http://192.168.10.2:8002', 'http://10.0.0.2', 'http://172.16.0.2'] as $url) {
            config(['mobile.server_url' => $url]);
            $this->assertTrue($gateway->request()->get('catalog')->successful());
        }
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'tecnico', 'active' => true, 'must_change_password' => false, 'password' => 'Secret12345']);
        $project = Project::create(['client_id' => Client::create(['name' => 'Entidad'])->id, 'name' => 'Proyecto', 'description' => 'Servicio']);
        $project->users()->attach($user);
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $auth = $this->postJson('/api/mobile/login', ['email' => $user->email, 'password' => 'Secret12345'])->assertOk()->json();
        $headers = ['Authorization' => 'Bearer '.$auth['token']];
        $catalog = $this->getJson('/api/mobile/catalog', $headers)->assertOk()->json();
        $payload = ['uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'template_id' => $template->id, 'template_version' => $catalog['projects'][0]['templates'][0]['version'], 'type' => 'preventivo', 'date' => '2026-11-21', 'technical' => [], 'activities' => [['result' => 'pendiente']]];

        return [$user, $project, $template, $headers, $payload];
    }

    public function test_sync_is_idempotent_and_server_assigns_codes_and_photo_receipts(): void
    {
        Storage::fake('local');
        [$user,$project,$template,$headers,$payload] = $this->fixture();
        $response = $this->postJson('/api/mobile/drafts', $payload, $headers)->assertOk()->assertJsonPath('kardex', 'AA-001')->assertJsonPath('status', 'borrador')->json();
        $this->postJson('/api/mobile/drafts', $payload, $headers)->assertOk()->assertJsonPath('intervention_id', $response['intervention_id']);
        $this->assertSame(1, Intervention::count());
        $this->assertSame(2, $project->fresh()->next_kardex);
        $uuid = (string) Str::uuid();
        foreach ([1, 2] as $attempt) {
            $this->post('/api/mobile/drafts/'.$response['intervention_id'].'/photos', ['uuid' => $uuid, 'activity_index' => 0, 'photo' => UploadedFile::fake()->image('photo.jpg')], $headers)->assertOk();
        }
        $this->assertSame(1, Photo::count());
        $this->assertNotNull(Intervention::first()->reviewValidationMessage());
        $this->postJson('/api/mobile/drafts/'.$response['intervention_id'].'/complete', ['photos' => [(string) Str::uuid()]], $headers)->assertStatus(409);
        $this->postJson('/api/mobile/drafts/'.$response['intervention_id'].'/complete', ['photos' => [$uuid]], $headers)->assertOk();
        $this->assertTrue((bool) Intervention::first()->mobile_sync_complete);
        $this->postJson('/api/mobile/logout', [], $headers)->assertNoContent();
        $this->getJson('/api/mobile/catalog', $headers)->assertUnauthorized();
    }

    public function test_revoked_access_and_template_changes_preserve_server_data(): void
    {
        [$user,$project,$template,$headers,$payload] = $this->fixture();
        $template->update(['preventive' => ['Otra actividad']]);
        $this->postJson('/api/mobile/drafts', $payload, $headers)->assertStatus(409);
        $this->assertSame(0, Intervention::count());
        $project->users()->detach($user);
        $this->postJson('/api/mobile/drafts', $payload, $headers)->assertNotFound();
        $this->getJson('/api/mobile/catalog', $headers)->assertOk()->assertJsonCount(0, 'projects');
        $user->update(['active' => false]);
        $this->getJson('/api/mobile/catalog', $headers)->assertForbidden();
    }

    public function test_no_connection_keeps_local_draft_and_photos_then_retries_without_duplicates(): void
    {
        Storage::fake('local');
        config(['mobile.server_url' => 'https://kardex.example', 'mobile.enabled' => true]);
        $id = (string) Str::uuid();
        $photoUuid = (string) Str::uuid();
        Storage::disk('local')->put('mobile-local/test.jpg', 'photo');
        $draft = MobileDraft::create(['id' => $id, 'owner_id' => 3, 'payload' => ['project_id' => 1], 'photos' => [['uuid' => $photoUuid, 'activity_index' => 0, 'name' => 'test.jpg', 'path' => 'mobile-local/test.jpg']]]);
        $connected = false;
        $receipt = ['uuid' => $id, 'intervention_id' => 7, 'kardex' => 'AA-001'];
        Http::fake(function ($request) use (&$connected, $receipt, $photoUuid) {
            if (! $connected) {
                throw new ConnectionException('Offline');
            }

            if (str_ends_with($request->url(), '/complete')) {
                return Http::response(['complete' => true, 'intervention_id' => 7]);
            }

            return str_ends_with($request->url(), '/photos') ? Http::response(['uuid' => $photoUuid, 'photo_id' => 9]) : Http::response($receipt);
        });
        try {
            app(SyncMobileDraft::class)->run($draft, 'token');
            $this->fail('Expected connection failure');
        } catch (ConnectionException $e) {
        }
        $this->assertSame('local', $draft->fresh()->sync_status);
        Storage::disk('local')->assertExists('mobile-local/test.jpg');
        $receipt = ['uuid' => $id, 'intervention_id' => 7, 'kardex' => 'AA-001'];
        $connected = true;
        app(SyncMobileDraft::class)->run($draft, 'token');
        $this->assertSame('synced', $draft->fresh()->sync_status);
        $this->assertSame(9, $draft->fresh()->photos[0]['receipt']['photo_id']);
        Http::assertSentCount(3);
        app(SyncMobileDraft::class)->run($draft, 'token');
        Http::assertSentCount(3);
    }

    public function test_local_editor_saves_without_contacting_server_and_is_hidden_on_web(): void
    {
        $this->get('/mobile')->assertNotFound();
        config(['mobile.enabled' => true]);
        Http::preventStrayRequests();
        $catalog = [['id' => 1, 'name' => 'Proyecto', 'templates' => [['id' => 2, 'name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => [], 'version' => str_repeat('a', 64)]]]];
        $this->withSession(['mobile.owner' => 42, 'mobile.catalog' => $catalog]);
        Livewire::test(MobileWorkspace::class)->call('newDraft')->set('form.project_id', 1)->set('form.template_id', 2)->call('save')->assertHasNoErrors();
        $this->assertSame(1, MobileDraft::where('owner_id', 42)->count());
        $this->assertSame('local', MobileDraft::first()->sync_status);
        Http::assertNothingSent();
    }
}
