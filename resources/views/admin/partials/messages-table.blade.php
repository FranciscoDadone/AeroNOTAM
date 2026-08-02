@if (count($messages) === 0)
  <p class="text-[0.9rem] text-dim">No hay mensajes que coincidan.</p>
@else
  {{-- El margen negativo tiene que igualar el padding de la tarjeta que la
       contiene, o la tabla no llega a los bordes al desplazarse. En un celular
       las tres columnas accesorias se van: lo que se lee ahí es quién escribió
       qué, y el resto está en la ficha del mensaje. --}}
  <div class="-mx-5 overflow-x-auto px-5 sm:-mx-6 sm:px-6">
    <table class="w-full border-collapse text-[0.92rem] sm:min-w-[820px]">
      <thead>
        <tr class="border-b border-line text-left text-[0.78rem] tracking-[0.06em] text-dim uppercase">
          <th class="py-2 pr-3 font-medium whitespace-nowrap sm:pr-4">Fecha</th>
          <th class="py-2 pr-3 font-medium sm:pr-4">De</th>
          <th class="py-2 pr-3 font-medium sm:pr-4">Mensaje</th>
          <th class="hidden py-2 pr-4 font-medium sm:table-cell">Tema</th>
          <th class="hidden py-2 pr-4 font-medium sm:table-cell">Aeródromo</th>
          <th class="hidden py-2 pr-4 font-medium sm:table-cell">Estado</th>
          <th class="hidden py-2 text-right font-medium sm:table-cell">Demora</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($messages as $message)
          <tr class="border-b border-line-soft last:border-0 hover:bg-panel">
            <td class="py-3 pr-3 font-mono text-[0.82rem] text-muted sm:pr-4 sm:whitespace-nowrap">
              {{ $message->localTime()->format('d/m H:i') }}
            </td>
            <td class="max-w-[140px] py-3 pr-3 sm:max-w-[180px] sm:pr-4">
              <div class="truncate">{{ $message->profile_name ?: '—' }}</div>
              <div class="truncate font-mono text-[0.78rem] text-dim">{{ $message->phoneNumber() }}</div>
            </td>
            <td class="max-w-[140px] py-3 pr-3 sm:max-w-[320px] sm:pr-4">
              <a href="{{ route('admin.messages.show', $message) }}" class="block truncate hover:text-air hover:underline">
                {{-- En el celular la columna del aeródromo no entra, así que el
                     código viaja acá adelante, que es donde se lo busca. --}}
                @if ($message->anac_code)
                  <span class="font-mono text-[0.8rem] font-semibold text-raw sm:hidden">{{ $message->anac_code }}</span>
                @endif
                {{ str($message->body)->limit(80) ?: '(botón)' }}
              </a>
              @if ($message->button_payload)
                <span class="font-mono text-[0.75rem] text-dim">botón · {{ $message->button_payload }}</span>
              @endif
            </td>
            <td class="hidden py-3 pr-4 whitespace-nowrap text-muted sm:table-cell">{{ $message->topicLabel() }}</td>
            <td class="hidden py-3 pr-4 whitespace-nowrap sm:table-cell">
              @if ($message->anac_code)
                <span class="font-mono text-[0.82rem] font-semibold text-raw">{{ $message->anac_code }}</span>
                <span class="hidden text-dim sm:inline">{{ $message->airport?->name ? str($message->airport->name)->limit(22) : '' }}</span>
              @else
                <span class="text-dim">—</span>
              @endif
            </td>
            <td class="hidden py-3 pr-4 sm:table-cell">@include('admin.partials.status', ['status' => $message->status])</td>
            <td class="hidden py-3 text-right font-mono text-[0.82rem] whitespace-nowrap text-muted tabular-nums sm:table-cell">
              {{ $message->duration_ms === null ? '—' : ($message->duration_ms >= 1000 ? round($message->duration_ms / 1000, 1).' s' : $message->duration_ms.' ms') }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
