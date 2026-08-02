@php
    // A zero column still gets a hairline: an empty day has to read as "nobody
    // wrote", not as a hole in the chart.
    $max = max(1, collect($items)->max('count'));
    $everyLabel = $everyLabel ?? 1;
@endphp

<div class="flex items-end gap-[3px]" style="height: {{ $height ?? 150 }}px">
  @foreach ($items as $item)
    <div class="flex h-full flex-1 flex-col justify-end" title="{{ $item['label'] }} · {{ $item['count'] }}">
      <div class="w-full rounded-t-sm {{ $item['count'] > 0 ? 'bg-air' : 'bg-line' }}"
           style="height: {{ $item['count'] > 0 ? max(2, round($item['count'] * 100 / $max)) : 2 }}%"></div>
    </div>
  @endforeach
</div>

{{-- min-w-0 deja que todas las celdas midan lo mismo aunque la fecha sea más
     ancha que su columna: en un celular, sin eso, las etiquetas se reparten el
     espacio a codazos y dejan de caer bajo su barra. Se desbordan sobre la
     celda vecina, que está vacía. --}}
<div class="mt-2 flex gap-[3px] font-mono text-[0.65rem] text-dim">
  @foreach ($items as $i => $item)
    <div class="min-w-0 flex-1 text-center whitespace-nowrap">{{ $i % $everyLabel === 0 ? $item['label'] : '' }}</div>
  @endforeach
</div>
