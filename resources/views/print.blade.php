<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Kardex de mantenimiento</title>
<style>
@page { size: letter portrait; margin: 28pt 24pt 40pt; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; line-height: 1.2; color: #233b49; }
table { width:100%; border-collapse:collapse; table-layout:fixed; }
td, th { vertical-align:top; overflow-wrap:break-word; }
.header { margin-bottom:7pt; }
.header td { padding:0 0 5pt; }
.eyebrow { font-size:7.5pt; letter-spacing:1.4pt; color:#58717d; }
h1 { font-size:15pt; line-height:1.2; margin:5pt 0; color:#095d7d; }
.pdf-heading h1 { font-size:12pt; font-weight:bold; white-space:nowrap; margin:5pt 0 9pt; }
.metadata-row td { width:25%; padding:0 0 8pt; font-size:7.5pt; white-space:nowrap; }
.metadata-row td:last-child { text-align:right; }
.number { font-size:12pt; font-weight:bold; color:#095d7d; line-height:1.2; }
.meta { text-align:right; width:29%; }
.meta-line { margin:0 0 4pt; font-size:7.5pt; }
.meta-label { color:#627985; font-weight:normal; }
.meta-value { font-weight:bold; color:#233b49; }
.status { font-size:7pt; color:#97601c; margin-top:5pt; }
h2 { font-size:8pt; text-align:center; color:white; background:#0074ae; border:.5pt solid #182b38; border-bottom:0; padding:2pt 5pt; margin:0; page-break-after:avoid; }
.details td { padding:4pt 6pt; border: .5pt solid #182b38; }
.label { color:#172b3a; font-size:7pt; font-weight:bold; }
.value { font-weight:normal; }
.project td { padding:3pt 6pt; background:white; border:.5pt solid #182b38; }
.project { border:0; }
.project + h2 { margin-top:10pt; }
.project .key { width:14%; color:#536d7b; font-weight:bold; }
.grid th { background:#d2d2d2; text-align:center; font-size:6.5pt; padding:3pt 4pt; border:.5pt solid #182b38; }
.grid td { padding:3pt 4pt; border:.5pt solid #182b38; font-size:7pt; }
.grid tr:nth-child(even) td { background:white; }
.grid td.activity-note { padding:4pt 8pt; background:#f3f6f8; white-space:pre-wrap; }
thead { display:table-header-group; }
tr { page-break-inside:avoid; }
.small { font-size:7pt; color:#627985; }
.center { text-align:center; }
.result { font-size:7pt; font-weight:bold; }
.prose { padding:4pt 6pt; white-space:pre-wrap; }
.signatures { margin-top:40pt; page-break-inside:avoid; }
.signatures td { width:50%; text-align:center; padding:0 55pt; font-size:6pt; }
.signatures .small { font-size:6pt; }
.signature-line { border-top:.5pt solid #537181; padding-top:4pt; font-weight:bold; }
.footer { position:fixed; bottom:-26pt; left:0; right:0; border-top:.5pt solid #ccd9df; padding-top:6pt; font-size:7pt; color:#6a808c; }
.photo-page { page-break-before:always; }
.photo-page h1 { font-size:12pt; font-weight:bold; margin:5pt 0 9pt; }
.photo-page .eyebrow { font-size:7.5pt; letter-spacing:0; }
.photo-page > .small { font-size:7.5pt; margin-bottom:9pt; }
.photo-table td { width:50%; padding:8pt; border:.5pt solid #d5dfe5; }
.photo-box { height:178pt; text-align:center; line-height:178pt; background:#f4f7f9; }
.photo-box img { max-width:232pt; max-height:174pt; vertical-align:middle; }
.caption { font-size:7.5pt; margin-top:5pt; }
</style></head><body>
@php
$states = ['pendiente'=>'Pendiente', 'realizado'=>'Realizado', 'no_realizado'=>'No realizado', 'no_aplica'=>'No aplica'];
$date = \Illuminate\Support\Carbon::parse($record['date'])->format('d/m/Y');
@endphp
<div class="footer">KARDEX {{ $i->asset->kardex_code }} · {{ strtoupper($record['type']) }} · {{ $date }}</div>
<div class="pdf-heading"><h1>KARDEX DE MANTENIMIENTO {{ strtoupper($record['type']) }}</h1></div>
<table class="header metadata-row"><tr><td><span class="meta-label">Kardex: </span><span class="meta-value">{{ $i->asset->kardex_code }}</span></td><td><span class="meta-label">Mantenimiento: </span><span class="meta-value">{{ sprintf('%03d', $record['number']) }}</span></td><td><span class="meta-label">Fecha: </span><span class="meta-value">{{ $date }}</span></td><td><span class="meta-label">Estado: </span><span class="meta-value">{{ $i->status_label }}</span></td></tr></table>
<h2>DATOS DEL PROYECTO</h2>
<table class="project"><tr><td class="key">Entidad</td><td>{{ $client }}</td></tr><tr><td class="key">Proyecto</td><td>{{ $project }}</td></tr>@if(!empty($record['location_text']))<tr><td class="key">Ubicación</td><td>{{ $record['location_text'] }}</td></tr>@endif</table>
@if(count($technical))
<h2>FICHA TÉCNICA DEL EQUIPO</h2>
<table class="details">@foreach(collect($technical)->chunk(3) as $pair)<tr>@foreach($pair as $name=>$field)<td style="width:33.333%"><span class="label">{{ $name }}: </span><span class="value">{{ $field['value'] ?? '' }} {{ $field['unit'] ?? '' }}</span></td>@endforeach @for($blank=$pair->count(); $blank<3; $blank++)<td style="width:33.333%"></td>@endfor</tr>@endforeach</table>
@endif
@if(filled($record['fault'] ?? null) || filled($record['diagnosis'] ?? null))<h2>DIAGNÓSTICO DE LA INTERVENCIÓN</h2><div class="prose"><strong>Falla reportada:</strong> {{ $record['fault'] ?? '' }}<br><strong>Diagnóstico:</strong> {{ $record['diagnosis'] ?? '' }}</div>@endif
<h2 style="margin-top:10pt">DETALLE DEL MANTENIMIENTO {{ strtoupper($record['type']) }}</h2>
<table class="grid"><thead><tr><th style="width:5%">N.º</th><th style="width:56%">DESCRIPCIÓN</th><th style="width:16%">ESTADO</th><th style="width:23%">FECHA</th></tr></thead><tbody>@forelse($activities as $activity)<tr><td class="center">{{ $loop->iteration }}</td><td>{{ $activity['name'] }}</td><td class="result">{{ $states[$activity['result']] ?? $activity['result'] }}</td><td>@if(!empty($activity['performed_on'])){{ \Illuminate\Support\Carbon::parse($activity['performed_on'])->format('d/m/Y') }}@endif</td></tr>@if(trim($activity['notes'] ?? '') !== '')<tr><td colspan="4" class="activity-note"><strong>Observación:</strong> {{ $activity['notes'] }}</td></tr>@endif @empty<tr><td colspan="4">Sin actividades registradas.</td></tr>@endforelse</tbody></table>
@if(!empty($record['measurements']))<h2>MEDICIONES Y LECTURAS</h2><table class="grid"><thead><tr><th>PARÁMETRO</th><th>VALOR</th><th>UNIDAD</th></tr></thead><tbody>@foreach($record['measurements'] as $m)<tr><td>{{ $m['name'] }}</td><td>{{ $m['value'] }}</td><td>{{ $m['unit'] }}</td></tr>@endforeach</tbody></table>@endif
@if(!empty($record['parts']))<h2>REPUESTOS Y MATERIALES</h2><table class="grid"><thead><tr><th>DESCRIPCIÓN</th><th>CANTIDAD</th><th>ESTADO</th></tr></thead><tbody>@foreach($record['parts'] as $p)<tr><td>{{ $p['name'] }}</td><td>{{ $p['quantity'] }}</td><td>{{ ucfirst(str_replace('_',' ',$p['status'])) }}</td></tr>@endforeach</tbody></table>@endif
<div style="height:10pt"></div>
@foreach(['observations'=>'Observaciones', 'conclusions'=>'Conclusiones', 'recommendations'=>'Recomendaciones'] as $key=>$label)<div class="prose"><strong>{{ $label }}:</strong> {{ $record[$key] ?? '' }}</div>@endforeach
<table class="signatures"><tr><td><div class="signature-line">ENCARGADO O FISCAL DE SERVICIO</div><div class="small">Firma y sello</div></td><td><div class="signature-line">AGENTE DE SERVICIO O TÉCNICO</div><div class="small">Firma y sello</div></td></tr></table>
@foreach(collect($photos)->chunk(4) as $page)
<div class="photo-page"><div class="eyebrow">ANEXO FOTOGRÁFICO · KARDEX {{ $i->asset->kardex_code }}</div><h1>Registro fotográfico</h1><div class="small">{{ $project }} · {{ ucfirst($record['type']) }}</div><h2>EVIDENCIAS POR ACTIVIDAD</h2><table class="photo-table">@foreach($page->chunk(2) as $pair)<tr>@foreach($pair as $photo)<td><div class="photo-box">@if($photo['source'])<img src="{{ $photo['source'] }}">@else<span class="small">Imagen no disponible</span>@endif</div><div class="caption"><strong>{{ $photo['activity'] }}</strong>@if($photo['description'])<br>{{ $photo['description'] }}@endif</div></td>@endforeach @if($pair->count()===1)<td></td>@endif</tr>@endforeach</table></div>
@endforeach
</body></html>
