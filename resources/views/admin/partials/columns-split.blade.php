@php
    // Como en columns.blade.php, un día sin nadie conserva su hairline. El
    // tramo sólido va abajo porque es el que se compara entre columnas: apoyado
    // en la base se lee de un vistazo, flotando arriba no.
    $max = max(1, collect($items)->max('count'));
    $everyLabel = $everyLabel ?? 1;
@endphp

<div class="flex items-end gap-[3px]" style="height: {{ $height ?? 150 }}px">
  @foreach ($items as $item)
    <div class="flex h-full flex-1 flex-col justify-end"
         title="{{ $item['label'] }} · {{ $item['count'] }} {{ $totalLabel ?? 'en total' }} · {{ $item['new'] }} {{ $partLabel ?? 'nuevas' }}">
      <div class="flex w-full flex-col justify-end overflow-hidden rounded-t-sm {{ $item['count'] > 0 ? '' : 'bg-line' }}"
           style="height: {{ $item['count'] > 0 ? max(2, round($item['count'] * 100 / $max)) : 2 }}%">
        @if ($item['count'] > 0)
          <div class="w-full bg-air/30" style="height: {{ 100 - round($item['new'] * 100 / $item['count']) }}%"></div>
          <div class="w-full bg-air" style="height: {{ round($item['new'] * 100 / $item['count']) }}%"></div>
        @endif
      </div>
    </div>
  @endforeach
</div>

{{-- Ver columns.blade.php sobre min-w-0. --}}
<div class="mt-2 flex gap-[3px] font-mono text-[0.65rem] text-dim">
  @foreach ($items as $i => $item)
    <div class="min-w-0 flex-1 text-center whitespace-nowrap">{{ $i % $everyLabel === 0 ? $item['label'] : '' }}</div>
  @endforeach
</div>
