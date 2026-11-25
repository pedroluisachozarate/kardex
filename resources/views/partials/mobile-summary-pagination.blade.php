@if($summaryPages>1)
<nav class="dashboard-pagination" aria-label="Paginación"><button type="button" class="text-button" wire:click="changeSummaryPage(-1)" @disabled($currentSummaryPage<=1)>← Anterior</button><span>{{ $currentSummaryPage }} / {{ $summaryPages }} · {{ $summaryResultCount }} registros</span><button type="button" class="text-button" wire:click="changeSummaryPage(1)" @disabled($currentSummaryPage>=$summaryPages)>Siguiente →</button></nav>
@endif
