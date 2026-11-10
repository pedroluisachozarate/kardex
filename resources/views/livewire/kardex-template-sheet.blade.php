<div class="kardex-editor compact-designer">
    @php($fieldsLocked = $designerType?->hasKardex() ?? false)
    <div class="sheet-tools">
        <label>Tipo de equipo<input wire:model="form.name" @disabled($fieldsLocked) placeholder="Ej.: Aire acondicionado"></label>
    </div>
    <article class="kardex-sheet" aria-label="Diseño completo del Kardex">
        <div class="sheet-heading"><strong>KARDEX DE MANTENIMIENTO</strong><div>N.º <span class="sheet-placeholder">Automático</span><br>Fecha <span class="sheet-placeholder">Al registrar</span></div></div>
        <h3>Datos del proyecto</h3>
        <div class="sheet-project"><div><b>Entidad:</b> {{ $project->client->name }}</div><div><b>Proyecto:</b> {{ $project->name }}</div></div>
        <h3>Ficha técnica del equipo</h3>
        <fieldset @disabled($fieldsLocked)>
        <div class="base-field-list">@foreach(\App\Models\Template::baseFields() as $base)<label>{{ $base['name'] }}<input type="text" value="Texto · Campo base" disabled aria-label="{{ $base['name'] }}: campo base no modificable"></label>@endforeach</div>
        <div class="sheet-fields">
            @forelse($fields as $key=>$field)
            <div class="designer-field-row" wire:key="sheet-field-{{ $key }}">
                <label>Campo<input wire:model="fields.{{ $key }}.name" placeholder="Ej.: Capacidad"></label>
                <label>Formato<select wire:model.live="fields.{{ $key }}.type"><option value="texto">Texto</option><option value="numero">Número</option><option value="fecha">Fecha</option><option value="lista">Lista</option></select></label>
                <label>Unidad<input wire:model="fields.{{ $key }}.unit" placeholder="Ej.: BTU"></label>
                <label class="check required-toggle"><input type="checkbox" wire:model="fields.{{ $key }}.required"><span class="required-switch" aria-hidden="true"></span><span>Obligatorio</span></label>
                <button type="button" class="text-button" wire:click="removeField({{ $key }})" aria-label="Quitar campo {{ $key+1 }}"><x-icon name="close"/></button>
                @if($field['type']==='lista')<label class="designer-options">Opciones<input wire:model="fields.{{ $key }}.options" placeholder="Opción 1, opción 2"></label>@endif
            </div>
            @empty<p class="muted small">Agrega los campos que tendrá este equipo.</p>@endforelse
        </div>
        <div class="sheet-add"><button type="button" class="button secondary compact" wire:click="addField"><x-icon name="plus"/>Agregar campo</button></div>
        </fieldset>
        <h3>Actividades del mantenimiento</h3>
        <div x-data="{ activityTab: '{{ $designerType?->activitiesLocked('preventive') && !$designerType?->activitiesLocked('corrective') ? 'corrective' : 'preventive' }}' }">
            <div class="activity-tabs" role="tablist" aria-label="Tipo de mantenimiento">
                <button type="button" role="tab" :aria-selected="activityTab === 'preventive'" :class="{selected: activityTab === 'preventive'}" @click="activityTab = 'preventive'"><x-icon name="check"/>Preventivo</button>
                <button type="button" role="tab" :aria-selected="activityTab === 'corrective'" :class="{selected: activityTab === 'corrective'}" @click="activityTab = 'corrective'"><x-icon name="file"/>Correctivo</button>
            </div>
            @foreach(['preventive'=>'preventivo','corrective'=>'correctivo'] as $kind=>$label)
            <section role="tabpanel" aria-label="Actividades de mantenimiento {{ $label }}" x-show="activityTab === '{{ $kind }}'" @if($kind==='corrective') x-cloak @endif>
                @if($designerType?->activitiesLocked($kind))<p class="muted small">Estas actividades ya tienen mantenimientos registrados y no se pueden editar.</p>@endif
                <fieldset @disabled($designerType?->activitiesLocked($kind))>
                @foreach($templateActivities[$kind] as $index=>$activity)
                <div class="template-activity-row" wire:key="template-activity-{{ $activity['key'] }}">
                    <span>{{ $loop->iteration }}</span>
                    <label><span class="sr-only">Actividad {{ $loop->iteration }} de mantenimiento {{ $label }}</span><input wire:model="templateActivities.{{ $kind }}.{{ $index }}.name" placeholder="Descripción de la actividad" maxlength="255"></label>
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
