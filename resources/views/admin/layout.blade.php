<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title', 'Panel') · FlyBot</title>
<link rel="icon" href="/favicon.ico" sizes="any">
@vite('resources/css/app.css')
</head>
<body class="bg-panel">

<nav class="border-b border-line bg-ink">
  <div class="mx-auto flex h-16 w-full max-w-[1200px] items-center justify-between gap-6 px-6">
    <div class="flex items-center gap-7">
      <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 font-semibold tracking-[-0.02em]">
        <img src="/images/flybot-logo-128.webp" alt="" width="32" height="32" class="size-8">
        Panel
      </a>
      <div class="flex gap-6 text-[0.95rem] text-muted">
        <a href="{{ route('admin.dashboard') }}"
           class="{{ request()->routeIs('admin.dashboard') ? 'font-medium text-air' : 'hover:text-body' }}">Métricas</a>
        <a href="{{ route('admin.messages.index') }}"
           class="{{ request()->routeIs('admin.messages.*') ? 'font-medium text-air' : 'hover:text-body' }}">Mensajes</a>
      </div>
    </div>

    <form method="POST" action="{{ route('admin.logout') }}" class="flex items-center gap-4">
      @csrf
      <span class="hidden text-[0.9rem] text-dim sm:inline">{{ auth()->user()?->email }}</span>
      <button type="submit" class="rounded-lg border border-line px-3 py-1.5 text-[0.9rem] text-muted hover:border-air/45 hover:text-body">
        Salir
      </button>
    </form>
  </div>
</nav>

<main class="mx-auto w-full max-w-[1200px] px-6 py-8">
  @yield('content')
</main>

</body>
</html>
