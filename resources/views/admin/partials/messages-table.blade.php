@if (count($messages) === 0)
  <p class="text-[0.9rem] text-dim">No hay mensajes que coincidan.</p>
@else
  <div class="-mx-6 overflow-x-auto px-6">
    <table class="w-full min-w-[820px] border-collapse text-[0.92rem]">
      <thead>
        <tr class="border-b border-line text-left text-[0.78rem] tracking-[0.06em] text-dim uppercase">
          <th class="py-2 pr-4 font-medium whitespace-nowrap">Fecha</th>
          <th class="py-2 pr-4 font-medium">De</th>
          <th class="py-2 pr-4 font-medium">Mensaje</th>
          <th class="py-2 pr-4 font-medium">Tema</th>
          <th class="py-2 pr-4 font-medium">Aeródromo</th>
          <th class="py-2 pr-4 font-medium">Estado</th>
          <th class="py-2 text-right font-medium">Demora</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($messages as $message)
          <tr class="border-b border-line-soft last:border-0 hover:bg-panel">
            <td class="py-3 pr-4 font-mono text-[0.82rem] whitespace-nowrap text-muted">
              {{ $message->localTime()->format('d/m H:i') }}
            </td>
            <td class="max-w-[180px] py-3 pr-4">
              <div class="truncate">{{ $message->profile_name ?: '—' }}</div>
              <div class="truncate font-mono text-[0.78rem] text-dim">{{ $message->phoneNumber() }}</div>
            </td>
            <td class="max-w-[320px] py-3 pr-4">
              <a href="{{ route('admin.messages.show', $message) }}" class="block truncate hover:text-air hover:underline">
                {{ str($message->body)->limit(80) ?: '(botón)' }}
              </a>
              @if ($message->button_payload)
                <span class="font-mono text-[0.75rem] text-dim">botón · {{ $message->button_payload }}</span>
              @endif
            </td>
            <td class="py-3 pr-4 whitespace-nowrap text-muted">{{ $message->topicLabel() }}</td>
            <td class="py-3 pr-4 whitespace-nowrap">
              @if ($message->anac_code)
                <span class="font-mono text-[0.82rem] font-semibold text-raw">{{ $message->anac_code }}</span>
                <span class="text-dim">{{ $message->airport?->name ? str($message->airport->name)->limit(22) : '' }}</span>
              @else
                <span class="text-dim">—</span>
              @endif
            </td>
            <td class="py-3 pr-4">@include('admin.partials.status', ['status' => $message->status])</td>
            <td class="py-3 text-right font-mono text-[0.82rem] whitespace-nowrap text-muted tabular-nums">
              {{ $message->duration_ms === null ? '—' : ($message->duration_ms >= 1000 ? round($message->duration_ms / 1000, 1).' s' : $message->duration_ms.' ms') }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
