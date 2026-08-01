{{--
  Shared by the landing page and the privacy notice. $home is what the section
  anchors hang off: empty on the landing, where the sections are on the page,
  and "/" anywhere else, where they are not.
--}}
@php($home = $home ?? '')

<nav class="sticky top-0 z-50 border-b border-line bg-ink/90 backdrop-blur">
  <div class="mx-auto flex h-16 w-full max-w-[1120px] items-center justify-between gap-6 px-6">
    <a href="{{ $home ?: '#top' }}" class="flex items-center gap-2.5 font-semibold tracking-[-0.02em]">
      <img src="/images/flybot-logo-128.webp" alt="FlyBot" width="36" height="36" class="size-9">
      FlyBot
    </a>
    <div class="hidden gap-7 text-[0.95rem] text-muted lg:flex">
      <a href="{{ $home }}#empezar" class="hover:text-body">Cómo empezar</a>
      <a href="{{ $home }}#que-hace" class="hover:text-body">Qué le pedís</a>
      <a href="{{ $home }}#decodifica" class="hover:text-body">Qué recibís</a>
      <a href="{{ $home }}#alertas" class="hover:text-body">Alertas</a>
      <a href="{{ $home }}#fuentes" class="hover:text-body">De dónde sale</a>
    </div>
    @if ($link)
      <a href="{{ $link }}" target="_blank" rel="noopener"
         class="rounded-lg bg-wa px-4 py-2.5 text-[0.95rem] font-medium whitespace-nowrap text-white hover:bg-[#0b5f33]">
        Abrir WhatsApp
      </a>
    @endif
  </div>
</nav>
