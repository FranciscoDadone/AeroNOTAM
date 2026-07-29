@extends('admin.layout')

@section('title', 'Mensajes')

@section('content')

<div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
  <h1 class="sec-title">Mensajes entrantes</h1>
  <p class="text-[0.9rem] text-dim">
    {{ number_format($messages->total(), 0, ',', '.') }} en total · horarios en {{ config('app.display_timezone') }}
  </p>
</div>

<form method="GET" action="{{ route('admin.messages.index') }}"
      class="mb-6 grid gap-3 rounded-xl border border-line bg-ink p-4 md:grid-cols-[1.5fr_1fr_1fr_1fr_1fr_auto]">
  <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar en texto o respuesta"
         class="rounded-lg border border-line bg-ink px-3 py-2 text-[0.92rem] outline-none focus:border-air">

  <input type="search" name="phone" value="{{ $filters['phone'] ?? '' }}" placeholder="Teléfono"
         class="rounded-lg border border-line bg-ink px-3 py-2 text-[0.92rem] outline-none focus:border-air">

  <select name="airport" class="rounded-lg border border-line bg-ink px-3 py-2 text-[0.92rem] outline-none focus:border-air">
    <option value="">Todos los aeródromos</option>
    @foreach ($airports as $code => $name)
      <option value="{{ $code }}" @selected(($filters['airport'] ?? null) === $code)>{{ $code }} · {{ str($name)->limit(28) }}</option>
    @endforeach
  </select>

  <select name="topic" class="rounded-lg border border-line bg-ink px-3 py-2 text-[0.92rem] outline-none focus:border-air">
    <option value="">Todos los temas</option>
    @foreach ($topics as $topic)
      <option value="{{ $topic }}" @selected(($filters['topic'] ?? null) === $topic)>
        {{ \App\Models\WhatsappMessage::TOPIC_LABELS[$topic] ?? $topic }}
      </option>
    @endforeach
  </select>

  <select name="status" class="rounded-lg border border-line bg-ink px-3 py-2 text-[0.92rem] outline-none focus:border-air">
    <option value="">Cualquier estado</option>
    <option value="answered" @selected(($filters['status'] ?? null) === 'answered')>Respondido</option>
    <option value="failed" @selected(($filters['status'] ?? null) === 'failed')>Falló</option>
    <option value="pending" @selected(($filters['status'] ?? null) === 'pending')>En cola</option>
  </select>

  <div class="flex items-center gap-3">
    <button type="submit" class="rounded-lg bg-air px-4 py-2 text-[0.92rem] font-medium text-white hover:bg-[#0a4fae]">
      Filtrar
    </button>
    @if (collect($filters)->filter()->isNotEmpty())
      <a href="{{ route('admin.messages.index') }}" class="text-[0.9rem] text-muted hover:text-body">Limpiar</a>
    @endif
  </div>

  {{-- Sólo aparece cuando se llega desde el panel; se conserva al re-filtrar. --}}
  @if (($filters['unmatched'] ?? null) === '1')
    <input type="hidden" name="unmatched" value="1">
    <p class="text-[0.9rem] text-muted md:col-span-6">
      Mostrando sólo los mensajes en los que el bot no identificó ningún aeródromo.
    </p>
  @endif
</form>

<div class="rounded-xl border border-line bg-ink p-6">
  @include('admin.partials.messages-table', ['messages' => $messages])
</div>

@if ($messages->hasPages())
  <div class="mt-6 flex items-center justify-between gap-4 text-[0.9rem]">
    @if ($messages->onFirstPage())
      <span class="text-dim">Anterior</span>
    @else
      <a href="{{ $messages->previousPageUrl() }}" class="text-air hover:underline">← Anterior</a>
    @endif

    <span class="text-muted">Página {{ $messages->currentPage() }} de {{ $messages->lastPage() }}</span>

    @if ($messages->hasMorePages())
      <a href="{{ $messages->nextPageUrl() }}" class="text-air hover:underline">Siguiente →</a>
    @else
      <span class="text-dim">Siguiente</span>
    @endif
  </div>
@endif

@endsection
