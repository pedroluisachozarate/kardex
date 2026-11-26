<div class="kardex-editor compact-designer">
    @php($fieldsLocked = $designerType?->hasKardex() ?? false)
    <article class="kardex-sheet" aria-label="Diseño completo del Kardex">
        <div class="sheet-project"><div><span>Entidad:</span> {{ $project->client->name }}</div><div><span>Proyecto:</span> {{ $project->name }}</div></div>
        <h3>Ficha técnica del equipo</h3>
        <label class="designer-equipment-type">Tipo de equipo<input wire:model="form.name" @disabled($fieldsLocked) placeholder="Ej.: Aire acondicionado" @class(['field-invalid' => $errors->has('form.name')]) aria-invalid="{{ $errors->has('form.name') ? 'true' : 'false' }}">@error('form.name')<small class="field-error">{{ $message }}</small>@enderror</label>
        <fieldset @disabled($fieldsLocked)>
        <div class="base-field-list">@foreach(\App\Models\Template::baseFields() as $base)<label>{{ $base['name'] }}<input type="text" value="Texto · Campo base" disabled aria-label="{{ $base['name'] }}: campo base no modificable"></label>@endforeach</div>
        <div class="sheet-fields">
            @foreach($fields as $key=>$field)
            <div class="designer-field-row" wire:key="sheet-field-{{ $key }}">
                <label class="designer-field-name">Campo<input wire:model="fields.{{ $key }}.name" placeholder="Ej.: Capacidad" @class(['field-invalid' => $errors->has('fields.'.$key.'.name')]) aria-invalid="{{ $errors->has('fields.'.$key.'.name') ? 'true' : 'false' }}" @if($errors->has('fields.'.$key.'.name')) aria-describedby="field-{{ $key }}-error" @endif>@error('fields.'.$key.'.name')<small id="field-{{ $key }}-error" class="field-error">{{ $message }}</small>@enderror</label>
                <label class="designer-field-format">Formato<select wire:model.live="fields.{{ $key }}.type"><option value="texto">Texto</option><option value="numero">Número</option><option value="fecha">Fecha</option><option value="lista">Lista</option></select></label>
                <label class="designer-field-unit">Unidad<input wire:model="fields.{{ $key }}.unit" placeholder="Ej.: BTU"></label>
                <div class="designer-field-actions">
                    <label class="check required-toggle"><span>Obligatorio</span><input type="checkbox" wire:model="fields.{{ $key }}.required"><span class="required-switch" aria-hidden="true"></span></label>
                    <button type="button" class="text-button" wire:click="removeField({{ $key }})" aria-label="Quitar campo {{ $key+1 }}"><x-icon name="close"/></button>
                </div>
                @if($field['type']==='lista')<label class="designer-options">Opciones<input wire:model="fields.{{ $key }}.options" placeholder="Opción 1, opción 2" @class(['field-invalid' => $errors->has('fields.'.$key.'.options')]) aria-invalid="{{ $errors->has('fields.'.$key.'.options') ? 'true' : 'false' }}">@error('fields.'.$key.'.options')<small class="field-error">{{ $message }}</small>@enderror</label>@endif
            </div>
            @endforeach
        </div>
        <div class="sheet-add"><button type="button" class="button secondary compact" wire:click="addField"><x-icon name="plus"/>Agregar campo</button></div>
        </fieldset>
        <h3>Actividades del mantenimiento</h3>
        <div x-data="{ activityTab: '{{ $designerType?->activitiesLocked('preventive') && !$designerType?->activitiesLocked('corrective') ? 'corrective' : 'preventive' }}' }">
            <div class="activity-tabs" role="tablist" aria-label="Tipo de mantenimiento">
                <button type="button" role="tab" :aria-selected="activityTab === 'preventive'" :class="{selected: activityTab === 'preventive'}" @click="activityTab = 'preventive'">Preventivo</button>
                <button type="button" role="tab" :aria-selected="activityTab === 'corrective'" :class="{selected: activityTab === 'corrective'}" @click="activityTab = 'corrective'">Correctivo</button>
            </div>
            @foreach(['preventive'=>'preventivo','corrective'=>'correctivo'] as $kind=>$label)
            <section role="tabpanel" aria-label="Actividades de mantenimiento {{ $label }}" x-show="activityTab === '{{ $kind }}'" @if($kind==='corrective') x-cloak @endif>
                @if($designerType?->activitiesLocked($kind))<p class="muted small">Estas actividades ya tienen mantenimientos registrados y no se pueden editar.</p>@endif
                <fieldset @disabled($designerType?->activitiesLocked($kind))>
                @foreach($templateActivities[$kind] as $index=>$activity)
                <div class="template-activity-row" wire:key="template-activity-{{ $activity['key'] }}">
                    <span>{{ $loop->iteration }}</span>
                    <label><span class="sr-only">Actividad {{ $loop->iteration }} de mantenimiento {{ $label }}</span><input wire:model="templateActivities.{{ $kind }}.{{ $index }}.name" placeholder="Descripción de la actividad" maxlength="255" @class(['field-invalid' => $errors->has('templateActivities.'.$kind.'.'.$index.'.name')]) aria-invalid="{{ $errors->has('templateActivities.'.$kind.'.'.$index.'.name') ? 'true' : 'false' }}">@error('templateActivities.'.$kind.'.'.$index.'.name')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <button type="button" class="text-button" wire:click="removeTemplateActivity('{{ $kind }}', {{ $index }})" aria-label="Quitar actividad {{ $loop->iteration }} de mantenimiento {{ $label }}"><x-icon name="close"/></button>
                </div>
                @endforeach
                <div class="sheet-add"><button type="button" class="button secondary compact" wire:click="addTemplateActivity('{{ $kind }}')"><x-icon name="plus"/>Agregar actividad</button></div>
                </fieldset>
            </section>
            @endforeach
        </div>
    </article>
</div>
