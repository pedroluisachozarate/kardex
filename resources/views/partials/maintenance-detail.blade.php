@php
    $snapshot = $i->status === 'finalizado' ? ($i->snapshot ?? []) : [];
    $record = array_merge($i->toArray(), $snapshot['record'] ?? []);
    $technical = \App\Models\Template::orderedTechnicalData($snapshot['technical'] ?? $i->asset->technical_data ?? [], $snapshot['equipment'] ?? $i->asset->equipment->toArray());
    $activities = collect($snapshot['activities'] ?? $i->activities->map(fn($activity) => [...$activity->toArray(), 'performer_name'=>$activity->performer?->name])->all());
    $role = auth()->user()->role;
    $history = $logs->reject(fn ($log) => $log->action === 'Kardex actualizado' && $logs->contains(fn ($other) => $other->user_id === $log->user_id && $other->created_at->equalTo($log->created_at) && str_starts_with($other->action, 'Estado: ')));
    $saveLabels = [];
    $savedStatus = 'borrador';
    foreach ($logs->sortBy('id') as $entry) {
        if (str_starts_with($entry->action, 'Estado: ') && str_contains($entry->action, ' → ')) {
            $savedStatus = trim(explode(' → ', $entry->action, 2)[1]);
        }
        $saveLabels[$entry->id] = $entry->action === 'Kardex actualizado'
            ? ($savedStatus === 'observado' ? 'Correcciones guardadas' : 'Borrador guardado') : $entry->action;
    }
    $previousSaveUser = null;
    $history = $history->filter(function ($log) use (&$previousSaveUser, $saveLabels) {
        if (!in_array($saveLabels[$log->id], ['Borrador guardado', 'Correcciones guardadas'])) {
            $previousSaveUser = null;
            return true;
        }
        if ($previousSaveUser === $log->user_id.':'.$saveLabels[$log->id]) {
            return false;
        }
        $previousSaveUser = $log->user_id.':'.$saveLabels[$log->id];
        return true;
    });
    $results = ['pendiente'=>'Pendiente','realizado'=>'Realizado','no_realizado'=>'No realizado','no_aplica'=>'No aplica'];
@endphp
<div class="maintenance-detail">
<a class="breadcrumb" href="{{ route('projects.show',$i->asset->project) }}">← {{ $i->asset->project->name }}</a>
<header class="maintenance-detail-heading"><div><p class="eyebrow">Mantenimiento {{ $i->type }}</p><h1>Kardex {{ $i->asset->kardex_code }} <span class="muted">· Mantenimiento {{ sprintf('%03d',$i->number) }}</span></h1></div><div class="detail-header-actions"><span class="badge {{ $i->status }}">{{ $i->status_label }}</span>@if($role!=='consulta'||$i->status==='finalizado')<a class="button secondary compact" target="_blank" href="{{ route('interventions.print',$i) }}"><x-icon name="file"/>PDF</a>@endif</div></header>
<article class="detail-sheet">
<div class="detail-summary" style="justify-content:flex-end"><span>{{ \Illuminate\Support\Carbon::parse($record['date'])->format('d/m/Y') }}</span></div>
<h2>Datos del proyecto</h2>
<div class="detail-project"><span><b>Entidad:</b> {{ $snapshot['client'] ?? $i->asset->project->client->name }}</span><span><b>Proyecto:</b> {{ $snapshot['project'] ?? $i->asset->project->name }}</span></div>
<h2>Ficha técnica del equipo</h2>
<dl class="detail-fields">@foreach($technical as $name=>$field)<div><dt>{{ $name }}</dt><dd>{{ filled($field['value']??null) ? $field['value'] : '—' }} {{ $field['unit']??'' }}</dd></div>@endforeach<div><dt>Ubicación</dt><dd>{{ $record['location_text'] ?: '—' }}</dd></div></dl>
@if(filled($record['fault'] ?? null) || filled($record['diagnosis'] ?? null))<h2>Diagnóstico</h2><dl class="detail-fields detail-two"><div><dt>Falla reportada</dt><dd>{{ $record['fault'] ?: '—' }}</dd></div><div><dt>Diagnóstico</dt><dd>{{ $record['diagnosis'] ?: '—' }}</dd></div></dl>@endif
<h2>Actividades del mantenimiento {{ $i->type }}</h2>
<div class="detail-activities">@foreach($activities as $activity)<section class="detail-activity"><div class="detail-activity-heading"><span class="detail-activity-number">{{ $loop->iteration }}</span><strong>{{ \Illuminate\Support\Str::ucfirst(mb_strtolower($activity['name'])) }}</strong><span class="badge {{ $activity['result'] }}">{{ $results[$activity['result']] ?? $activity['result'] }}</span>@if(!empty($activity['performed_on']))<time>{{ \Illuminate\Support\Carbon::parse($activity['performed_on'])->format('d/m/Y') }}</time>@endif</div>
@if(filled($activity['notes']??null))<p class="detail-activity-note"><b>{{ $activity['result']==='no_realizado'?'Motivo':'Observación' }}:</b> {{ $activity['notes'] }}</p>@endif
@if(count($activity['photos']??[]))<div class="detail-photos">@foreach($activity['photos'] as $photo)<a href="{{ route('photos.optimized',$photo['id']) }}" target="_blank" aria-label="Ver foto {{ $loop->iteration }} de {{ $activity['name'] }}"><img loading="lazy" src="{{ route('photos.thumbnail',$photo['id']) }}" alt="Foto de {{ $activity['name'] }}"></a>@endforeach</div>@endif
</section>@endforeach</div>
<h2>Resultados</h2><dl class="detail-fields detail-results"><div><dt>Observaciones generales</dt><dd>{{ $record['observations'] ?: '—' }}</dd></div><div><dt>Conclusiones</dt><dd>{{ $record['conclusions'] ?: '—' }}</dd></div><div><dt>Recomendaciones</dt><dd>{{ $record['recommendations'] ?: '—' }}</dd></div></dl>
@if(!empty($record['measurements']))<h2>Mediciones y lecturas</h2><dl class="detail-fields">@foreach($record['measurements'] as $measurement)<div><dt>{{ $measurement['name'] }}</dt><dd>{{ $measurement['value'] }} {{ $measurement['unit'] }}</dd></div>@endforeach</dl>@endif
@if(!empty($record['parts']))<h2>Repuestos y materiales</h2><dl class="detail-fields">@foreach($record['parts'] as $part)<div><dt>{{ $part['name'] }}</dt><dd>{{ $part['quantity'] }} · {{ ucfirst(str_replace('_',' ',$part['status'])) }}</dd></div>@endforeach</dl>@endif
@if($i->review_note)<div class="detail-review-note"><b>Observación de revisión:</b> {{ $i->review_note }}</div>@endif
</article>
@if($i->status==='revision' && $role==='supervisor')
<section class="detail-review"><h2>Revisión y cierre</h2><form method="POST" action="{{ route('interventions.transition',$i) }}">@csrf<label for="review-note">Observación <span class="muted">(obligatoria al devolver)</span></label><textarea id="review-note" name="review_note" rows="2" placeholder="Indica las correcciones que debe realizar el técnico">{{ old('review_note') }}</textarea><div class="detail-review-actions"><button data-confirm="¿Aprobar y finalizar el Kardex?" class="button" name="status" value="finalizado"><x-icon name="check"/>Aprobar y finalizar</button><button data-confirm="¿Devolver con observaciones?" class="button secondary" name="status" value="observado"><x-icon name="back"/>Devolver con observaciones</button></div></form></section>
@elseif($i->status==='finalizado' && $role==='supervisor')
<details class="detail-reopen"><summary>Reabrir Kardex</summary><form method="POST" action="{{ route('interventions.transition',$i) }}">@csrf<label>Motivo de reapertura<textarea name="review_note" rows="2" required></textarea></label><button data-confirm="¿Reabrir este Kardex?" class="button secondary" name="status" value="observado"><x-icon name="back"/>Reabrir Kardex</button></form></details>
@elseif($i->status==='revision')<p class="detail-waiting">Pendiente de aprobación del supervisor.</p>@endif
<details class="detail-history"><summary>Historial de cambios</summary>@foreach($history as $log)<div class="detail-log"><strong>{{ $saveLabels[$log->id] }}</strong><small>{{ $log->user?->name ?? 'Usuario' }} · {{ $log->created_at->timezone('America/La_Paz')->format('d/m/Y H:i') }}</small>@if($log->details['note']??null)<p><b>Motivo:</b> {{ $log->details['note'] }}</p>@endif</div>@endforeach</details>
</div>
