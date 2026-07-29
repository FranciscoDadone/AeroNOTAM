@extends('admin.layout')

@section('title', 'Métricas')

@php
    $ms = fn (?int $v) => $v === null ? '—' : ($v >= 1000 ? round($v / 1000, 1).' s' : $v.' ms');
@endphp

@section('content')

<div class="mb-8 flex flex-wrap items-baseline justify-between gap-3">
  <h1 class="sec-title">Métricas</h1>
  <p class="text-[0.9rem] text-dim">
    Horarios en {{ config('app.display_timezone') }} · actualizado {{ now()->setTimezone(config('app.display_timezone'))->format('d/m H:i') }}
  </p>
</div>

<section class="mb-10 grid grid-cols-2 gap-4 lg:grid-cols-4">
  @include('admin.partials.stat', [
      'label' => 'Mensajes hoy',
      'value' => number_format($totals['today'], 0, ',', '.'),
      'hint' => number_format($totals['week'], 0, ',', '.').' en los últimos 7 días',
  ])
  @include('admin.partials.stat', [
      'label' => 'Mensajes totales',
      'value' => number_format($totals['messages'], 0, ',', '.'),
      'hint' => $totals['pending'] > 0 ? $totals['pending'].' sin responder todavía' : 'Ninguno en cola',
  ])
  @include('admin.partials.stat', [
      'label' => 'Personas distintas',
      'value' => number_format($totals['people'], 0, ',', '.'),
      'hint' => $totals['new_people_week'].' nuevas esta semana · '.$totals['returning_people'].' repiten',
  ])
  @include('admin.partials.stat', [
      'label' => 'Respuesta (p95, 7 días)',
      'value' => $ms($latency['p95']),
      'hint' => 'Mediana '.$ms($latency['p50']).' · máx '.$ms($latency['max']),
  ])
</section>

<section class="mb-6 grid gap-6 lg:grid-cols-[1.6fr_1fr]">
  <div class="rounded-xl border border-line bg-ink p-6">
    <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Mensajes por día</h2>
    <p class="mb-6 text-[0.9rem] text-muted">Últimas dos semanas.</p>
    @include('admin.partials.columns', ['items' => $perDay, 'height' => 170, 'everyLabel' => 2])
  </div>

  <div class="rounded-xl border border-line bg-ink p-6">
    <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Actividad por hora</h2>
    <p class="mb-6 text-[0.9rem] text-muted">Hora local, últimos 30 días.</p>
    @include('admin.partials.columns', ['items' => $perHour, 'height' => 170, 'everyLabel' => 4])
  </div>
</section>

<section class="mb-6 grid gap-6 lg:grid-cols-[1.6fr_1fr]">
  <div class="rounded-xl border border-line bg-ink p-6">
    <div class="mb-1 flex items-baseline justify-between gap-4">
      <h2 class="text-[1.05rem] font-semibold tracking-[-0.01em]">Consultas por aeródromo</h2>
      <a href="{{ route('admin.messages.index') }}" class="text-[0.85rem] text-air hover:underline">Ver mensajes</a>
    </div>
    <p class="mb-6 text-[0.9rem] text-muted">Histórico completo, por el aeródromo que el bot identificó.</p>

    @include('admin.partials.ranking', [
        'rows' => $topAirports->map(fn ($a) => [
            'code' => $a['anac_code'],
            'label' => $a['name'],
            'total' => $a['total'],
        ]),
        'empty' => 'Ningún mensaje resolvió un aeródromo todavía.',
    ])
  </div>

  <div class="grid content-start gap-6">
    <div class="rounded-xl border border-line bg-ink p-6">
      <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Esta semana</h2>
      <p class="mb-6 text-[0.9rem] text-muted">Los cinco más consultados en 7 días.</p>

      @include('admin.partials.ranking', [
          'rows' => $airportsWeek->map(fn ($a) => [
              'code' => $a['anac_code'],
              'label' => $a['name'],
              'total' => $a['total'],
          ]),
          'empty' => 'Sin consultas con aeródromo esta semana.',
      ])
    </div>

    <div class="rounded-xl border border-line bg-ink p-6">
      <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Qué le piden</h2>
      <p class="mb-6 text-[0.9rem] text-muted">Tema al que el bot enrutó cada mensaje.</p>

      @include('admin.partials.ranking', [
          'rows' => $topics->map(fn ($t) => ['label' => $t['label'], 'total' => $t['total']]),
          'empty' => 'Todavía no se procesó ningún mensaje.',
      ])
    </div>
  </div>
</section>

<section class="mb-6 grid gap-6 lg:grid-cols-2">
  <div class="rounded-xl border border-line bg-ink p-6">
    <div class="mb-1 flex items-baseline justify-between gap-4">
      <h2 class="text-[1.05rem] font-semibold tracking-[-0.01em]">Sin aeródromo identificado</h2>
      <a href="{{ route('admin.messages.index', ['unmatched' => 1]) }}" class="text-[0.85rem] text-air hover:underline">Ver todos</a>
    </div>
    <p class="mb-5 text-[0.9rem] text-muted">
      {{ $unmatched['rate'] }} % de los últimos 30 días ({{ $unmatched['total'] }} mensajes). Incluye saludos y
      preguntas sueltas, pero es donde aparecen los aeródromos que el bot no supo reconocer.
    </p>

    @forelse ($unmatched['samples'] as $sample)
      <a href="{{ route('admin.messages.show', $sample) }}"
         class="mb-2 block truncate rounded-lg border border-line-soft bg-panel px-3 py-2 text-[0.9rem] text-muted hover:border-air/45">
        «{{ str($sample->body)->limit(90) }}»
      </a>
    @empty
      <p class="text-[0.9rem] text-dim">Todos los mensajes resolvieron un aeródromo.</p>
    @endforelse
  </div>

  <div class="rounded-xl border border-line bg-ink p-6">
    <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Alertas METAR activas</h2>
    <p class="mb-5 text-[0.9rem] text-muted">
      {{ $subscriptions['active'] }} vigentes de {{ $subscriptions['people'] }} personas ·
      {{ $subscriptions['expiring_today'] }} vencen hoy.
    </p>

    @include('admin.partials.ranking', [
        'rows' => $subscriptions['per_airport']->map(fn ($s) => [
            'code' => $s['anac_code'],
            'label' => $s['name'],
            'total' => $s['total'],
        ]),
        'empty' => 'Nadie tiene una alerta activa en este momento.',
    ])
  </div>
</section>

<section class="mb-6 grid gap-6 lg:grid-cols-2">
  <div class="rounded-xl border border-line bg-ink p-6">
    <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Quiénes escriben más</h2>
    <p class="mb-5 text-[0.9rem] text-muted">Por cantidad de mensajes, histórico.</p>

    @forelse ($topUsers as $user)
      <a href="{{ route('admin.messages.index', ['phone' => $user['phone']]) }}"
         class="flex items-baseline justify-between gap-3 border-b border-line-soft py-2.5 last:border-0 hover:text-air">
        <span class="truncate">
          {{ $user['name'] ?: str($user['phone'])->after('whatsapp:') }}
          @if ($user['name'])
            <span class="font-mono text-[0.8rem] text-dim">{{ str($user['phone'])->after('whatsapp:') }}</span>
          @endif
        </span>
        <span class="shrink-0 text-[0.85rem] text-muted tabular-nums">
          {{ $user['total'] }} ·
          {{ $user['last_at']->setTimezone(config('app.display_timezone'))->format('d/m') }}
        </span>
      </a>
    @empty
      <p class="text-[0.9rem] text-dim">Todavía no escribió nadie.</p>
    @endforelse
  </div>

  <div class="rounded-xl border border-line bg-ink p-6">
    <h2 class="mb-1 text-[1.05rem] font-semibold tracking-[-0.01em]">Entrega</h2>
    <p class="mb-5 text-[0.9rem] text-muted">Cómo terminaron los mensajes de los últimos 7 días.</p>

    <dl class="grid grid-cols-2 gap-4">
      <div class="rounded-lg border border-line-soft bg-panel p-4">
        <dt class="eyebrow mb-1.5">Fallidos (7 días)</dt>
        <dd class="text-[1.4rem] font-semibold tabular-nums {{ $totals['failed_week'] > 0 ? 'text-red-700' : 'text-body' }}">
          {{ $totals['failed_week'] }}
        </dd>
      </div>
      <div class="rounded-lg border border-line-soft bg-panel p-4">
        <dt class="eyebrow mb-1.5">Sin responder</dt>
        <dd class="text-[1.4rem] font-semibold tabular-nums {{ $totals['pending'] > 0 ? 'text-amber-700' : 'text-body' }}">
          {{ $totals['pending'] }}
        </dd>
      </div>
      <div class="rounded-lg border border-line-soft bg-panel p-4">
        <dt class="eyebrow mb-1.5">Respuesta media</dt>
        <dd class="text-[1.4rem] font-semibold tabular-nums">{{ $ms($latency['avg']) }}</dd>
      </div>
      <div class="rounded-lg border border-line-soft bg-panel p-4">
        <dt class="eyebrow mb-1.5">Medidos</dt>
        <dd class="text-[1.4rem] font-semibold tabular-nums">{{ $latency['count'] }}</dd>
      </div>
    </dl>

    @if ($totals['failed_week'] > 0)
      <a href="{{ route('admin.messages.index', ['status' => 'failed']) }}"
         class="mt-5 inline-block text-[0.9rem] text-air hover:underline">Ver los mensajes que fallaron</a>
    @endif
  </div>
</section>

<section class="rounded-xl border border-line bg-ink p-6">
  <div class="mb-5 flex items-baseline justify-between gap-4">
    <h2 class="text-[1.05rem] font-semibold tracking-[-0.01em]">Últimos mensajes</h2>
    <a href="{{ route('admin.messages.index') }}" class="text-[0.85rem] text-air hover:underline">Ver el log completo</a>
  </div>

  @include('admin.partials.messages-table', ['messages' => $latest])
</section>

@endsection
