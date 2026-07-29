@php($max = max(1, collect($rows)->max('total')))

@if (count($rows) === 0)
  <p class="text-[0.9rem] text-dim">{{ $empty ?? 'Todavía no hay datos.' }}</p>
@else
  <ul class="grid gap-3.5">
    @foreach ($rows as $row)
      <li>
        <div class="mb-1.5 flex items-baseline justify-between gap-3">
          <span class="truncate text-[0.95rem]">
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
