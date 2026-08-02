@extends('admin.layout')

@section('title', 'Mensaje #'.$message->id)

@section('content')

<a href="{{ route('admin.messages.index') }}" class="mb-5 inline-block text-[0.9rem] text-air hover:underline">← Volver al log</a>

<div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
  <h1 class="sec-title">{{ $message->senderName() }}</h1>
  @include('admin.partials.status', ['status' => $message->status])
</div>

<section class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
  <div class="grid min-w-0 grid-cols-1 content-start gap-4">
    <div class="rounded-xl border border-line bg-ink p-5 sm:p-6">
      <div class="eyebrow mb-3">Recibido · {{ $message->localTime()->format('d/m/Y H:i:s') }}</div>
      <p class="break-words whitespace-pre-wrap">{{ $message->body ?: '(sin texto)' }}</p>

      @if ($message->button_payload)
        <p class="mt-4 text-[0.9rem] text-muted">
          Llegó como botón: <code class="font-mono text-raw">{{ $message->button_payload }}</code>
        </p>
      @endif
    </div>

    @if ($message->error)
      <div class="rounded-xl border border-red-200 bg-red-50 p-6">
        <div class="eyebrow mb-2 text-red-800">Error</div>
        <p class="font-mono text-[0.85rem] break-words text-red-900">{{ $message->error }}</p>
      </div>
    @endif

    <div class="rounded-xl border border-line bg-ink p-5 sm:p-6">
      <div class="eyebrow mb-4">
        Respuesta del bot
        @if ($message->reply)
          · {{ count($message->reply) }} {{ count($message->reply) === 1 ? 'mensaje' : 'mensajes' }}
        @endif
      </div>

      @forelse ($message->reply ?? [] as $i => $part)
        <div class="mb-3 rounded-xl rounded-tl-sm border border-line-soft bg-panel p-4 last:mb-0">
          @if (count($message->reply) > 1)
            <div class="mb-2 font-mono text-[0.75rem] text-dim">{{ $i + 1 }}/{{ count($message->reply) }}</div>
          @endif
          <p class="text-[0.95rem] leading-relaxed break-words whitespace-pre-wrap">{{ $part }}</p>
        </div>
      @empty
        <p class="text-[0.9rem] text-dim">
          {{ $message->status === 'pending' ? 'Todavía en cola.' : 'No se registró ninguna respuesta.' }}
        </p>
      @endforelse
    </div>
  </div>

  <div class="grid min-w-0 grid-cols-1 content-start gap-4">
    <div class="rounded-xl border border-line bg-ink p-5 sm:p-6">
      <div class="eyebrow mb-4">Qué entendió el bot</div>
      <dl class="grid gap-3 text-[0.92rem]">
        <div class="flex justify-between gap-3">
          <dt class="text-muted">Tema</dt>
          <dd>{{ $message->topicLabel() }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-muted">Aeródromo</dt>
          <dd class="text-right">
            @if ($message->anac_code)
              <span class="font-mono font-semibold text-raw">{{ $message->anac_code }}</span>
              @if ($message->icao_code)
                <span class="font-mono text-dim">/ {{ $message->icao_code }}</span>
              @endif
              <div class="text-[0.85rem] text-dim">{{ $message->airport?->name }}</div>
            @else
              <span class="text-dim">Ninguno</span>
            @endif
          </dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-muted">Demora</dt>
          <dd class="font-mono tabular-nums">
            {{ $message->duration_ms === null ? '—' : ($message->duration_ms >= 1000 ? round($message->duration_ms / 1000, 1).' s' : $message->duration_ms.' ms') }}
          </dd>
        </div>
      </dl>
    </div>

    <div class="rounded-xl border border-line bg-ink p-5 sm:p-6">
      <div class="eyebrow mb-4">Remitente</div>
      <dl class="grid gap-3 text-[0.92rem]">
        <div class="flex justify-between gap-3">
          <dt class="text-muted">Nombre</dt>
          <dd>{{ $message->profile_name ?: 'No informado' }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-muted">Teléfono</dt>
          <dd class="font-mono">{{ $message->phoneNumber() }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-muted">SID</dt>
          {{-- El SID de Meta es una sola palabra de 60 caracteres: sin cortarla
               estira la ficha entera fuera de la pantalla del celular. --}}
          <dd class="font-mono text-[0.8rem] break-all text-dim">{{ $message->message_sid ?: '—' }}</dd>
        </div>
      </dl>

      <a href="{{ route('admin.messages.index', ['phone' => $message->phone]) }}"
         class="mt-5 inline-block text-[0.9rem] text-air hover:underline">Ver todos sus mensajes</a>
    </div>
  </div>
</section>

<section class="rounded-xl border border-line bg-ink p-5 sm:p-6">
  <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Resto de la conversación</h2>
  <p class="mb-5 text-[0.9rem] text-muted">Los últimos mensajes de este mismo número.</p>

  @include('admin.partials.messages-table', ['messages' => $conversation])
</section>

@endsection
