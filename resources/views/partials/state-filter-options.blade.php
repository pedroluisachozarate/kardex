<div class="state-filter-heading"><strong>Mostrar estados</strong><button type="button" class="text-button" wire:click="$set('{{ $stateModel }}', ['borrador','observado','revision','finalizado'])">Todos</button></div>
<div class="state-filter-options">
@foreach(['borrador'=>'Borradores','revision'=>'En revisión','observado'=>'Observados','finalizado'=>'Finalizados'] as $value=>$label)
<label class="state-filter-option"><input type="checkbox" wire:model.live="{{ $stateModel }}" value="{{ $value }}"><span>{{ $label }}</span></label>
@endforeach
</div>
