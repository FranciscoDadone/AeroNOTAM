<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FlyBot — NOTAM, METAR y TAF de Argentina, en español y por WhatsApp</title>
<meta name="description" content="Es gratis: escribí «EZE» por WhatsApp y recibí los NOTAM, el METAR, el TAF, el PRONAREA y más de cualquier aeródromo argentino, decodificados a español legible.">
<meta name="theme-color" content="#ffffff">
<meta property="og:title" content="FlyBot — NOTAM, METAR y TAF en español">
<meta property="og:description" content="Gratis: el parte aeronáutico argentino, decodificado y por WhatsApp.">
<meta property="og:type" content="website">
<meta property="og:image" content="{{ url('/images/flybot-og.jpg') }}">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/images/flybot-touch.png">
@vite('resources/css/app.css')
</head>
<body>

@include('partials.nav')

<header id="top" class="py-14 sm:py-20">
  <div class="mx-auto grid w-full max-w-[1120px] grid-cols-1 items-start gap-12 px-6 lg:grid-cols-[1fr_360px] lg:gap-16">
    <div>
      <div class="flex flex-wrap items-center gap-2.5">
        <span class="inline-flex items-center rounded-lg border border-air/25 bg-air-soft px-3 py-1.5 font-mono text-xs font-medium tracking-[0.1em] text-air uppercase">
          Tu asistente aeronáutico · Argentina
        </span>
        <span class="inline-flex items-center rounded-lg border border-wa/25 bg-[#e7f7ee] px-3 py-1.5 font-mono text-xs font-medium tracking-[0.1em] text-wa uppercase">
          Gratis
        </span>
      </div>

      <h1 class="mt-6 mb-5 text-[clamp(2.1rem,4.3vw,3.1rem)] font-semibold leading-[1.08] tracking-[-0.03em]">
        NOTAM, METAR y TAF de los aeródromos argentinos,
        <span class="text-air">en español</span> y por WhatsApp.
      </h1>

      <p class="max-w-[34em] text-[clamp(1.08rem,1.6vw,1.25rem)] leading-relaxed text-muted">
        Escribí <b class="font-semibold text-body">«EZE»</b> y recibí, gratis y sin límite de
        consultas, los NOTAM activos, el estado del tiempo y el pronóstico de cualquier
        aeródromo de Argentina — decodificados a español legible, con el texto crudo siempre
        a la vista.
      </p>

      <div id="empezar" class="mt-9 max-w-[580px] rounded-2xl border border-line bg-panel p-6 sm:p-8">
        <div class="mb-6 flex items-baseline justify-between gap-4">
          <h2 class="text-xl font-semibold tracking-[-0.015em]">Empezá en 30 segundos</h2>
          <span class="hidden font-mono text-xs text-dim sm:block">gratis · sin registro · sin app</span>
        </div>

        <ol class="grid gap-5">
          <li class="grid grid-cols-[28px_1fr] items-start gap-4">
            <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">1</span>
            <p class="text-muted">
              Abrí el chat con
              <span class="rounded-md border border-line bg-ink px-2 py-0.5 font-mono text-[0.92em] font-medium whitespace-nowrap text-body">{{ $number ? '+'.$number : 'el bot' }}</span>
              desde el botón de acá abajo, o guardá el número en tu agenda.
            </p>
          </li>
          <li class="grid grid-cols-[28px_1fr] items-start gap-4">
            <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">2</span>
            <p class="text-muted">
              Escribí el aeródromo: <strong class="font-semibold text-body">«EZE»</strong>,
              <strong class="font-semibold text-body">«SAEZ»</strong>,
              <strong class="font-semibold text-body">«Ezeiza»</strong> o directamente
              <strong class="font-semibold text-body">«¿hay notams en Aeroparque?»</strong>.
            </p>
          </li>
          <li class="grid grid-cols-[28px_1fr] items-start gap-4">
            <span class="grid size-7 place-items-center rounded-lg border border-line bg-ink font-mono text-sm font-medium text-air">3</span>
            <p class="text-muted">
              ¿Querés el clima? <strong class="font-semibold text-body">«metar EZE»</strong>.
              ¿El pronóstico? <strong class="font-semibold text-body">«taf EZE»</strong>.
              ¿Que te avise si cambia? <strong class="font-semibold text-body">«avisame EZE»</strong>.
            </p>
          </li>
        </ol>

        <div class="mt-8 flex flex-wrap gap-3">
          @if ($link)
            <a href="{{ $link }}" target="_blank" rel="noopener" class="btn bg-wa text-white hover:bg-[#0b5f33]">Abrir en WhatsApp →</a>
          @endif
          <a href="#que-hace" class="btn border border-line bg-ink hover:bg-panel-2">Ver todo lo que responde</a>
        </div>
      </div>
    </div>

    <div class="mx-auto w-full max-w-[360px] rounded-3xl border border-line bg-ink p-3 shadow-[0_18px_50px_-24px_rgba(15,21,27,.35)]" aria-hidden="true">
      <div class="overflow-hidden rounded-2xl border border-line-soft">
        <div class="flex items-center gap-3 border-b border-line bg-panel-2 px-4 py-3">
          <img src="/images/flybot-logo-128.webp" alt="" width="34" height="34" class="size-8.5 rounded-full">
          <div>
            <b class="block text-[0.9rem] font-semibold tracking-[-0.01em]">FlyBot</b>
            <small class="text-[0.75rem] font-medium text-wa">en línea</small>
          </div>
        </div>

        <div class="grid gap-3 bg-panel px-3.5 py-4 text-[0.82rem] leading-relaxed">
          <div class="max-w-[88%] justify-self-end rounded-xl rounded-br-sm bg-[#d7f4c8] px-3 py-2 font-medium">metar EZE</div>

          <div class="max-w-[92%] justify-self-start rounded-xl rounded-bl-sm border border-line-soft bg-ink px-3 py-2.5">
            <b class="font-semibold">Ministro Pistarini (SAEZ)</b>
            <code class="raw my-2 block text-[0.7rem] leading-normal break-words">SAEZ 281700Z 03009KT 9999 BKN008 15/14 Q1009</code>
            <b class="font-semibold">Qué dice</b>
            <ul class="mt-1 grid gap-1 text-muted">
              <li>• Viento del 030° (NNE) a 9 nudos.</li>
              <li>• Visibilidad 10 km o más.</li>
              <li>• Nubosidad rota (5 a 7 octavos) a 800 ft.</li>
              <li>• Temperatura 15 °C, rocío 14 °C.</li>
              <li>• Presión QNH 1009 hPa.</li>
            </ul>
            <span class="mt-2 block text-[0.7rem] text-dim">Fuente: Servicio Meteorológico Nacional</span>
            <div class="mt-2.5 border-t border-line-soft pt-2.5 text-center text-[0.8rem] font-semibold text-air">Avisarme 12 h</div>
          </div>

          <div class="max-w-[88%] justify-self-end rounded-xl rounded-br-sm bg-[#d7f4c8] px-3 py-2 font-medium">¿va a llover mañana?</div>

          <div class="max-w-[92%] justify-self-start rounded-xl rounded-bl-sm border border-line-soft bg-ink px-3 py-2.5">
            <b class="font-semibold">Pronóstico (TAF)</b>
            <code class="raw my-2 block text-[0.7rem] leading-normal break-words">TEMPO 2808/2812 0500 FGDZ BKN005</code>
            <ul class="mt-1 grid gap-1 text-muted">
              <li>• Temporario el 28 entre 08:00 y 12:00 UTC…</li>
            </ul>
          </div>

          <div class="max-w-[88%] justify-self-end rounded-xl rounded-br-sm bg-[#d7f4c8] px-3 py-2 font-medium">qué pista conviene?</div>

          <div class="max-w-[92%] justify-self-start rounded-xl rounded-bl-sm border border-line-soft bg-ink px-3 py-2.5">
            <b class="font-semibold">Viento en pista — SAEZ</b>
            <ul class="mt-1 grid gap-1 text-muted">
              <li>✅ RWY 35 — de frente 15 kt · cruzado 1 kt (izq)</li>
              <li>RWY 17 — de cola 15 kt · cruzado 1 kt (izq)</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<section id="que-hace" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Qué le podés pedir</span>
      <h2 class="sec-title mt-3 mb-4">Ocho respuestas, un solo chat.</h2>
      <p class="text-lg text-muted">
        Escribís como hablás. El bot resuelve de qué aeródromo hablás y qué le estás
        preguntando; si el nombre es ambiguo —Córdoba tiene tres— pregunta en vez de
        elegir por vos.
      </p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      <div class="card">
        <h3>NOTAM activos</h3>
        <p>Directo de ANAC, con la vigencia de cada uno y traducidos del telegrama a español corrido.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"hay notams en EZE?"</span></div>
      </div>
      <div class="card">
        <h3>METAR</h3>
        <p>La observación vigente del aeródromo: viento, visibilidad, nubes, temperatura y QNH, grupo por grupo.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"clima en Bariloche"</span></div>
      </div>
      <div class="card">
        <h3>TAF</h3>
        <p>El pronóstico de 24 a 30 h, con cada período (BECMG, TEMPO, PROB30, FM) explicado por separado.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"pronóstico de Aeroparque"</span></div>
      </div>
      <div class="card">
        <h3>PRONAREA</h3>
        <p>El pronóstico de área que emite el SMN por FIR, para cuando lo que importa es la ruta y no sólo el aeródromo de destino.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"pronarea EZE"</span></div>
      </div>
      <div class="card">
        <h3>AEROMET</h3>
        <p>Si el aeródromo no tiene METAR —o directamente no tiene código OACI—, la observación de la estación AEROMET más cercana, la misma red que también cubre localidades sin aeródromo.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"aeromet junín"</span></div>
      </div>
      <div class="card">
        <h3>Viento en pista</h3>
        <p>Componente a favor y cruzado para cada cabecera, calculado con el METAR —o el AEROMET si no hay— contra el rumbo real de cada pista.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"viento cruzado en Ezeiza"</span></div>
      </div>
      <div class="card">
        <h3>Crepúsculo</h3>
        <p>Salida y puesta del sol para cualquier aeródromo del país: la tabla del Servicio de Hidrografía Naval donde la publica, calculada sobre las coordenadas del aeródromo donde no.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"crepúsculo Santa Rosa"</span></div>
      </div>
      <div class="card">
        <h3>Alertas</h3>
        <p>El bot te escribe solo cuando el clima cambia de verdad. Un botón en cada METAR o una frase.</p>
        <div class="mt-auto pt-5"><span class="rounded-md bg-air-soft px-2.5 py-1 font-mono text-[0.78rem] font-medium text-air">"avisame EZE"</span></div>
      </div>
    </div>

    <div class="mt-14 grid gap-10 lg:grid-cols-2 lg:gap-14">
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold tracking-[-0.01em]">Entiende cómo escribís</h3>
        <div class="flex flex-wrap gap-2.5">
          <span class="chip">EZE</span>
          <span class="chip">SAEZ</span>
          <span class="chip">ezeiza</span>
          <span class="chip">hay notams en Ezeiza?</span>
          <span class="chip">viento en SAEZ</span>
          <span class="chip">cómo está el clima en Bariloche?</span>
          <span class="chip">va a llover en SAEZ?</span>
          <span class="chip">cómo va a estar el tiempo mañana?</span>
          <span class="chip">pronarea EZE</span>
          <span class="chip">aeromet junín</span>
          <span class="chip">qué pista conviene?</span>
          <span class="chip">crepúsculo Santa Rosa</span>
          <span class="chip">avisame EZE por 6 horas</span>
          <span class="chip">mis alertas</span>
          <span class="chip">baja EZE</span>
        </div>
      </div>
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold tracking-[-0.01em]">Y desambigua en vez de adivinar</h3>
        <ul class="checks">
          <li><span><strong>Una pregunta sobre mañana</strong> se contesta con el pronóstico, no con la observación de ahora.</span></li>
          <li><span>Si escribiste <strong>«notam»</strong>, eso recibís: sabés lo que pediste.</span></li>
          <li><span>Si el nombre coincide con varios aeródromos, te muestra la lista y espera el código. Mandar los NOTAM del aeródromo equivocado es peor que repreguntar.</span></li>
          <li><span>Después de cada respuesta, un botón para pedir lo que sigue del mismo aeródromo —NOTAM, METAR, TAF o crepúsculo— sin volver a escribir el código.</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="decodifica" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Decodificación</span>
      <h2 class="sec-title mt-3 mb-4">De telegrama a español.</h2>
      <p class="text-lg text-muted">
        El texto crudo nunca se omite: es la forma estándar internacional contra la
        que podés contrastar cualquier otra fuente. La explicación va debajo.
      </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-line">
      @foreach ([
        ['NOTAM', 'RWY 13/31 CLSD WIP MAINT', 'Pista 13/31 cerrada por trabajos de mantenimiento en progreso.'],
        ['METAR', '03009KT 9999 BKN008 15/14 Q1009', 'Viento del 030° (NNE) a 9 nudos. Visibilidad 10 km o más. Nubosidad rota (5 a 7 octavos) a 800 ft. Temperatura 15 °C, punto de rocío 14 °C. Presión QNH 1009 hPa.'],
        ['TAF', 'TEMPO 2808/2812 0500 FGDZ BKN005', 'Fluctuaciones temporarias el día 28 entre las 08:00 y las 12:00 UTC, en períodos de menos de una hora cada vez: visibilidad 500 m, niebla y llovizna, nubosidad rota a 500 ft.'],
      ] as $i => [$kind, $raw, $meaning])
        <div class="grid items-stretch md:grid-cols-[minmax(0,1fr)_48px_minmax(0,1.25fr)] {{ $i > 0 ? 'border-t border-line' : '' }}">
          <div class="flex min-w-0 flex-col justify-center border-b border-line bg-panel px-6 py-6 md:border-b-0">
            <span class="eyebrow mb-3 block">{{ $kind }}</span>
            <code class="font-mono text-[0.92rem] font-medium break-words text-raw">{{ $raw }}</code>
          </div>
          <div class="hidden place-items-center font-mono text-dim md:grid">→</div>
          <div class="flex min-w-0 flex-col justify-center px-6 py-6">
            <span class="eyebrow mb-3 block">Lo que significa</span>
            <p class="text-muted">{{ $meaning }}</p>
          </div>
        </div>
      @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
      <div class="card">
        <h3>El crudo, siempre arriba</h3>
        <p>Cada respuesta empieza por el reporte tal como se emitió. La explicación va debajo, y podés ignorarla: nunca reemplaza al texto que estás acostumbrado a leer.</p>
      </div>
      <div class="card">
        <h3>Nada se inventa</h3>
        <p>Un grupo que no se reconoce aparece marcado como tal, nunca traducido a lo que suena razonable ni omitido en silencio. Si algo no está explicado, se ve.</p>
      </div>
      <div class="card">
        <h3>Siempre el vigente</h3>
        <p>Un METAR queda superado en cuanto sale el siguiente, así que recibís ese y no una lista vieja. Del TAF, además, te avisa si viene enmendado (AMD) o cancelado (CNL).</p>
      </div>
      <div class="card">
        <h3>Aeródromo cerrado, dicho de entrada</h3>
        <p>Si el aeródromo está cerrado, el bot lo pone en el encabezado del NOTAM, no como un ítem más de la lista — para que no se lea como «está todo bien» cuando no lo está.</p>
      </div>
    </div>

    <div class="mt-14 grid gap-5 lg:grid-cols-2">
      <div class="card p-7">
        <h3>METAR y TAF no son lo mismo</h3>
        <p>
          Un METAR es una <strong class="font-semibold text-body">observación</strong>: qué está pasando
          ahora en el aeródromo. Un TAF es un <strong class="font-semibold text-body">pronóstico</strong>:
          qué se espera en las próximas 24 o 30 horas. Por eso son dos respuestas distintas, y una
          pregunta sobre mañana nunca se contesta con la observación de recién.
        </p>
      </div>
      <div class="card p-7">
        <h3>El TEMPO, dicho como es</h3>
        <p>
          Un TAF no es una foto sino una secuencia: <code>BECMG</code>, <code>TEMPO</code>,
          <code>PROB30</code> y <code>FM</code> abren cada uno su ventana, y lo que sigue vale sólo
          dentro de ella. Cada cambio se explica por separado, con su horario, y el <code>TEMPO</code>
          aclara que las condiciones pueden aparecer por menos de una hora cada vez — no durante toda
          la ventana.
        </p>
      </div>
    </div>
  </div>
</section>

<section id="alertas" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">Alertas</span>
      <h2 class="sec-title mt-3 mb-4">«Avisame si cambia» — y sólo si cambia.</h2>
      <p class="text-lg text-muted">
        El METAR se reemite cada hora y casi siempre difiere del anterior: un grado de
        temperatura, dos nudos de viento. Avisar por eso sería un despertador horario,
        así que la comparación se hace sobre los grupos que un piloto efectivamente acciona.
      </p>
    </div>

    <div class="grid gap-10 lg:grid-cols-2 lg:gap-14">
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold">Qué cuenta como un cambio</h3>
        <ul class="checks">
          <li><span>La llegada de un <strong>SPECI</strong> — lo emitió el SMN porque algo cruzó un umbral, y ese criterio manda.</span></li>
          <li><span><strong>Categoría de vuelo</strong> (VFR / MVFR / IFR / LIFR), siempre por el peor entre techo y visibilidad.</span></li>
          <li><span><strong>Viento</strong>: ±10 kt, aparición o desaparición de ráfaga, o ±30° de dirección — pero sólo con 10 kt o más, porque abajo de eso la dirección deambula sola.</span></li>
          <li><span><strong>Visibilidad y techo</strong> que cruzan una banda (800 / 1500 / 3000 / 5000 / 8000 m; 200 / 500 / 1000 / 1500 / 3000 ft).</span></li>
          <li><span><strong>Fenómenos</strong> que empiezan, terminan o cambian de intensidad (<code>-RA</code> → <code>+TSRA</code>).</span></li>
          <li><span><strong>QNH</strong> de ±2 hPa.</span></li>
        </ul>
      </div>
      <div>
        <h3 class="mb-5 text-[1.15rem] font-semibold">Cómo se manejan</h3>
        <div class="mb-7 flex flex-wrap gap-2.5">
          <span class="chip">avisame EZE</span>
          <span class="chip">avisame EZE por 6 horas</span>
          <span class="chip">mis alertas</span>
          <span class="chip">baja EZE</span>
          <span class="chip">baja todas</span>
        </div>
        <ul class="checks">
          <li><span>Cada METAR trae un botón <strong>Avisarme 12 h</strong>, y cada aviso uno de <strong>Dar de baja</strong>. Escrito funciona igual.</span></li>
          <li><span>Duran <strong>12 horas</strong> por defecto y 24 como máximo. Volver a suscribirte la renueva.</span></li>
          <li><span>Hasta <strong>5 aeródromos</strong> a la vez, y el bot los revisa cada diez minutos.</span></li>
          <li><span>Cuando una alerta vence te lo dice. Una que simplemente dejara de escribir sería idéntica a una que no tuvo nada que informar — y «el clima no cambió» es justo lo que alguien podría dar por sentado.</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="fuentes" class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="mb-12 max-w-[42em]">
      <span class="eyebrow">De dónde sale</span>
      <h2 class="sec-title mt-3 mb-4">Los mismos partes que consultás.</h2>
      <p class="text-lg text-muted">
        Nada de acá se elabora: son los NOTAM que publica ANAC y los METAR y TAF que
        emite el Servicio Meteorológico Nacional, tal como salieron. Lo único que agrega
        el bot es la lectura en español debajo del texto.
      </p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
      <div class="card">
        <h3>NOTAM — ANAC</h3>
        <p>Los NOTAM activos del aeródromo, con la fecha desde la que rigen y hasta cuándo, o si son permanentes.</p>
      </div>
      <div class="card">
        <h3>METAR y TAF — SMN</h3>
        <p>La observación y el pronóstico de aeródromo emitidos por el Servicio Meteorológico Nacional, que es la autoridad para los aeródromos argentinos.</p>
      </div>
      <div class="card">
        <h3>Si el SMN no responde</h3>
        <p>El reporte llega igual por el intercambio internacional OPMET: es el mismo que emitió el SMN, retransmitido. Cada mensaje aclara al pie cuándo pasó por ahí.</p>
      </div>
    </div>
  </div>
</section>

<section class="border-t border-line-soft py-16 sm:py-24">
  <div class="mx-auto w-full max-w-[1120px] px-6">
    <div class="rounded-2xl border border-line bg-panel px-6 py-12 text-center sm:px-10">
      <img src="/images/flybot-logo.webp" alt="FlyBot" width="104" height="104" class="mx-auto mb-6 size-26">
      <h2 class="text-[clamp(1.7rem,3vw,2.2rem)] font-semibold tracking-[-0.025em]">Probalo con tu aeródromo.</h2>
      <p class="mx-auto mt-4 mb-8 max-w-[52ch] text-lg text-muted">
        Mandale «EZE» —o cualquier aeródromo de Argentina— y ves la respuesta completa
        en el chat. Gratis, sin registro y sin instalar nada.
      </p>
      @if ($link)
        <a href="{{ $link }}" target="_blank" rel="noopener" class="btn bg-wa text-white hover:bg-[#0b5f33]">Abrir en WhatsApp →</a>
      @else
        <p class="text-dim">Todavía no hay un número publicado para este bot.</p>
      @endif
    </div>
  </div>
</section>

@include('partials.footer')

</body>
</html>
