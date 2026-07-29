@php
    $styles = [
        'answered' => ['Respondido', 'border-emerald-200 bg-emerald-50 text-emerald-800'],
        'failed' => ['Falló', 'border-red-200 bg-red-50 text-red-800'],
        'pending' => ['En cola', 'border-amber-200 bg-amber-50 text-amber-800'],
    ];
    [$label, $classes] = $styles[$status] ?? [$status, 'border-line bg-panel text-muted'];
@endphp

<span class="inline-flex rounded-md border px-2 py-0.5 text-[0.75rem] font-medium whitespace-nowrap {{ $classes }}">{{ $label }}</span>
