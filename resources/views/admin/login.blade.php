<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar al panel · FlyBot</title>
<link rel="icon" href="/favicon.ico" sizes="any">
@vite('resources/css/app.css')
</head>
<body class="bg-panel">

<div class="mx-auto flex min-h-screen w-full max-w-[420px] flex-col justify-center px-6 py-12">
  <div class="mb-8 flex items-center gap-3">
    <img src="/images/flybot-logo-128.webp" alt="" width="40" height="40" class="size-10">
    <div>
      <div class="font-semibold tracking-[-0.02em]">FlyBot</div>
      <div class="text-[0.9rem] text-dim">Panel de administración</div>
    </div>
  </div>

  <form method="POST" action="{{ route('admin.login.store') }}" class="rounded-xl border border-line bg-ink p-6">
    @csrf

    @error('email')
      <p class="mb-5 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[0.9rem] text-red-800">{{ $message }}</p>
    @enderror

    <label class="mb-1.5 block text-[0.9rem] font-medium" for="email">Correo</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
           class="mb-5 w-full rounded-lg border border-line bg-ink px-3 py-2.5 outline-none focus:border-air">

    <label class="mb-1.5 block text-[0.9rem] font-medium" for="password">Contraseña</label>
    <input id="password" name="password" type="password" required autocomplete="current-password"
           class="mb-5 w-full rounded-lg border border-line bg-ink px-3 py-2.5 outline-none focus:border-air">

    <label class="mb-6 flex items-center gap-2 text-[0.9rem] text-muted">
      <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line">
      Mantener la sesión abierta
    </label>

    <button type="submit" class="w-full rounded-lg bg-air px-6 py-3 font-medium text-white hover:bg-[#0a4fae]">
      Entrar
    </button>
  </form>

</div>

</body>
</html>
