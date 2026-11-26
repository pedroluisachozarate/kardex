<div class="admin-workspace project-workspace" data-recovery-scope="web-{{ auth()->id() }}-project-{{ $projectId }}" x-data @keydown.escape.window="$wire.closeModal()">
<header class="page-heading project-compact-heading"><a class="dashboard-back" href="{{ route('projects.index') }}" aria-label="Volver a proyectos" title="Volver a proyectos">←</a><div><p class="eyebrow">{{ $project->client->name }}</p><h1>{{ $project->name }}</h1></div></header>
@if($isAdmin)
<div class="admin-tabs project-admin-tabs" role="tablist" aria-label="Secciones del proyecto">
@foreach(['types' => 'Tipos de equipo', 'members' => 'Personal'] as $key => $label)
<button type="button" role="tab" class="admin-tab {{ $section === $key ? 'selected' : '' }}" aria-selected="{{ $section === $key ? 'true' : 'false' }}" wire:click="selectSection('{{ $key }}')">{{ $label }}</button>
@endforeach
</div>
@endif
@if($message)<div hidden data-kardex-alert="success">{{ $message }}</div>@endif
@if($section==='types')
<section class="card project-section-list">
    <div class="project-section-toolbar">
        <span class="project-section-meta">{{ $project->templates->count() }} {{ $project->templates->count() === 1 ? 'tipo de equipo' : 'tipos de equipo' }}</span>
        <button type="button" class="button compact" wire:click="open('type')"><x-icon name="plus"/>Crear Kardex</button>
    </div>
    @forelse($project->templates as $t)
        <div class="project-section-row" wire:key="project-type-{{ $t->id }}">
            <div class="project-type-name">
                <span>{{ $t->name }}</span>
                <span class="project-section-meta">{{ count($t->preventive) }} preventivas · {{ count($t->corrective) }} correctivas</span>
            </div>
            <div class="project-type-actions">
                <button type="button" class="button secondary compact" wire:click="open('preview-type', {{ $t->id }})" aria-label="Ver diseño de {{ $t->name }}" title="Ver diseño"><x-icon name="eye"/></button>
                @unless($t->fullyLocked())
                    <button type="button" class="button secondary compact" wire:click="open('type', {{ $t->id }})" aria-label="Editar {{ $t->name }}" title="Editar"><x-icon name="edit"/></button>
                @endunless
            </div>
        </div>
    @empty
        <p class="project-section-empty">Sin tipos de equipo.</p>
    @endforelse
</section>
@elseif($section==='equipment')
<section class="card admin-list"><div class="admin-toolbar compact-toolbar"><label><span class="sr-only">Buscar equipos</span><input wire:model.live.debounce.300ms="search" placeholder="Equipo o serie…" aria-label="Buscar equipos"></label><div class="status-check-filter" x-data="{show:false}" @click.outside="show=false" @keydown.escape.stop="show=false"><button type="button" class="button secondary" @click="show=!show" :aria-expanded="show" aria-label="Filtrar estados">Estados · {{ count($statusFilters) }} <svg class="status-filter-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><div class="status-check-menu state-filter-panel" x-show="show" x-cloak>@include('partials.state-filter-options',['stateModel'=>'statusFilters'])</div></div>@if($canRegister)<button class="button" wire:click="open('asset')" @disabled($project->templates->isEmpty())><x-icon name="plus"/>Agregar equipo</button>@endif</div>
@include('partials.equipment-register')<div class="admin-pagination"><span>{{ $assets->firstItem() ?? 0 }}–{{ $assets->lastItem() ?? 0 }} de {{ $assets->total() }}</span><div><button type="button" class="button secondary compact" wire:click="previousPage('equipmentPage')" @disabled($assets->onFirstPage())><x-icon name="back"/>Anterior</button><span>{{ $assets->currentPage() }} / {{ $assets->lastPage() }}</span><button type="button" class="button secondary compact" wire:click="nextPage('equipmentPage')" @disabled(!$assets->hasMorePages())>Siguiente<x-icon name="next"/></button></div></div></section>
@else
<section class="card project-section-list">
    <div class="project-section-toolbar">
        <span class="project-section-meta">{{ $project->users->count() }} personas asignadas</span>
        <button type="button" class="button compact" wire:click="open('members')"><x-icon name="users"/>Asignar personal</button>
    </div>
    @forelse($project->users as $u)
        <div class="project-section-row" wire:key="project-person-{{ $u->id }}">
            <span>{{ $u->name }}</span>
            <span class="project-section-meta">{{ ucfirst($u->role) }}</span>
        </div>
    @empty
        <p class="project-section-empty">Sin personal asignado.</p>
    @endforelse
</section>
@endif
@if($modal)
<div class="admin-overlay" wire:key="project-modal-{{ $modal }}-{{ $editing }}" x-data="{ galleryOpen: false, uploads: 0 }" x-on:livewire-upload-start="uploads++" x-on:livewire-upload-finish="uploads = Math.max(0, uploads - 1)" x-on:livewire-upload-error="uploads = Math.max(0, uploads - 1)" x-on:livewire-upload-cancel="uploads = Math.max(0, uploads - 1)" @kardex-gallery-state.window="galleryOpen=$event.detail.open" x-trap.inert.noscroll="!galleryOpen" x-init="$nextTick(() => $refs.projectDialog.querySelector('input,select,button')?.focus())"><div class="admin-backdrop"></div>
<section class="admin-modal {{ in_array($modal,['type','preview-type','asset','edit','view'])?'type-modal':'' }}" role="dialog" aria-modal="true" aria-labelledby="project-modal-title" x-ref="projectDialog">
<header><h2 id="project-modal-title">{{ ['type'=>$editing?'Diseñar Kardex':'Diseñar Kardex','preview-type'=>$designerType?->name,'asset'=>'Registrar equipo y Kardex','edit'=>'Editar Kardex','view'=>'Consultar Kardex','members'=>'Asignar personal','intervention'=>'Agregar mantenimiento'][$modal] }}</h2><button class="text-button close-drawer" :disabled="uploads > 0" wire:click="closeModal" aria-label="Cerrar formulario"><x-icon name="close"/></button></header>
@if($modal==='preview-type')
<div class="drawer-body project-type-detail">
    <section class="card project-detail-card">
        <h3>Ficha técnica</h3>
        <ul class="project-detail-fields">
            @foreach($designerType->fields as $field)
                <li>{{ $field['name'] }}
                    <small>{{ $field['type'] === 'numero' ? 'Número' : ucfirst($field['type']) }}@if(!empty($field['unit'])) · {{ $field['unit'] }}@endif @if(!empty($field['required'])) · Obligatorio @endif</small>
                    @if(!empty($field['options']))<small>{{ implode(', ', $field['options']) }}</small>@endif
                </li>
            @endforeach
        </ul>
    </section>
    <section class="card project-detail-card" x-data="{ kind: 'preventive' }">
        <h3>Actividades</h3>
        <div class="project-detail-tabs" role="group" aria-label="Tipo de mantenimiento">
            @foreach(['preventive' => 'Preventivas', 'corrective' => 'Correctivas'] as $kind => $label)
                <button type="button" class="button secondary compact" :aria-pressed="kind === '{{ $kind }}'" @click="kind = '{{ $kind }}'">{{ $label }} · {{ count($designerType->{$kind}) }}</button>
            @endforeach
        </div>
        @foreach(['preventive', 'corrective'] as $kind)
            <ol x-show="kind === '{{ $kind }}'" @if($kind === 'corrective') x-cloak @endif>
                @forelse($designerType->{$kind} as $activity)
                    <li>{{ explode('|', $activity, 2)[0] }}</li>
                @empty
                    <li class="project-section-meta">Sin actividades.</li>
                @endforelse
            </ol>
        @endforeach
    </section>
</div>
@elseif($modal==='view')
<div class="drawer-body maintenance-view-body">
@include('partials.maintenance-detail', ['i'=>$viewedIntervention, 'logs'=>\App\Models\AuditLog::with('user')->where('intervention_id',$viewedIntervention->id)->latest()->get()])
</div>
@else
<form @if(in_array($modal,['asset','edit','intervention'])) data-recovery-form data-recovery-mode="{{ $modal }}" data-recovery-id="{{ $editing }}" @endif wire:submit="{{ ['type'=>'saveType','asset'=>'saveCurrentMaintenance','edit'=>'saveCurrentMaintenance','members'=>'saveMembers','intervention'=>'createIntervention'][$modal] }}" novalidate>
@if(in_array($modal,['asset','edit','intervention']))
    <p wire:ignore data-recovery-status class="workspace-recovery-status" role="status"></p>
@endif
<div class="drawer-body" wire:key="project-drawer-{{ $modal }}-{{ $editing }}">
@if($modal==='edit' && $message)<div hidden data-kardex-alert="success">{{ $message }}</div>@endif
@if($errors->any())<div hidden data-kardex-alert="error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if($modal==='type')
@include('livewire.kardex-template-sheet')
@elseif($modal==='members')
<p class="personnel-intro">Selecciona las personas que tendrán acceso al proyecto.</p>
<div class="personnel-list">
@foreach($users as $u)<label class="personnel-option" wire:key="member-{{ $u->id }}"><input type="checkbox" wire:model="members" value="{{ $u->id }}"><span class="personnel-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($u->name,0,1)) }}</span><span class="personnel-name">{{ $u->name }}@if(!$u->active)<small>Inactivo</small>@endif</span><span class="personnel-role">{{ ['admin'=>'Administrador','supervisor'=>'Supervisor','tecnico'=>'Técnico','consulta'=>'Consulta'][$u->role] ?? ucfirst($u->role) }}</span></label>@endforeach
</div>
<p class="muted small">El administrador ya tiene acceso a todos los proyectos.</p>
@elseif(in_array($modal,['asset','edit']))
@include('livewire.kardex-registration-sheet')
@elseif($modal==='intervention')
<label>Mantenimiento<select wire:model="form.type"><option value="preventivo">Preventivo</option><option value="correctivo">Correctivo</option></select></label><label>Fecha<input type="date" wire:model="form.date"></label>
@endif
</div>
@php($ready = in_array($modal,['asset','edit']) && $this->readyForReview())
@php($resending = $editingIntervention?->status === 'observado')
<footer><button type="button" class="button secondary footer-cancel" :disabled="uploads > 0" wire:click="closeModal" aria-label="Cancelar" title="Cancelar"><x-icon name="close"/><span class="footer-icon-label">Cancelar</span></button><div class="editor-footer-actions">
@if($editingIntervention)<a class="button secondary compact footer-pdf" target="_blank" :aria-disabled="uploads > 0" :tabindex="uploads > 0 ? -1 : null" @click="if (uploads > 0) $event.preventDefault()" href="{{ route('interventions.print', $editingIntervention) }}" aria-label="PDF" title="PDF"><x-icon name="file"/><span class="footer-icon-label">PDF</span></a>@endif
@if($ready)<button type="button" class="button secondary" title="{{ $resending ? 'Reenviar a revisión' : 'Enviar a revisión' }}" aria-label="{{ $resending ? 'Reenviar a revisión' : 'Enviar a revisión' }}" wire:click="sendCurrentMaintenance" :disabled="uploads > 0" wire:loading.attr="disabled" wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,saveMembers,createIntervention"><x-icon name="send"/>{{ $resending ? 'Reenviar' : 'Enviar' }}</button>@endif
<button type="submit" class="button" title="{{ in_array($modal,['asset','edit']) ? ($resending ? 'Guardar correcciones' : 'Guardar borrador') : 'Guardar' }}" aria-label="{{ in_array($modal,['asset','edit']) ? ($resending ? 'Guardar correcciones' : 'Guardar borrador') : 'Guardar' }}" :disabled="uploads > 0" wire:loading.attr="disabled" wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,saveMembers,createIntervention"><x-icon name="save"/><span wire:loading.remove wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,saveMembers,createIntervention">{{ in_array($modal,['asset','edit']) ? 'Guardar' : ($modal==='intervention'?'Agregar mantenimiento':'Guardar') }}</span><span wire:loading wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,saveMembers,createIntervention">Guardando…</span></button>
</div></footer></form>
@endif
</section></div>
@endif
</div>

