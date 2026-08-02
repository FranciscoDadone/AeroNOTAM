@php($max = max(1, collect($rows)->max('total')))

@if (count($rows) === 0)
  <p class="text-[0.9rem] text-dim">{{ $empty ?? 'Todavía no hay datos.' }}</p>
@else
  {{-- grid-cols-1 y no sólo grid: la columna implícita se mide contra el
       contenido, y un nombre de aeródromo que no corta estira la lista fuera de
       la pantalla por más que la fila de adentro sepa truncar. --}}
  <ul class="grid grid-cols-1 gap-3.5">
    @foreach ($rows as $row)
      <li>
        <div class="mb-1.5 flex items-baseline justify-between gap-3">
          {{-- min-w-0: sin eso el nombre largo de un aeródromo fija el ancho
               mínimo de la fila y estira la tarjeta más allá de la pantalla. --}}
          <span class="min-w-0 truncate text-[0.95rem]">
            @isset($row['code'])
              <span class="font-mono text-[0.8rem] font-semibold text-raw">{{ $row['code'] }}</span>
            @endisset
            {{ $row['label'] }}
          </span>
          <span class="shrink-0 font-mono text-[0.85rem] text-muted tabular-nums">{{ $row['total'] }}</span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-line-soft">
          <div class="h-full rounded-full bg-air" style="width: {{ max(2, round($row['total'] * 100 / $max)) }}%"></div>
        </div>
      </li>
    @endforeach
  </ul>
@endif
