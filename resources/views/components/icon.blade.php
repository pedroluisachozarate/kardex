@props(['name'=>'plus'])
@php($paths = [
 'calendar'=>'M4 4h16v17H4V4ZM4 9h16M8 2v5M16 2v5',
 'back'=>'M19 12H5m7-7-7 7 7 7',
 'next'=>'M5 12h14m-7-7 7 7-7 7',
 'menu'=>'M4 6h16M4 12h16M4 18h16',
 'login'=>'M15 3h6v18h-6M3 12h13m-5-5 5 5-5 5',
 'check'=>'m5 12 4 4L19 6',
 'copy'=>'M9 9h12v12H9V9ZM15 9V3H3v12h6',
 'plus'=>'M12 5v14M5 12h14',
 'save'=>'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2ZM7 3v6h10V3M7 21v-8h10v8',
 'close'=>'M6 6l12 12M6 18 18 6',
 'send'=>'m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13',
 'file'=>'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6ZM14 2v6h6M8 13h8M8 17h6',
 'trash'=>'M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6',
 'edit'=>'m16 3 5 5-12 12-6 1 1-6L16 3ZM14 5l5 5',
 'camera'=>'M3 6h4l2-3h6l2 3h4v14H3V6ZM16 13a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
 'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M13 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z'
])
<svg {{ $attributes->class(['button-icon']) }} width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] ?? $paths['plus'] }}"/></svg>
