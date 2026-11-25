<div class="table-card equipment-register-list">
@forelse($assets as $a)
@php($identity=$a->identity_data)
<article class="equipment-register-item" wire:key="asset-{{ $a->id }}">
    <div class="equipment-register-heading"><div class="equipment-register-name"><strong>{{ $a->kardex_code }}</strong><span> · {{ $identity['name'] }}</span></div>@if($canRegister)<button type="button" class="button secondary equipment-add-maintenance" title="Agregar mantenimiento" aria-label="Agregar mantenimiento a {{ $a->kardex_code }}" wire:click="open('intervention',{{ $a->id }})"><x-icon name="plus"/></button>@endif</div>
    <details class="equipment-register-info"><summary>Datos del equipo <svg class="equipment-info-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><dl>@foreach(['brand'=>'Marca','model'=>'Modelo','serial'=>'N.º de serie','code'=>'Código interno'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>{{ $identity[$key] ?: '—' }}</dd></div>@endforeach</dl></details>
    <div class="equipment-register-records">
    @forelse($a->interventions->sortByDesc('number') as $i)
    <div class="equipment-register-record" wire:key="equipment-record-{{ $i->id }}">
        <a class="equipment-record-link" href="{{ route('interventions.show',$i) }}" wire:click.prevent="openRecord({{ $i->id }})"><div class="equipment-record-label"><span>{{ $a->kardex_code }} / {{ sprintf('%03d',$i->number) }} · {{ ucfirst($i->type) }}</span><small>{{ $i->date->format('d/m/Y') }} · {{ $i->progress }}%</small></div><span class="badge {{ $i->status }}">{{ $i->status_label }}</span></a>
        <details class="equipment-record-options"><summary aria-label="Acciones del mantenimiento {{ sprintf('%03d',$i->number) }}" title="Fotos, PDF y acciones">⋯</summary><div class="equipment-record-actions"><x-maintenance-photos :intervention="$i"/>@if(auth()->user()->role!=='consulta' || $i->status==='finalizado')<a class="button secondary" href="{{ route('interventions.print',$i) }}" target="_blank" rel="noopener" title="PDF del mantenimiento {{ sprintf('%03d',$i->number) }}" aria-label="PDF del mantenimiento {{ sprintf('%03d',$i->number) }}"><x-icon name="file"/>PDF</a>@endif @if(auth()->user()->role==='supervisor')<button type="button" class="button danger" title="Eliminar mantenimiento {{ sprintf('%03d',$i->number) }}" aria-label="Eliminar mantenimiento {{ sprintf('%03d',$i->number) }}" data-confirm="¿Eliminar este mantenimiento?" data-confirm-text="Se eliminarán sus actividades y fotos. Si es el último mantenimiento, también se eliminará este Kardex del proyecto." wire:click="deleteMaintenance({{ $i->id }})" wire:loading.attr="disabled"><x-icon name="trash"/>Eliminar</button>@endif</div></details>
    </div>
    @empty<div class="equipment-register-empty">Sin mantenimientos.</div>@endforelse
    </div>
    @if(auth()->user()->role==='supervisor' && $a->interventions->isEmpty())<button type="button" class="button danger equipment-empty-delete" title="Eliminar Kardex vacío" aria-label="Eliminar Kardex vacío" data-confirm="¿Eliminar este Kardex vacío?" wire:click="deleteEmptyKardex({{ $a->id }})" wire:loading.attr="disabled"><x-icon name="trash"/>Eliminar Kardex vacío</button>@endif
</article>
@empty<div class="equipment-register-empty">No hay equipos para este filtro. Marca todos los estados para consultar el historial completo.</div>@endforelse
</div>
