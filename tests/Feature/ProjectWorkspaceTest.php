<?php

namespace Tests\Feature;

use App\Livewire\ProjectWorkspace;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Intervention;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::create(['client_id' => Client::create(['name' => 'Cliente'])->id, 'name' => 'Proyecto', 'description' => 'Servicio']);
    }

    private function account(string $role): User
    {
        return User::factory()->create(['role' => $role, 'active' => true, 'must_change_password' => false]);
    }

    public function test_equipment_type_is_locked_once_a_kardex_exists_even_if_editor_was_open(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $this->actingAs($this->account('admin'));
        $editor = Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('open', 'type', $template->id)->set('form.name', 'Cambio no permitido');
        $project->assets()->create(['template_id' => $template->id, 'equipment_id' => Equipment::create(['name' => 'Cámara #AA-001'])->id, 'number' => 1, 'technical_data' => []]);
        $editor->call('saveType')->assertStatus(409);
        $this->assertSame('Cámara', $template->fresh()->name);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('selectSection', 'types')->assertSee('Editar')->call('open', 'type', $template->id)->assertSet('modal', 'type');
        $this->get(route('templates.edit', [$project, $template]))->assertOk();
        $this->patch(route('templates.update', [$project, $template]), ['name' => 'Cambio por URL'])->assertStatus(409);
        $this->assertSame('Cámara', $template->fresh()->name);
    }

    public function test_only_assigned_supervisor_can_delete_maintenance_and_last_deletion_removes_kardex(): void
    {
        Storage::fake('local');
        $p = $this->project();
        $template = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->call('saveAsset')->assertHasNoErrors();
        $first = Intervention::firstOrFail();
        $asset = $first->asset;
        $second = $asset->interventions()->create(['created_by' => $tech->id, 'number' => 2, 'type' => 'correctivo', 'date' => now(), 'status' => 'borrador']);
        $activity = $first->activities()->firstOrFail();
        Storage::disk('local')->put('evidence.jpg', 'photo');
        $activity->photos()->create(['path' => 'evidence.jpg', 'uploaded_by' => $tech->id]);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('deleteMaintenance', $first->id)->assertForbidden();
        $this->actingAs($this->account('admin'));
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('deleteMaintenance', $first->id)->assertForbidden();
        $supervisor = $this->account('supervisor');
        $p->users()->attach($supervisor);
        $this->actingAs($supervisor);
        $component = Livewire::test(ProjectWorkspace::class, ['project' => $p]);
        $component->call('deleteEmptyKardex', $asset->id)->assertHasErrors('delete');
        $component->call('deleteMaintenance', $first->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('interventions', ['id' => $first->id]);
        $this->assertDatabaseHas('project_equipment', ['id' => $asset->id]);
        $this->assertDatabaseHas('interventions', ['id' => $second->id]);
        Storage::disk('local')->assertMissing('evidence.jpg');
        $component->call('deleteMaintenance', $second->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('project_equipment', ['id' => $asset->id]);
        $this->assertDatabaseHas('equipment', ['id' => $asset->equipment_id]);
        $other = $this->project();
        $other->users()->attach($supervisor);
        Livewire::test(ProjectWorkspace::class, ['project' => $other])->call('deleteMaintenance', $first->id)->assertNotFound();
    }

    public function test_ordered_workflow_creates_type_equipment_and_kardex(): void
    {
        $this->actingAs($this->account('admin'));
        $p = $this->project();
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p])->assertSet('section', 'types')->assertSee('Nuevo tipo')
            ->call('open', 'type')->set('form.name', 'Aire')->call('addTemplateActivity', 'preventive')->set('templateActivities.preventive.0.name', 'Limpieza')->call('addTemplateActivity', 'corrective')->set('templateActivities.corrective.0.name', 'Diagnóstico')
            ->call('addField')->set('fields.0.name', 'Capacidad')->set('fields.0.type', 'numero')->set('fields.0.required', true)->call('saveType')->assertHasNoErrors()->assertDontSee('Siguiente:');
        $t = $p->templates()->firstOrFail();
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p]);
        $c->call('open', 'asset')->set('form.name', 'Cassette')->call('saveAsset', true)->assertHasErrors('technical.0')
            ->set('technical.0', 60000)->call('saveAsset')->assertHasNoErrors()->assertSet('section', 'equipment');
        $a = $p->assets()->firstOrFail();
        $this->assertSame(1, $a->number);
        $this->assertNull($a->location_id);
        $this->assertSame(1, $a->interventions()->count());
        $this->assertSame('borrador', $a->interventions()->firstOrFail()->status);
        $c->call('open', 'intervention', $a->id)->call('createIntervention')->assertRedirect(route('interventions.show', Intervention::latest('id')->firstOrFail()));
        $this->assertSame([1, 2], $a->interventions()->pluck('number')->all());
        $this->assertDatabaseHas('activities', ['name' => 'Limpieza']);
    }

    public function test_roles_and_foreign_records_are_rejected(): void
    {
        $p = $this->project();
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'type')->assertForbidden();
        Livewire::test(ProjectWorkspace::class, ['project' => $this->project()])->assertForbidden();
        $this->actingAs($this->account('admin'));
        $other = $this->project();
        $t = $other->templates()->create(['name' => 'Ajeno', 'fields' => [], 'preventive' => [], 'corrective' => []]);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'type', $t->id)->assertNotFound();
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->assertDontSee('Agregar equipo')->call('open', 'asset')->assertForbidden();
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('saveAsset')->assertForbidden();
        $this->post(route('assets.store', $p), [])->assertForbidden();
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->assertStatus(422);
    }

    public function test_base_fields_are_locked_and_cannot_be_added_as_custom_fields(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [
            ['name' => 'Nro Serie', 'type' => 'numero', 'unit' => 'x', 'required' => true, 'options' => []],
        ], 'preventive' => [], 'corrective' => []]);
        $this->assertCount(4, $t->fields);
        $this->assertSame('texto', $t->fields[0]['type']);
        $this->assertFalse($t->fields[0]['required']);
        $this->actingAs($this->account('admin'));
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'type', $t->id)
            ->assertSee('campo base no modificable')->assertSet('fields', [])
            ->call('addField')->set('fields.0.name', 'Numero de serie')
            ->call('saveType')->assertHasErrors('fields.0.name');
    }

    public function test_assigned_technician_fills_admin_defined_fields(): void
    {
        $p = $this->project();
        $p->templates()->create(['name' => 'Aire acondicionado', 'fields' => [
            ['name' => 'Marca', 'type' => 'texto', 'unit' => '', 'required' => true, 'options' => []],
            ['name' => 'Capacidad', 'type' => 'numero', 'unit' => 'BTU', 'required' => true, 'options' => []],
        ], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])
            ->assertSee('Agregar equipo')->call('open', 'asset')
            ->set('form.name', 'Aire recepción')->call('saveAsset', true)->assertHasErrors(['technical.1'])
            ->set('technical.0', 'Samsung')->set('technical.1', '60000')
            ->call('saveAsset')->assertHasNoErrors();
        $asset = $p->assets()->firstOrFail();
        $this->assertSame('Samsung', $asset->equipment->brand);
        $this->assertSame('60000', $asset->technical_data['Capacidad']['value']);
        $this->assertSame('BTU', $asset->technical_data['Capacidad']['unit']);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'type')->assertForbidden();

        $viewer = $this->account('consulta');
        $p->users()->attach($viewer);
        $this->actingAs($viewer);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->assertDontSee('Agregar equipo')->call('open', 'asset')->assertForbidden();
    }

    public function test_technician_cannot_link_equipment_from_an_unassigned_project(): void
    {
        $p = $this->project();
        $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => [], 'corrective' => []]);
        $foreign = Equipment::create(['name' => 'Equipo privado']);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->assertDontSee('Equipo privado')->set('form.equipment_id', $foreign->id)->call('saveAsset')->assertNotFound();
        $this->assertSame(0, $p->assets()->count());
    }

    public function test_equipment_form_only_shows_configured_fields_and_generates_a_name(): void
    {
        $p = $this->project();
        $p->templates()->create(['name' => 'Computadora', 'fields' => [
            ['name' => 'CPU', 'type' => 'numero', 'unit' => 'GHz', 'required' => true, 'options' => []],
        ], 'preventive' => [], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $component = Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->assertSee('Cpu')->assertDontSee('Nombre del equipo')->assertSee('Marca')
            ->assertSee('Modelo')->assertSee('N.º de serie')->assertSee('Código interno')
            ->set('form.brand', 'No configurada')->set('technical.0', '3.2')
            ->call('saveAsset')->assertHasNoErrors();
        $first = $p->assets()->firstOrFail();
        $this->assertSame('Computadora #AA-001', $first->equipment->name);
        $this->assertNull($first->equipment->brand);
        $component->call('open', 'asset')->set('technical.0', '2.4')->call('saveAsset')->assertHasNoErrors();
        $this->assertDatabaseHas('equipment', ['name' => 'Computadora #AA-002']);
    }

    public function test_legacy_equipment_name_field_is_hidden_and_name_is_generated(): void
    {
        $p = $this->project();
        $p->templates()->create(['name' => 'Aire', 'fields' => [
            ['name' => 'Nombre del equipo', 'type' => 'texto', 'unit' => '', 'required' => true, 'options' => []],
        ], 'preventive' => [], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->assertDontSee('Nombre del equipo')->call('saveAsset')->assertHasNoErrors();
        $this->assertSame('Aire #AA-001', $p->assets()->firstOrFail()->equipment->name);
    }

    public function test_single_sheet_saves_equipment_results_and_activity_photos_as_draft(): void
    {
        Storage::fake('local');
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza|Limpiar filtros'], 'corrective' => ['Reparación']]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $bucket = $t->id.'_preventivo';
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->assertSee('Registro completo del Kardex')->assertSee('Guardar borrador')
            ->set('form.location_text', 'Piso 2')->set('form.conclusions', 'Operativo')
            ->set("activityData.$bucket.0.result", 'realizado')
            ->set("activityData.$bucket.0.performed_on", '2026-10-17')
            ->set("activityData.$bucket.0.notes", 'Sin fallas')
            ->set("photoFiles.$bucket.0", [UploadedFile::fake()->createWithContent('filtro.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')), UploadedFile::fake()->createWithContent('despues.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))])
            ->call('saveAsset')->assertHasNoErrors()->assertSet('modal', '');
        $i = $p->assets()->firstOrFail()->interventions()->firstOrFail();
        $this->assertSame('borrador', $i->status);
        $this->assertSame('Piso 2', $i->location_text);
        $this->assertSame('Operativo', $i->conclusions);
        $activity = $i->activities()->firstOrFail();
        $this->assertSame('Limpiar filtros', $activity->procedure);
        $this->assertSame('realizado', $activity->result);
        $this->assertSame($tech->id, $activity->performed_by);
        $this->assertSame(2, $activity->photos()->count());
        Storage::disk('local')->assertExists($activity->photos()->firstOrFail()->path);
    }

    public function test_sheet_keeps_maintenance_types_separate_and_validates_before_creating_equipment(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => ['Reparación']]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->set('activityData.'.$t->id.'_preventivo.0.notes', 'Nota preventiva')
            ->set('form.type', 'correctivo')->set('form.fault', 'No enciende')
            ->set('activityData.'.$t->id.'_correctivo.0.result', 'incorrecto')
            ->call('saveAsset')->assertHasErrors('activityData.'.$t->id.'_correctivo.0.result');
        $this->assertSame(0, $p->assets()->count());
        $this->assertSame(0, Equipment::count());
        $c->set('activityData.'.$t->id.'_correctivo.0.result', 'pendiente')->call('saveAsset')->assertHasNoErrors();
        $i = Intervention::firstOrFail();
        $this->assertSame('correctivo', $i->type);
        $this->assertSame('No enciende', $i->fault);
        $this->assertSame('Reparación', $i->activities()->firstOrFail()->name);
        $this->assertNull($i->activities()->firstOrFail()->notes);
    }

    public function test_admin_edits_activity_rows_independently_without_losing_legacy_data(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza|Detalle anterior', 'Revisión'], 'corrective' => ['Reparación']]);
        $this->actingAs($this->account('admin'));
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'type', $t->id)
            ->call('removeTemplateActivity', 'preventive', 1)->call('addTemplateActivity', 'corrective')
            ->set('templateActivities.corrective.1.name', 'Cambio de filtro')->call('saveType')->assertHasNoErrors();
        $this->assertSame(['Limpieza|Detalle anterior'], $t->fresh()->preventive);
        $this->assertSame(['Reparación', 'Cambio de filtro'], $t->fresh()->corrective);
    }

    public function test_not_performed_requires_a_reason_and_pending_does_not_require_photos(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza', 'Revisión'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $bucket = $t->id.'_preventivo';
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->set("activityData.$bucket.0.result", 'no_realizado')->call('saveAsset')->assertHasErrors("activityData.$bucket.0.notes")
            ->set("activityData.$bucket.0.notes", 'Falta acceso al equipo')->call('saveAsset')->assertHasNoErrors();
        $i = Intervention::firstOrFail();
        $this->assertSame(['no_realizado', 'pendiente'], $i->activities()->pluck('result')->all());
        $this->assertSame(0, $i->activities->sum(fn ($a) => $a->photos()->count()));
        $activity = $i->activities()->firstOrFail();
        $this->patch(route('activities.update', $activity), ['result' => 'no_realizado', 'performed_on' => '2026-10-17'])->assertSessionHasErrors('notes');
    }

    public function test_execution_dates_are_defaulted_and_photos_accumulate_across_selections(): void
    {
        Storage::fake('local');
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza', 'Revisión'], 'corrective' => ['Reparación']]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $bucket = $t->id.'_preventivo';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->assertSet("activityData.$bucket.0.performed_on", now()->toDateString())
            ->assertSet("activityData.$bucket.1.performed_on", now()->toDateString())
            ->set("activityData.$bucket.0.performed_on", '2026-09-10')
            ->set("incomingPhotos.$bucket.0", [UploadedFile::fake()->createWithContent('primera.png', $png)])
            ->assertHasNoErrors()->assertSet("incomingPhotos.$bucket.0", [])
            ->set("incomingPhotos.$bucket.0", [UploadedFile::fake()->createWithContent('segunda.png', $png)])
            ->assertHasNoErrors();
        $this->assertCount(2, $c->get('photoFiles')[$bucket][0]);
        $firstFilename = $c->get('photoFiles')[$bucket][0][0]->getFilename();
        $secondFilename = $c->get('photoFiles')[$bucket][0][1]->getFilename();
        $c->call('removePendingPhoto', $bucket, 0, $firstFilename)->assertHasNoErrors();
        $this->assertCount(1, $c->get('photoFiles')[$bucket][0]);
        $this->assertSame($secondFilename, $c->get('photoFiles')[$bucket][0][0]->getFilename());
        $c->set('form.type', 'correctivo')->assertSet('activityData.'.$t->id.'_correctivo.0.performed_on', now()->toDateString())
            ->set('form.type', 'preventivo')->assertSet("activityData.$bucket.0.performed_on", '2026-09-10')
            ->call('saveAsset')->assertHasNoErrors();
        $activities = Intervention::firstOrFail()->activities;
        $this->assertSame('2026-09-10', $activities[0]->performed_on);
        $this->assertSame(1, $activities[0]->photos()->count());
        $this->assertSame(0, $activities[1]->photos()->count());
    }

    public function test_direct_photo_upload_confirms_a_batch_and_persists_images(): void
    {
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $response = $this->postJson(route('projects.photo-upload', $p), [
            'template_id' => $t->id, 'type' => 'preventivo', 'activity_index' => 0,
            'files' => [UploadedFile::fake()->createWithContent('una.png', $png), UploadedFile::fake()->createWithContent('dos.png', $png)],
        ])->assertOk()->assertJsonStructure(['token']);
        $token = $response->json('token');
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->call('acceptUploadedPhotos', $token)->assertReturned(['ok' => true])->assertHasNoErrors();
        $this->assertCount(2, $c->get('photoFiles')[$t->id.'_preventivo'][0]);
        $c->call('acceptUploadedPhotos', $token)->assertReturned(['ok' => true]);
        $this->assertCount(2, $c->get('photoFiles')[$t->id.'_preventivo'][0]);
        $c->call('saveAsset')->assertHasNoErrors();
        $photos = Intervention::firstOrFail()->activities()->firstOrFail()->photos;
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists($photo->path);
        }
        $otherTech = $this->account('tecnico');
        $p->users()->attach($otherTech);
        $this->actingAs($otherTech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->call('acceptUploadedPhotos', $token)->assertForbidden();
    }

    public function test_photo_upload_rejects_invalid_files_and_unassigned_users(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $this->actingAs($tech)->postJson(route('projects.photo-upload', $p), [])->assertForbidden();
        $p->users()->attach($tech);
        $this->postJson(route('projects.photo-upload', $p), [
            'template_id' => $t->id, 'type' => 'preventivo', 'activity_index' => 0,
            'files' => [UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')
            ->call('acceptUploadedPhotos', 'invalid-token')->assertReturned(['ok' => false, 'message' => 'La carga no es válida. Selecciona las fotos nuevamente.']);
    }

    public function test_existing_draft_uses_creation_sheet_and_updates_without_creating_duplicates(): void
    {
        Storage::fake('local');
        Storage::fake('tmp-for-tests');
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [
            ['name' => 'Marca', 'type' => 'texto', 'unit' => '', 'required' => true, 'options' => []],
        ], 'preventive' => ['Limpieza original'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->set('technical.0', 'Marca original')->call('saveAsset')->assertHasNoErrors();
        $i = Intervention::firstOrFail();
        $i->update(['measurements' => [['name' => 'Voltaje', 'value' => '220', 'unit' => 'V']], 'parts' => [['name' => 'Filtro', 'quantity' => 1, 'status' => 'pendiente']]]);
        $originalActivity = $i->activities()->firstOrFail();
        $originalPhoto = $originalActivity->photos()->create(['uploaded_by' => $tech->id, 'path' => 'photos/anterior.png']);
        $i->activities()->create(['name' => 'Actividad adicional']);
        $t->update(['preventive' => ['Otra actividad de la plantilla']]);
        $this->get(route('interventions.show', $i))->assertOk()->assertSee('Editar Kardex')->assertSee('Registro completo del Kardex');
        $bucket = $t->id.'_preventivo';
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i])
            ->assertSet('modal', 'edit')->assertSet('technical.0', 'Marca original')->assertSee('Actividad adicional')
            ->assertDontSee('Otra actividad de la plantilla')->assertSee('Guardada')
            ->set('technical.0', 'Marca corregida')->set('form.location_text', 'Piso 3')->set('form.conclusions', 'Operativo')
            ->set("activityData.$bucket.0.result", 'realizado');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $upload = $this->postJson(route('projects.photo-upload', $p), [
            'template_id' => $t->id, 'type' => 'preventivo', 'activity_index' => 1, 'intervention_id' => $i->id,
            'files' => [UploadedFile::fake()->createWithContent('nueva.png', $png)],
        ])->assertOk();
        $c->call('acceptUploadedPhotos', $upload->json('token'))->assertReturned(['ok' => true])
            ->call('saveEditedIntervention')->assertHasNoErrors()->assertSee('Cambios guardados.');
        $this->assertSame(1, Equipment::count());
        $this->assertSame(1, Intervention::count());
        $this->assertSame(2, $i->activities()->count());
        $this->assertSame('Piso 3', $i->fresh()->location_text);
        $this->assertSame('Marca corregida', $i->asset->fresh()->equipment->brand);
        $this->assertSame('220', $i->fresh()->measurements[0]['value']);
        $this->assertSame('Filtro', $i->fresh()->parts[0]['name']);
        $this->assertDatabaseHas('photos', ['id' => $originalPhoto->id, 'activity_id' => $originalActivity->id]);
        $newPhoto = $i->activities()->latest('id')->firstOrFail()->photos()->firstOrFail();
        Storage::disk('local')->assertExists($newPhoto->path);
        $this->assertSame('realizado', $originalActivity->fresh()->result);
        $this->assertSame(1, $i->fresh()->number);
        $this->assertSame(1, $i->asset->fresh()->number);
        $this->assertSame('borrador', $i->fresh()->status);
    }

    public function test_draft_editor_checks_scope_status_and_preserves_type(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->call('saveAsset');
        $i = Intervention::firstOrFail();
        Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i])
            ->set('form.type', 'correctivo')->call('saveEditedIntervention')->assertHasErrors('form.type');
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i]);
        $i->update(['status' => 'revision']);
        $c->call('saveEditedIntervention')->assertStatus(409);
        Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i])->assertSet('modal', 'view')->assertSee('Consultar Kardex');
        $i->update(['status' => 'borrador']);
        $other = $this->account('tecnico');
        $this->actingAs($other)->get(route('interventions.show', $i))->assertForbidden();
        $p->users()->attach($other);
        Livewire::test(ProjectWorkspace::class, ['project' => $this->project(), 'intervention' => $i])->assertForbidden();
        $viewer = $this->account('consulta');
        $p->users()->attach($viewer);
        $this->actingAs($viewer);
        $this->get(route('interventions.show', $i))->assertOk()->assertDontSee('Guardar cambios');
        Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i])->assertSet('modal', 'view')
            ->call('saveEditedIntervention')->assertForbidden();
    }

    public function test_editor_can_save_all_changes_and_send_to_review(): void
    {
        $p = $this->project();
        $t = $p->templates()->create(['name' => 'Aire', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $p->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $p])->call('open', 'asset')->call('saveAsset');
        $i = Intervention::firstOrFail();
        $c = Livewire::test(ProjectWorkspace::class, ['project' => $p, 'intervention' => $i]);
        $c->call('saveEditedIntervention', true)->assertHasErrors('review');
        $this->assertSame('borrador', $i->fresh()->status);
        $c->set('activityData.'.$t->id.'_preventivo.0.result', 'realizado')
            ->set('form.conclusions', 'Operativo')
            ->call('saveEditedIntervention', true)->assertHasNoErrors()->assertRedirect(route('interventions.show', $i));
        $this->assertSame('revision', $i->fresh()->status);
        $this->assertSame('realizado', $i->activities()->firstOrFail()->result);
    }

    public function test_saving_does_not_send_and_technician_explicitly_submits_corrections(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza', 'Soporte'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $supervisor = $this->account('supervisor');
        $project->users()->attach([$tech->id, $supervisor->id]);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'asset')
            ->call('saveCurrentMaintenance')->assertHasNoErrors();
        $record = Intervention::firstOrFail();
        $bucket = $template->id.'_preventivo';
        Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record])
            ->assertSee('Guardar borrador')->assertDontSee('Guardar cambios')
            ->set("activityData.$bucket.0.result", 'no_aplica')
            ->set("activityData.$bucket.1.result", 'no_realizado')
            ->set('form.conclusions', 'No fue posible intervenir')
            ->assertSee('Guardar borrador')
            ->set("activityData.$bucket.1.notes", 'No tiene tornillos')
            ->assertSee('Enviar a revisión')
            ->call('saveCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('borrador', $record->fresh()->status);
        Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])->call('sendCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('revision', $record->fresh()->status);
        $this->assertSame(1, $project->assets()->count());
        $this->post(route('interventions.transition', $record), ['status' => 'observado', 'review_note' => 'Corregir motivo'])->assertForbidden();
        $this->actingAs($supervisor)->post(route('interventions.transition', $record), ['status' => 'observado'])->assertSessionHasErrors('review_note');
        $this->post(route('interventions.transition', $record), ['status' => 'observado', 'review_note' => 'Precisar el motivo'])->assertRedirect();
        $this->assertSame('observado', $record->fresh()->status);
        $this->actingAs($tech)->get(route('interventions.show', $record))->assertOk()->assertSee('Precisar el motivo');
        Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])
            ->assertSee('Guardar correcciones')->assertSee('Reenviar a revisión')->call('saveCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('observado', $record->fresh()->status);
        Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])->call('sendCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('revision', $record->fresh()->status);
        $this->actingAs($supervisor)->post(route('interventions.transition', $record), ['status' => 'finalizado'])->assertRedirect();
        $this->assertSame('finalizado', $record->fresh()->status);
        $this->actingAs($tech)->patch(route('interventions.update', $record), ['date' => '2026-10-17'])->assertStatus(409);
    }

    public function test_new_maintenance_can_be_saved_and_sent_to_review_in_one_action(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Inspección'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $project->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'asset')
            ->set('activityData.'.$template->id.'_preventivo.0.result', 'no_aplica')
            ->set('form.conclusions', 'No corresponde a este equipo')
            ->assertSee('Enviar a revisión')->call('sendCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('revision', Intervention::firstOrFail()->status);
        $this->assertSame(1, $project->assets()->count());
    }

    public function test_role_permissions_and_buttons_in_each_kardex_state(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Inspección'], 'corrective' => []]);
        $tech = $this->account('tecnico');
        $project->users()->attach($tech);
        $this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'asset')->call('saveAsset')->assertHasNoErrors();
        $record = Intervention::firstOrFail();
        foreach (['admin', 'supervisor', 'consulta'] as $role) {
            $user = $this->account($role);
            $project->users()->attach($user);
            $this->actingAs($user);
            foreach (['borrador', 'observado', 'revision', 'finalizado'] as $status) {
                $record->update(['status' => $status]);
                $view = Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])
                    ->assertSet('modal', 'view')->assertDontSee('Guardar borrador')->assertDontSee('Guardar correcciones');
                if ($role === 'supervisor' && $status === 'revision') {
                    $view->assertSee('Aprobar y finalizar')->assertSee('Devolver con observaciones');
                } else {
                    $view->assertDontSee('Aprobar y finalizar')->assertDontSee('Devolver con observaciones');
                }
                if ($role === 'supervisor' && $status === 'finalizado') {
                    $view->assertSee('Reabrir Kardex');
                } else {
                    $view->assertDontSee('Reabrir Kardex');
                }
                Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'edit', $record->id)->assertForbidden();
                $this->patch(route('interventions.update', $record), ['date' => '2026-10-17'])->assertForbidden();
                $this->post(route('interventions.transition', $record), ['status' => 'revision'])->assertForbidden();
                $this->post(route('projects.photo-upload', $project), [])->assertForbidden();
                if ($role !== 'supervisor') {
                    $this->post(route('interventions.transition', $record), ['status' => 'finalizado'])->assertForbidden();
                    $this->post(route('interventions.transition', $record), ['status' => 'observado', 'review_note' => 'Cambio'])->assertForbidden();
                }
            }
            Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'intervention', $record->project_equipment_id)->assertForbidden();
            $this->post(route('interventions.store', $record->asset), ['type' => 'preventivo', 'date' => '2026-10-17'])->assertForbidden();
        }
        $this->actingAs($tech);
        foreach (['revision', 'finalizado'] as $status) {
            $record->update(['status' => $status]);
            Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])
                ->assertSet('modal', 'view')->assertDontSee('Aprobar y finalizar')->assertDontSee('Reabrir Kardex');
        }
        $record->update(['status' => 'borrador']);
        Livewire::test(ProjectWorkspace::class, ['project' => $project, 'intervention' => $record->fresh()])
            ->assertDontSee('Enviar a revisión')->call('sendCurrentMaintenance')->assertHasErrors('review');
        $this->assertSame('borrador', $record->fresh()->status);
        $outsider = $this->account('supervisor');
        $this->actingAs($outsider)->post(route('interventions.transition', $record), ['status' => 'finalizado'])->assertForbidden();
    }

    public function test_template_locks_only_activities_of_maintenance_types_already_created(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name' => 'Cámara', 'fields' => [], 'preventive' => ['Limpieza'], 'corrective' => []]);
        $admin = $this->account('admin');
        $asset = $project->assets()->create(['template_id' => $template->id, 'equipment_id' => Equipment::create(['name' => 'Cámara'])->id, 'number' => 1, 'technical_data' => []]);
        $preventive = $asset->interventions()->create(['created_by' => $admin->id, 'number' => 1, 'type' => 'preventivo', 'date' => now(), 'status' => 'borrador']);
        $preventive->activities()->create(['name' => 'Limpieza']);
        $empty = $asset->interventions()->create(['created_by' => $admin->id, 'number' => 2, 'type' => 'correctivo', 'date' => now(), 'status' => 'borrador']);
        $this->actingAs($admin);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'type', $template->id)
            ->call('addTemplateActivity', 'corrective')->set('templateActivities.corrective.0.name', 'Reemplazar soporte')
            ->call('saveType')->assertHasNoErrors();
        $this->assertSame(['Reemplazar soporte'], $template->fresh()->corrective);
        $this->assertSame(['Reemplazar soporte'], $empty->activities()->pluck('name')->all());
        $this->assertSame(['Limpieza'], $template->fresh()->preventive);
        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'type', $template->id)->assertStatus(409);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])->call('open', 'type', $template->id)->assertStatus(409);
        $this->assertSame(['Reemplazar soporte'], $template->fresh()->corrective);
    }
    public function test_draft_accepts_missing_required_fields_but_submission_requires_them(): void
    {
        $project = $this->project();
        $template = $project->templates()->create(['name'=>'Cámara','fields'=>[['name'=>'Megapíxeles','type'=>'numero','unit'=>'Mpx','required'=>true,'options'=>[]]],'preventive'=>['Limpieza'],'corrective'=>[]]);
        $tech=$this->account('tecnico');$project->users()->attach($tech);$this->actingAs($tech);
        Livewire::test(ProjectWorkspace::class,['project'=>$project])->call('open','asset')->call('saveCurrentMaintenance')->assertHasNoErrors();
        $record=Intervention::firstOrFail();
        $editor=Livewire::test(ProjectWorkspace::class,['project'=>$project,'intervention'=>$record]);
        $editor->set('form.conclusions','Operativo')->set('activityData.'.$template->id.'_preventivo.0.result','realizado')
            ->call('saveCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('borrador',$record->fresh()->status);
        $editor->call('saveEditedIntervention',true)->assertHasErrors('technical.0');
        $this->assertSame('borrador',$record->fresh()->status);
        $editor->set('technical.0',2)->call('sendCurrentMaintenance')->assertHasNoErrors();
        $this->assertSame('revision',$record->fresh()->status);
    }

}
