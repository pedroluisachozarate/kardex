@props(['intervention'])
@php
$count = $intervention->activities->sum(fn($activity) => $activity->photos_count ?? $activity->photos->count());
$first = $intervention->activities->flatMap(fn($activity) => $activity->photos)->first();
@endphp
@if($count && $first)
<button type="button" class="maintenance-photo-button" data-photo-gallery="{{ route('interventions.photos',$intervention) }}" data-gallery-title="Kardex {{ $intervention->asset->kardex_code }} · Mantenimiento {{ sprintf('%03d',$intervention->number) }}" title="Ver {{ $count }} fotos" aria-label="Ver {{ $count }} fotos del mantenimiento {{ sprintf('%03d',$intervention->number) }}"><img src="{{ route('photos.thumbnail',$first) }}" alt="" loading="lazy" decoding="async" width="44" height="34"><span><x-icon name="camera"/>{{ $count }}</span></button>
@else
<button type="button" class="maintenance-photo-button no-photos" disabled title="Sin fotos" aria-label="Mantenimiento sin fotos"><x-icon name="camera"/><svg class="photo-disabled-mark" width="44" height="34" viewBox="0 0 44 34" aria-hidden="true"><path d="M11 26 33 8" stroke="currentColor" stroke-width="2"/></svg></button>
@endif
