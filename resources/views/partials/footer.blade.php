@php($home = $home ?? '')

<footer class="border-t border-line py-12">
  <div class="mx-auto flex w-full max-w-[1120px] flex-wrap items-center justify-between gap-5 px-6">
    <div class="grid gap-1.5 text-[0.9rem] text-dim">
      <p>
        Hecho por <span class="font-medium text-body">Francisco Dadone</span> —
        <a href="mailto:dadonefran@gmail.com" class="text-air hover:underline">dadonefran@gmail.com</a>
      </p>
      <p>
        Ideado por <span class="font-medium text-body">Gabriel Jalabert</span> —
        <a href="mailto:gabyjalabert@gmail.com" class="text-air hover:underline">gabyjalabert@gmail.com</a>
      </p>
      <p>
        NOTAM de <a href="https://ais.anac.gob.ar" target="_blank" rel="noopener" class="text-air hover:underline">ANAC</a> ·
        METAR y TAF del <a href="https://www.smn.gob.ar/metar" target="_blank" rel="noopener" class="text-air hover:underline">Servicio Meteorológico Nacional</a>.
      </p>
    </div>
    <div class="flex gap-6 text-[0.9rem] text-muted">
      <a href="{{ $home }}#empezar" class="hover:text-body">Empezar</a>
      <a href="{{ $home }}#que-hace" class="hover:text-body">Qué le pedís</a>
      <a href="{{ $home }}#alertas" class="hover:text-body">Alertas</a>
      <a href="/privacidad" class="hover:text-body">Privacidad</a>
    </div>
  </div>
</footer>
