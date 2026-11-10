<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Intervention;
use App\Models\Location;
use App\Models\Photo;
use App\Models\Project;
use App\Models\ProjectEquipment;
use App\Models\Template;
use App\Models\User;
use App\Services\KardexPdf;
use App\Services\OptimizedPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KardexTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_can_enter_or_clear_optional_location(): void
    {
        $project = $this->project();
        $asset = $this->asset($project);
        $tech = $this->user('tecnico');
        $project->users()->attach($tech);
        $this->actingAs($tech)->post(route('interventions.store', $asset), ['type' => 'preventivo', 'date' => '2026-10-17'])->assertRedirect();
        $i = Intervention::firstOrFail();
        $this->assertNull($i->location_text);
        $this->patch(route('interventions.update', $i), ['date' => '2026-10-17', 'location_text' => 'Edificio central, piso 2'])->assertRedirect();
        $this->assertSame('Edificio central, piso 2', $i->fresh()->location_text);
        $this->get(route('interventions.print', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdfData = app(KardexPdf::class)->data($i->fresh());
        $this->assertSame('Edificio central, piso 2', $pdfData['record']['location_text']);
        $html = view('print', $pdfData)->render();
        $this->assertStringContainsString('RAM', $html);
        $this->assertStringNotContainsString('Marca / Modelo', $html);
        $this->assertStringNotContainsString('Actividad y procedimiento', $html);
        $pdf = app(KardexPdf::class)->render($i->fresh());
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        $this->assertEquals([0, 0, 612, 792], $pdf->getDomPDF()->getPaperSize());
        $this->patch(route('interventions.update', $i), ['date' => '2026-10-17', 'location_text' => ''])->assertRedirect();
        $this->assertNull($i->fresh()->location_text);
        $this->get(route('interventions.print', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'active' => true, 'must_change_password' => false]);
    }

    public function test_template_creation_route_accepts_a_new_template(): void
    {
        $project = $this->project();
        $this->actingAs($this->user())->post(route('templates.store', $project), [
            'name' => 'Aire acondicionado', 'fields' => 'Capacidad|numero|BTU|si|',
            'preventive' => 'Limpieza',
        ])->assertRedirect(route('projects.show', $project));
        $this->assertSame(1, $project->templates()->count());
    }

    public function test_photo_limit_counts_previous_batches_and_missing_files_return_404(): void
    {
        Storage::fake('local');
        $asset = $this->asset($this->project());
        $tech = $this->user('tecnico');
        $asset->project->users()->attach($tech);
        $this->actingAs($tech)->post(route('interventions.store', $asset), ['type' => 'preventivo', 'date' => '2026-10-17']);
        $activity = Intervention::firstOrFail()->activities()->firstOrFail();
        for ($index = 0; $index < 10; $index++) {
            $activity->photos()->create(['path' => "missing-$index.png", 'uploaded_by' => auth()->id()]);
        }
        $this->post(route('photos.store', $activity), ['photos' => [UploadedFile::fake()->image('extra.png')]])
            ->assertSessionHasErrors('photos');
        $this->assertSame(10, $activity->photos()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->get(route('photos.show', $activity->photos()->firstOrFail()))->assertNotFound();
    }

    private function project(): Project
    {
        $c = Client::create(['name' => 'Cliente']);

        return Project::create(['client_id' => $c->id, 'name' => 'Proyecto', 'description' => 'Mantenimiento']);
    }

    private function asset(Project $p): ProjectEquipment
    {
        $l = Location::create(['client_id' => $p->client_id, 'building' => 'Sede']);
        $t = Template::create(['project_id' => $p->id, 'name' => 'Computadora', 'fields' => [], 'preventive' => ['Limpieza', 'Prueba'], 'corrective' => ['Diagnóstico']]);
        $e = Equipment::create(['name' => 'PC', 'serial' => 'SERIE-1']);

        return ProjectEquipment::create(['project_id' => $p->id, 'template_id' => $t->id, 'equipment_id' => $e->id, 'number' => 1, 'technical_data' => ['RAM' => ['value' => 16, 'unit' => 'GB']]]);
    }

    public function test_private_routes_and_account_activation(): void
    {
        $this->get('/')->assertRedirect('/login');
        $u = $this->user();
        $u->update(['must_change_password' => true]);
        $this->actingAs($u)->get('/')->assertRedirect('/password');
        $u->update(['active' => false]);
        $this->get('/password')->assertRedirect('/login');
    }

    public function test_password_validation_is_in_spanish(): void
    {
        $user = $this->user();
        $user->update(['password' => 'Actual12345']);
        $this->actingAs($user)->post(route('password.update'), [
            'current_password' => 'Actual12345', 'password' => '12345678901', 'password_confirmation' => '12345678901',
        ])->assertSessionHasErrors(['password' => 'La nueva contraseña debe contener al menos una letra.']);
        $this->post(route('password.update'), [
            'current_password' => 'Actual12345', 'password' => 'abcdefghijk', 'password_confirmation' => 'abcdefghijk',
        ])->assertSessionHasErrors(['password' => 'La nueva contraseña debe contener al menos un número.']);
    }

    public function test_roles_and_project_boundaries(): void
    {
        $p = $this->project();
        $a = $this->asset($p);
        $u = $this->user('tecnico');
        $this->actingAs($u)->get(route('projects.show', $p))->assertForbidden();
        $this->post(route('interventions.store', $a), ['type' => 'preventivo', 'date' => '2026-10-17'])->assertForbidden();
        $p->users()->attach($u);
        $this->get(route('projects.show', $p))->assertOk();
        $this->get(route('administration'))->assertForbidden();
        $this->post(route('templates.store', $p), ['name' => 'Unauthorized'])->assertForbidden();
        $u->update(['role' => 'consulta']);
        $this->post(route('interventions.store', $a), ['type' => 'preventivo', 'date' => '2026-10-17'])->assertForbidden();
    }

    public function test_numbering_separates_interventions_and_shares_equipment(): void
    {
        $p = $this->project();
        $a = $this->asset($p);
        $tech = $this->user('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        foreach (['preventivo', 'correctivo', 'preventivo'] as $type) {
            $this->post(route('interventions.store', $a), ['type' => $type, 'date' => '2026-10-17'])->assertRedirect();
        }
        $this->assertSame([1, 2, 3], $a->interventions()->pluck('number')->all());
        $this->assertSame(1, Equipment::count());
        $this->assertSame(5, Activity::count());
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('Resumen')
            ->assertSee('href="'.route('administration', ['tab' => 'proyectos']).'"', false)
            ->assertSee('Crear proyecto')
            ->assertDontSee("'proyectos']) }}");
        $this->get(route('interventions.show', Intervention::first()))->assertOk();
        $this->get(route('interventions.print', Intervention::first()))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_review_requires_complete_results_and_freezes_finalized_record(): void
    {
        $p = $this->project();
        $a = $this->asset($p);
        $admin = $this->user();
        $tech = $this->user('tecnico');
        $supervisor = $this->user('supervisor');
        $p->users()->attach([$tech->id, $supervisor->id]);
        $this->actingAs($tech)->post(route('interventions.store', $a), ['type' => 'preventivo', 'date' => '2026-10-17']);
        $i = Intervention::first();
        $this->post(route('interventions.transition', $i), ['status' => 'revision'])->assertSessionHasErrors('review');
        $this->patch(route('interventions.update', $i), ['date' => '2026-10-17', 'conclusions' => 'Operativo', 'fiscal' => 'Fiscal', 'agent' => 'Técnico'])->assertRedirect();
        foreach ($i->activities as $activity) {
            $this->patch(route('activities.update', $activity), ['result' => 'realizado', 'performed_on' => '2026-10-17'])->assertRedirect();
        }
        $this->assertSame(100, $i->fresh()->progress);
        $this->post(route('interventions.transition', $i), ['status' => 'revision'])->assertRedirect();
        $this->post(route('interventions.transition', $i), ['status' => 'finalizado'])->assertForbidden();
        $this->patch(route('interventions.update', $i), ['date' => '2026-10-17'])->assertStatus(409);
        $this->actingAs($supervisor)->post(route('interventions.transition', $i), ['status' => 'finalizado'])->assertRedirect();
        $this->assertSame('PC', $i->fresh()->snapshot['equipment']['name']);
        $a->equipment->update(['name' => 'Nombre cambiado']);
        $this->get(route('interventions.print', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post(route('interventions.transition', $i), ['status' => 'borrador', 'review_note' => 'Corregir'])->assertForbidden();
        $this->actingAs($admin)->post(route('interventions.transition', $i), ['status' => 'observado', 'review_note' => 'Corregir'])->assertForbidden();
        $this->actingAs($supervisor)->post(route('interventions.transition', $i), ['status' => 'observado'])->assertSessionHasErrors('review_note');
        $this->post(route('interventions.transition', $i), ['status' => 'observado', 'review_note' => 'Corregir'])->assertRedirect();
        $this->assertSame('observado', $i->fresh()->status);
        $this->assertNull($i->fresh()->finalized_at);
        $this->assertNull($i->fresh()->snapshot);
    }

    public function test_legacy_equipment_registration_is_retired(): void
    {
        $p = $this->project();
        $this->actingAs($this->user())->post(route('assets.store', $p), [])->assertForbidden();
        $tech = $this->user('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech)->post(route('assets.store', $p), [])->assertStatus(410);
        $this->get(route('assets.create', $p))->assertRedirect(route('projects.show', $p));
        $this->assertSame(0, $p->assets()->count());
    }

    public function test_photo_access_is_scoped_and_optional_for_completion(): void
    {
        Storage::fake('local');
        $p = $this->project();
        $a = $this->asset($p);
        $admin = $this->user();
        $tech = $this->user('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech)->post(route('interventions.store', $a), ['type' => 'preventivo', 'date' => '2026-10-17']);
        $activity = Intervention::first()->activities->first();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $this->post(route('photos.store', $activity), ['photos' => [UploadedFile::fake()->createWithContent('evidence.png', $png)]])->assertRedirect();
        $photo = Photo::firstOrFail();
        Storage::disk('local')->assertExists($photo->path);
        $this->get(route('photos.show', $photo))->assertOk();
        $this->actingAs($this->user('consulta'))->get(route('photos.show', $photo))->assertForbidden();
    }

    public function test_thumbnails_are_small_cached_and_private(): void
    {
        Storage::fake('local');
        $project = $this->project();
        $asset = $this->asset($project);
        $tech = $this->user('tecnico');
        $project->users()->attach($tech);
        $record = $asset->interventions()->create(['created_by' => $tech->id, 'number' => 1, 'type' => 'preventivo', 'date' => now(), 'status' => 'borrador']);
        $activity = $record->activities()->create(['name' => 'Limpieza']);
        $image = UploadedFile::fake()->image('original.jpg', 1200, 800);
        $path = $image->store('photos', 'local');
        $original = Storage::disk('local')->get($path);
        $photo = $activity->photos()->create(['path' => $path, 'uploaded_by' => $tech->id]);
        $this->actingAs($tech)->get(route('photos.thumbnail', $photo))->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->getJson(route('interventions.photos', $record))->assertOk()->assertJsonCount(1)->assertJsonPath('0.thumbnail', route('photos.thumbnail', $photo));
        $thumbs = Storage::disk('local')->files('photo-thumbnails');
        $this->assertCount(1, $thumbs);
        $bytes = Storage::disk('local')->get($thumbs[0]);
        $size = getimagesizefromstring($bytes);
        $this->assertSame(240, $size[0]);
        $this->assertSame(160, $size[1]);
        $this->get(route('photos.thumbnail', $photo))->assertOk();
        $this->assertSame($thumbs, Storage::disk('local')->files('photo-thumbnails'));
        Storage::disk('local')->assertMissing($path);
        $this->assertNotNull($photo->fresh()->optimized_at);
        $this->get(route('projects.show', $project))->assertOk()->assertSee(route('photos.thumbnail', $photo), false);
        $this->actingAs($this->user('tecnico'))->get(route('photos.thumbnail', $photo))->assertForbidden();
        $this->getJson(route('interventions.photos', $record))->assertForbidden();
        Storage::disk('local')->delete($photo->fresh()->path);
        $this->actingAs($tech)->get(route('photos.thumbnail', $photo))->assertNotFound();
    }

    public function test_gallery_uses_verified_optimized_photos_removing_originals_and_preserving_permissions(): void
    {
        Storage::fake('local');
        $project = $this->project();
        $asset = $this->asset($project);
        $tech = $this->user('tecnico');
        $project->users()->attach($tech);
        $record = $asset->interventions()->create(['created_by' => $tech->id, 'number' => 1, 'type' => 'preventivo', 'date' => now(), 'status' => 'borrador']);
        $activity = $record->activities()->create(['name' => 'Limpieza']);
        $path = UploadedFile::fake()->image('camera.jpg', 2400, 1600)->store('photos', 'local');
        $original = Storage::disk('local')->get($path);
        $photo = $activity->photos()->create(['path' => $path, 'uploaded_by' => $tech->id]);
        $this->actingAs($tech)->get(route('photos.optimized', $photo))->assertOk();
        $optimized = app(OptimizedPhoto::class)->path($photo);
        $size = getimagesize($optimized);
        $this->assertSame(1920, $size[0]);
        $this->assertSame(1280, $size[1]);
        $this->assertSame('image/webp', $size['mime']);
        $this->assertLessThan(strlen($original), filesize($optimized));
        Storage::disk('local')->assertMissing($path);
        $this->assertNotNull($photo->fresh()->optimized_at);
        $this->assertCount(1, Storage::disk('local')->files('photo-optimized'));
        $this->get(route('photos.optimized', $photo))->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('photo-optimized'));
        $this->getJson(route('interventions.photos', $record))->assertOk()->assertJsonPath('0.url', route('photos.optimized', $photo));
        $this->actingAs($this->user('tecnico'))->get(route('photos.optimized', $photo))->assertForbidden();
        $this->getJson(route('interventions.photos', $record))->assertForbidden();
        Storage::disk('local')->delete($photo->fresh()->path);
        $this->actingAs($tech)->get(route('photos.optimized', $photo))->assertNotFound();
    }

    public function test_failed_conversion_keeps_original_and_retries_when_viewed(): void
    {
        Storage::fake('local');
        $service = new class extends OptimizedPhoto
        {
            public bool $fail = true;

            protected function generate(Photo $photo): string
            {
                if ($this->fail) {
                    throw new \RuntimeException('Simulated conversion failure');
                }

                return parent::generate($photo);
            }
        };
        $this->app->instance(OptimizedPhoto::class, $service);
        $project = $this->project();
        $asset = $this->asset($project);
        $tech = $this->user('tecnico');
        $project->users()->attach($tech);
        $record = $asset->interventions()->create(['created_by' => $tech->id, 'number' => 1, 'type' => 'preventivo', 'date' => now(), 'status' => 'borrador']);
        $activity = $record->activities()->create(['name' => 'Limpieza']);
        $path = UploadedFile::fake()->image('camera.jpg', 2400, 1600)->store('photos', 'local');
        $photo = $activity->photos()->create(['path' => $path, 'uploaded_by' => $tech->id]);
        Storage::disk('local')->assertExists($path);
        $this->assertNull($photo->fresh()->optimized_at);
        $this->actingAs($tech)->get(route('photos.optimized', $photo))->assertOk();
        $this->assertSame($path, $photo->fresh()->path);
        $service->fail = false;
        $this->get(route('photos.optimized', $photo))->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertNotNull($photo->fresh()->optimized_at);
        $this->assertNotSame($path, $photo->fresh()->path);
        Storage::disk('local')->assertExists($photo->fresh()->path);
        $record->update(['status' => 'finalizado', 'snapshot' => ['activities' => [['name' => 'Limpieza', 'result' => 'realizado', 'photos' => [['id' => $photo->id, 'path' => $path]]]]]]);
        $this->assertNotEmpty(app(KardexPdf::class)->data($record->fresh())['photos'][0]['source']);
    }
}
