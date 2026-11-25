<div class="admin-workspace project-workspace" data-recovery-scope="web-{{ auth()->id() }}-project-{{ $projectId }}" x-data @keydown.escape.window="$wire.closeModal()">
<header class="page-heading project-compact-heading"><a class="dashboard-back" href="{{ route('projects.index') }}" aria-label="Volver a proyectos" title="Volver a proyectos">←</a><div><p class="eyebrow">{{ $project->client->name }}</p><h1>{{ $project->name }}</h1></div></header>
@if($isAdmin)
<div class="admin-tabs" role="tablist" aria-label="Secciones del proyecto">
@foreach(['types'=>'1. Tipos de equipo','equipment'=>'2. Equipos y Kardex','members'=>'3. Personal'] as $key=>$label)<button role="tab" class="admin-tab {{ $section===$key?'selected':'' }}" aria-selected="{{ $section===$key?'true':'false' }}" wire:click="selectSection('{{ $key }}')"><x-icon name="file"/>{{ $label }}</button>@endforeach
</div>
@endif
@if($message)<div hidden data-kardex-alert="success">{{ $message }}</div>@endif
@if($section==='types')
<section class="card admin-list"><div class="admin-toolbar"><span class="muted small">Define los campos; el técnico completa los valores.</span><div><button class="button secondary" wire:click="open('copy')" @disabled($otherTypes->isEmpty())><x-icon name="copy"/>Copiar de otro proyecto</button> <button class="button" wire:click="open('type')"><x-icon name="plus"/>Nuevo tipo</button></div></div>
<div class="table-card"><table class="responsive-table"><thead><tr><th>Tipo de equipo</th><th>Actividades</th><th></th></tr></thead><tbody>@forelse($project->templates as $t)<tr><td data-label="Tipo de equipo"><strong>{{ $t->name }}</strong></td><td data-label="Actividades">{{ count($t->preventive) }} preventivas · {{ count($t->corrective) }} correctivas</td><td data-label="Acciones" class="align-right">@if($t->fullyLocked())<span class="muted small" title="Ya tiene Kardex creados">Edición bloqueada</span>@else<button class="button secondary compact" wire:click="open('type',{{ $t->id }})"><x-icon name="edit"/>Editar</button>@endif</td></tr>@empty<tr><td colspan="3" class="empty">Sin tipos de equipo.</td></tr>@endforelse</tbody></table></div><div class="admin-pagination"><span>{{ $assets->firstItem() ?? 0 }}–{{ $assets->lastItem() ?? 0 }} de {{ $assets->total() }}</span><div><button type="button" class="button secondary compact" wire:click="previousPage('equipmentPage')" @disabled($assets->onFirstPage())><x-icon name="back"/>Anterior</button><span>{{ $assets->currentPage() }} / {{ $assets->lastPage() }}</span><button type="button" class="button secondary compact" wire:click="nextPage('equipmentPage')" @disabled(!$assets->hasMorePages())>Siguiente<x-icon name="next"/></button></div></div></section>
@elseif($section==='equipment')
<section class="card admin-list"><div class="admin-toolbar compact-toolbar"><label><span class="sr-only">Buscar equipos</span><input wire:model.live.debounce.300ms="search" placeholder="Equipo o serie…" aria-label="Buscar equipos"></label><div class="status-check-filter" x-data="{show:false}" @click.outside="show=false" @keydown.escape.stop="show=false"><button type="button" class="button secondary" @click="show=!show" :aria-expanded="show" aria-label="Filtrar estados">Estados · {{ count($statusFilters) }} <svg class="status-filter-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><div class="status-check-menu state-filter-panel" x-show="show" x-cloak>@include('partials.state-filter-options',['stateModel'=>'statusFilters'])</div></div>@if($canRegister)<button class="button" wire:click="open('asset')" @disabled($project->templates->isEmpty())><x-icon name="plus"/>Agregar equipo</button>@endif</div>
@include('partials.equipment-register')<div class="admin-pagination"><span>{{ $assets->firstItem() ?? 0 }}–{{ $assets->lastItem() ?? 0 }} de {{ $assets->total() }}</span><div><button type="button" class="button secondary compact" wire:click="previousPage('equipmentPage')" @disabled($assets->onFirstPage())><x-icon name="back"/>Anterior</button><span>{{ $assets->currentPage() }} / {{ $assets->lastPage() }}</span><button type="button" class="button secondary compact" wire:click="nextPage('equipmentPage')" @disabled(!$assets->hasMorePages())>Siguiente<x-icon name="next"/></button></div></div></section>
@else
<section class="card admin-list"><div class="admin-toolbar"><span>{{ $project->users->count() }} personas asignadas</span><button class="button" wire:click="open('members')"><x-icon name="users"/>Asignar personal</button></div><div class="table-card"><table class="responsive-table"><thead><tr><th>Nombre</th><th>Rol</th></tr></thead><tbody>@forelse($project->users as $u)<tr><td data-label="Nombre">{{ $u->name }}</td><td data-label="Rol">{{ ucfirst($u->role) }}</td></tr>@empty<tr><td colspan="2" class="empty">Sin personal asignado.</td></tr>@endforelse</tbody></table></div></section>
@endif
@if($modal)
<div class="admin-overlay" wire:key="project-modal-{{ $modal }}-{{ $editing }}" x-data="{ galleryOpen: false, uploads: 0 }" x-on:livewire-upload-start="uploads++" x-on:livewire-upload-finish="uploads = Math.max(0, uploads - 1)" x-on:livewire-upload-error="uploads = Math.max(0, uploads - 1)" x-on:livewire-upload-cancel="uploads = Math.max(0, uploads - 1)" @kardex-gallery-state.window="galleryOpen=$event.detail.open" x-trap.inert.noscroll="!galleryOpen" x-init="$nextTick(() => $refs.projectDialog.querySelector('input,select,button')?.focus())"><div class="admin-backdrop"></div>
<section class="admin-modal {{ in_array($modal,['type','asset','edit','view'])?'type-modal':'' }}" role="dialog" aria-modal="true" aria-labelledby="project-modal-title" x-ref="projectDialog">
<header><h2 id="project-modal-title">{{ ['type'=>$editing?'Diseñar Kardex':'Diseñar Kardex','copy'=>'Copiar tipo de equipo','asset'=>'Registrar equipo y Kardex','edit'=>'Editar Kardex','view'=>'Consultar Kardex','members'=>'Asignar personal','intervention'=>'Agregar mantenimiento'][$modal] }}</h2><button class="text-button close-drawer" :disabled="uploads > 0" wire:click="closeModal" aria-label="Cerrar formulario"><x-icon name="close"/></button></header>
@if($modal==='view')
<div class="drawer-body maintenance-view-body">
@include('partials.maintenance-detail', ['i'=>$viewedIntervention, 'logs'=>\App\Models\AuditLog::with('user')->where('intervention_id',$viewedIntervention->id)->latest()->get()])
</div>
@else
<form @if(in_array($modal,['asset','edit','intervention'])) data-recovery-form data-recovery-mode="{{ $modal }}" data-recovery-id="{{ $editing }}" @endif wire:submit="{{ ['type'=>'saveType','copy'=>'copyType','asset'=>'saveCurrentMaintenance','edit'=>'saveCurrentMaintenance','members'=>'saveMembers','intervention'=>'createIntervention'][$modal] }}" novalidate>
@if(in_array($modal,['asset','edit','intervention']))
    <p wire:ignore data-recovery-status class="workspace-recovery-status" role="status"></p>
@endif
<div class="drawer-body" wire:key="project-drawer-{{ $modal }}-{{ $editing }}">
@if($modal==='edit' && $message)<div hidden data-kardex-alert="success">{{ $message }}</div>@endif
@if($errors->any())<div hidden data-kardex-alert="error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if($modal==='type')
@include('livewire.kardex-template-sheet')
@elseif($modal==='copy')<label>Tipo disponible<select wire:model="source"><option value="">Seleccionar</option>@foreach($otherTypes as $t)<option value="{{ $t->id }}">{{ $t->name }} · Proyecto {{ $t->project_id }}</option>@endforeach</select></label>
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
@if($ready)<button type="button" class="button secondary" title="{{ $resending ? 'Reenviar a revisión' : 'Enviar a revisión' }}" aria-label="{{ $resending ? 'Reenviar a revisión' : 'Enviar a revisión' }}" wire:click="sendCurrentMaintenance" :disabled="uploads > 0" wire:loading.attr="disabled" wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,copyType,saveMembers,createIntervention"><x-icon name="send"/>{{ $resending ? 'Reenviar' : 'Enviar' }}</button>@endif
<button type="submit" class="button" title="{{ in_array($modal,['asset','edit']) ? ($resending ? 'Guardar correcciones' : 'Guardar borrador') : 'Guardar' }}" aria-label="{{ in_array($modal,['asset','edit']) ? ($resending ? 'Guardar correcciones' : 'Guardar borrador') : 'Guardar' }}" :disabled="uploads > 0" wire:loading.attr="disabled" wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,copyType,saveMembers,createIntervention"><x-icon name="save"/><span wire:loading.remove wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,copyType,saveMembers,createIntervention">{{ in_array($modal,['asset','edit']) ? 'Guardar' : ($modal==='intervention'?'Agregar mantenimiento':'Guardar') }}</span><span wire:loading wire:target="saveCurrentMaintenance,sendCurrentMaintenance,saveType,copyType,saveMembers,createIntervention">Guardando…</span></button>
</div></footer></form>
@endif
</section></div>
@endif
</div>

