<div class="kardex-editor compact-registration">
    <div class="sheet-tools">
        <label>Tipo de equipo <span class="required-mark" aria-label="obligatorio">*</span><select :disabled="uploads > 0 || {{ $editingIntervention ? 'true' : 'false' }}" wire:model.live="form.template_id"><option value="">Seleccionar</option>@foreach($project->templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
        <label>Mantenimiento <span class="required-mark" aria-label="obligatorio">*</span><select :disabled="uploads > 0 || {{ $editingIntervention ? 'true' : 'false' }}" wire:model.live="form.type"><option value="preventivo">Preventivo</option><option value="correctivo">Correctivo</option></select></label>
        @unless($editingIntervention)<span class="muted small">Completa la hoja y guarda el borrador para continuar después.</span>@endunless
    </div>
    @if($editingIntervention?->status === 'observado' && $editingIntervention->review_note)<div class="review-feedback"><strong>Correcciones solicitadas</strong><p>{{ $editingIntervention->review_note }}</p></div>@endif
    <article class="kardex-sheet" aria-label="Registro completo del Kardex">
        <div class="sheet-heading"><strong>Kardex de mantenimiento</strong><div>
            @if($editingIntervention)
                Kardex {{ $editingIntervention->asset->kardex_code }} · Mantenimiento {{ sprintf('%03d', $editingIntervention->number) }} · {{ $editingIntervention->status_label }}
            @else
                N.º automático · Borrador
            @endif
            <label>Fecha <span class="required-mark" aria-label="obligatorio">*</span><input type="date" wire:model.live="form.date"></label></div></div>
        <h3>Datos del proyecto</h3>
        <div class="sheet-project"><div><b>Entidad:</b> {{ $project->client->name }}</div><div><b>Proyecto:</b> {{ $project->name }}</div></div>
        <h3>Ficha técnica del equipo</h3>
        @if($selectedType)
        <div class="grid two sheet-inputs technical-inputs" wire:key="technical-{{ $selectedType->id }}">@foreach(collect($selectedType->fields)->sortBy(fn ($field, $index) => ($column = \App\Models\Template::identityColumn($field['name'])) ? array_search($column, ['name','brand','model','serial','code']) : 5 + $index) as $key=>$field)<label>{{ \Illuminate\Support\Str::ucfirst(mb_strtolower($field['name'])) }} {{ $field['unit']?'('.$field['unit'].')':'' }}@if($field['required']) <span class="required-mark" aria-label="obligatorio">*</span>@endif@if($field['type']==='lista')<select wire:model.live.debounce.1000ms="technical.{{ $key }}"><option value="">Seleccionar</option>@foreach($field['options'] as $option)<option>{{ $option }}</option>@endforeach</select>@else<input type="{{ ['texto'=>'text','numero'=>'number','fecha'=>'date'][$field['type']] }}" step="any" wire:model.live.debounce.1000ms="technical.{{ $key }}">@endif</label>@endforeach<label>Ubicación (opcional)<input wire:model="form.location_text" placeholder="Sede, piso u oficina" maxlength="500"></label></div>
        @else<p class="sheet-project muted">Selecciona el tipo de equipo para cargar sus campos y actividades.</p>@endif


        <h3>Actividades del mantenimiento {{ $form['type']??'preventivo' }}</h3>
        @if($selectedType)
        @php($bucket=$selectedType->id.'_'.$form['type'])
        @php($lines=$form['type']==='preventivo'?$selectedType->preventive:$selectedType->corrective)
        <div wire:key="activities-{{ $bucket }}">
        @forelse($lines as $key=>$line)
        @php($parts=explode('|',$line,2))
        @php($savedPhotos=$editingIntervention?->activities[$key]?->photos ?? collect())
        <section class="compact-activity" x-data="{ expanded: false, activePhotoInput: null, photoBusy: false, photoProgress: 0, photoError: '' }" wire:key="activity-{{ $bucket }}-{{ $key }}">
            <div class="compact-activity-main">
                <span>{{ $loop->iteration }}</span><strong>{{ \Illuminate\Support\Str::ucfirst(mb_strtolower(trim($parts[0]))) }}</strong>
                <label><span class="sr-only">Estado de {{ trim($parts[0]) }}</span><select wire:model.live="activityData.{{ $bucket }}.{{ $key }}.result"><option value="pendiente">Pendiente</option><option value="realizado">Realizado</option><option value="no_realizado">No realizado</option><option value="no_aplica">No aplica</option></select></label>
                <button type="button" class="button secondary compact" @click="expanded = !expanded" :aria-expanded="expanded"><x-icon name="camera"/>Fotos / Observación · {{ (count($photoFiles[$bucket][$key]??[]) + $savedPhotos->count()) }}</button>
            </div>
            <div class="compact-activity-details technician-activity-details" x-show="expanded || $wire.activityData['{{ $bucket }}']?.[{{ $key }}]?.result === 'no_realizado'" x-cloak>
                <div class="technician-entry-row">
                    <label class="technician-note"><span class="sr-only">Observación {{ ($activityData[$bucket][$key]['result']??'')==='no_realizado'?'(obligatoria)':'(opcional)' }}</span><input wire:model.live.debounce.1000ms="activityData.{{ $bucket }}.{{ $key }}.notes" placeholder="{{ ($activityData[$bucket][$key]['result']??'')==='no_realizado'?'Motivo por el que no se realizó':'Observación (opcional)' }}" maxlength="10000"></label>
                    <label class="technician-date"><span class="sr-only">Fecha de ejecución</span><input type="date" aria-label="Fecha de ejecución" wire:model="activityData.{{ $bucket }}.{{ $key }}.performed_on"></label>
                    <div wire:ignore class="photo-upload-actions">
                        <label class="button secondary compact photo-add-button camera-capture-button" title="Tomar foto"><x-icon name="camera"/><span class="sr-only">Tomar foto</span><input aria-label="Tomar foto con la cámara del celular" x-on:livewire-upload-start="photoBusy = true; photoProgress = 0; photoError = ''" x-on:livewire-upload-progress="photoProgress = $event.detail.progress" x-on:livewire-upload-finish="photoBusy = false; $event.target.value = ''" x-on:livewire-upload-error="photoBusy = false; photoError = $event.detail.message || 'No se pudieron cargar las fotos. Intenta nuevamente.'" x-on:livewire-upload-cancel="photoBusy = false" :disabled="photoBusy" type="file" wire:key="camera-input-{{ $bucket }}-{{ $key }}" x-ref="activityCameraInput" x-on:change="activePhotoInput=$event.target; window.kardexUploadActivityPhotos($event.target, $wire, '{{ route('projects.photo-upload', $project, false) }}', '{{ $bucket }}', {{ $key }})" accept="image/*" capture="environment"></label>
                        <label class="button secondary compact photo-add-button"><x-icon name="file"/><span>Archivo</span><input aria-label="Elegir fotos de archivos" x-on:livewire-upload-start="photoBusy = true; photoProgress = 0; photoError = ''" x-on:livewire-upload-progress="photoProgress = $event.detail.progress" x-on:livewire-upload-finish="photoBusy = false; $event.target.value = ''" x-on:livewire-upload-error="photoBusy = false; photoError = $event.detail.message || 'No se pudieron cargar las fotos. Intenta nuevamente.'" x-on:livewire-upload-cancel="photoBusy = false" :disabled="photoBusy" type="file" wire:key="photos-input-{{ $bucket }}-{{ $key }}" x-ref="activityPhotoInput" x-on:change="activePhotoInput=$event.target; window.kardexUploadActivityPhotos($event.target, $wire, '{{ route('projects.photo-upload', $project, false) }}', '{{ $bucket }}', {{ $key }})" multiple accept="image/jpeg,image/png,image/webp"></label>
                    </div>
                </div>
                <div class="technician-upload-status"><span>{{ (count($photoFiles[$bucket][$key]??[]) + $savedPhotos->count()) }}/10 fotos · máximo 8 MB por foto</span><span x-show="photoBusy" x-cloak role="status"><span x-text="photoProgress < 100 ? 'Subiendo ' + photoProgress + '%' : 'Confirmando fotos…'"></span> <button x-show="photoProgress < 100" type="button" class="text-button" @click="window.kardexCancelPhotoUpload(activePhotoInput)"><x-icon name="close"/>Cancelar</button></span></div>
                <small x-show="photoError" x-text="photoError" x-cloak class="field-error" role="alert"></small>
                @error('incomingPhotos.'.$bucket.'.'.$key)<small class="field-error" role="alert">{{ $message }}</small>@enderror
                <div class="activity-thumbnails" aria-label="Fotos seleccionadas de {{ trim($parts[0]) }}">@foreach($savedPhotos as $savedPhoto)<figure class="pending-photo" wire:key="saved-photo-{{ $savedPhoto->id }}"><a href="{{ route('photos.optimized', $savedPhoto) }}" target="_blank"><img src="{{ route('photos.thumbnail', $savedPhoto) }}" alt="Foto guardada de {{ trim($parts[0]) }}"></a><figcaption>Guardada</figcaption></figure>@endforeach@foreach($photoFiles[$bucket][$key]??[] as $photo)<figure class="pending-photo" wire:key="pending-photo-{{ $bucket }}-{{ $key }}-{{ $photo->getFilename() }}">@if($photo->isPreviewable())<img src="{{ $photo->temporaryUrl() }}" alt="Foto de {{ trim($parts[0]) }}">@endif<button type="button" class="remove-photo" :disabled="photoBusy" wire:loading.attr="disabled" wire:click="removePendingPhoto('{{ $bucket }}', {{ $key }}, '{{ $photo->getFilename() }}')" aria-label="Quitar foto {{ $photo->getClientOriginalName() }}"><x-icon name="close"/></button><figcaption>{{ $photo->getClientOriginalName() }}</figcaption></figure>@endforeach</div>            </div>
        </section>        @empty<p class="sheet-project muted">Este tipo no tiene actividades {{ $form['type']==='preventivo'?'preventivas':'correctivas' }} configuradas.</p>@endforelse
        </div>
        @else<p class="sheet-project muted">Las actividades aparecerán al seleccionar el tipo de equipo.</p>@endif
        <h3>Resultados</h3>
        <div class="sheet-inputs result-inputs"><label>Observaciones generales<textarea wire:model="form.observations" rows="2"></textarea></label><div class="result-pair"><label>Conclusiones <span class="required-mark" aria-label="obligatorio para enviar a revisión" title="Obligatorio para enviar a revisión">*</span><textarea wire:model.live.debounce.1000ms="form.conclusions" rows="2"></textarea></label><label>Recomendaciones<textarea wire:model="form.recommendations" rows="2"></textarea></label></div></div>


    </article>
</div>
