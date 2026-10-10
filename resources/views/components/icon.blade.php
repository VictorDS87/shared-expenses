@props(['name' => 'grid', 'size' => 20])
<svg {{ $attributes->merge(['class' => 'icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('receipt')<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 7h6M9 11h6M9 15h3"/>@break
@case('building')<path d="M3 21h18M5 21V4h10v17M15 10h4v11M8 8h1M11 8h1M8 12h1M11 12h1M8 16h1M11 16h1"/>@break
@case('chart')<path d="M4 3v17h17M9 16v-5M14 16V7M19 16V4"/>@break
@case('upload')<path d="M12 16V3m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>@break
@case('download')<path d="M12 3v13m-5-5 5 5 5-5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('arrow-right')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
@case('arrow-left')<path d="M20 12H4m6-6-6 6 6 6"/>@break
@case('chevron')<path d="m9 5 7 7-7 7"/>@break
@case('chevron-down')<path d="m6 9 6 6 6-6"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('check')<path d="m5 12 4 4L19 6"/>@break
@case('check-circle')<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>@break
@case('alert')<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v4m0 3v1"/>@break
@case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>@break
@case('wallet')<path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0-3 3v13a2 2 0 0 0 2 2h15V8H6a2 2 0 0 1 0-4"/><path d="M20 12h-5v5h5M16 14.5h.01"/>@break
@case('globe')<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>@break
@case('logout')<path d="M9 4H4v16h5M9 12h12m-5-5 5 5-5 5"/>@break
@case('edit')<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-4-4L5 15l-1 5Z"/>@break
@case('trash')<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>@break
@case('refresh')<path d="M20 8a8 8 0 0 0-14-3L3 8m0-5v5h5M4 16a8 8 0 0 0 14 3l3-3m0 5v-5h-5"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
@case('file')<path d="M14 2H5v20h14V7l-5-5Zm0 0v5h5M8 12h8M8 16h8"/>@break
@case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
@case('split')<path d="M12 21V11M5 3v4h14V3M5 3l-3 3m3-3 3 3M19 3l-3 3m3-3 3 3M12 7v4"/>@break
@default<circle cx="12" cy="12" r="8"/>
@endswitch
</svg>
