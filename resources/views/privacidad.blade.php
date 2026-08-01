<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacidad — FlyBot</title>
<meta name="description" content="Qué datos guarda FlyBot cuando le escribís por WhatsApp, para qué los usa y cómo pedir que se borren.">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="index, follow">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/images/flybot-touch.png">
@vite('resources/css/app.css')
</head>
<body>

@include('partials.nav', ['home' => '/'])

<main class="mx-auto w-full max-w-[760px] px-6 py-14 sm:py-20">

  <h1 class="text-[2rem] font-semibold tracking-[-0.02em] sm:text-[2.4rem]">Política de privacidad</h1>
  <p class="mt-3 text-[0.9rem] text-dim">Última actualización: 31 de julio de 2026</p>

  <p class="mt-8 text-muted">
    FlyBot es un bot de WhatsApp que responde consultas aeronáuticas sobre aeródromos
    argentinos: NOTAM, METAR, TAF, PRONAREA, salida y puesta del sol. Lo hace y lo
    mantiene <span class="font-medium text-body">Francisco Dadone</span> a título personal.
    Es gratuito y no tiene publicidad.
  </p>

  <p class="mt-4 text-muted">
    Esta página describe exactamente qué queda guardado cuando le escribís, para qué
    se usa y cómo pedir que se borre.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Qué se guarda</h2>

  <p class="mt-4 text-muted">Cuando le escribís al bot, se registra:</p>

  <ul class="mt-4 grid gap-2.5 text-muted">
    <li class="flex gap-3"><span class="text-air">·</span><span>Tu <span class="font-medium text-body">número de WhatsApp</span>.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>El <span class="font-medium text-body">nombre de perfil</span> que WhatsApp informa, si tenés uno puesto. Lo elegís vos y lo podés cambiar cuando quieras: sirve para ponerle una cara a la conversación, nunca para identificarte.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>El <span class="font-medium text-body">texto del mensaje</span> que enviaste, o el botón que tocaste.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>La <span class="font-medium text-body">respuesta</span> que dio el bot, junto con qué entendió (el tema y el aeródromo) y cuánto tardó.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>Si pediste que te avise de cambios en un aeródromo, esa <span class="font-medium text-body">suscripción</span> mientras esté activa.</span></li>
  </ul>

  <p class="mt-5 text-muted">
    No se pide ni se guarda tu nombre real, tu correo, tu ubicación ni ningún dato de
    pago. El bot no tiene forma de saber quién sos más allá del número desde el que
    escribís.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Para qué se usa</h2>

  <ul class="mt-4 grid gap-2.5 text-muted">
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">responder tu consulta</span>.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">mandarte los avisos</span> de cambio de clima que hayas pedido, y sólo mientras los tengas pedidos.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">revisar las consultas que el bot no entendió</span> y mejorar el reconocimiento de aeródromos. Es el único uso analítico, y es el motivo por el que los mensajes quedan guardados.</span></li>
  </ul>

  <p class="mt-5 text-muted">
    No se usa para publicidad, no se hace perfilado y no hay decisiones automatizadas
    sobre vos.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Con quién se comparte</h2>

  <p class="mt-4 text-muted">
    Con nadie. Tus mensajes no se venden, no se ceden y no se publican.
  </p>

  <p class="mt-4 text-muted">
    El bot sí consulta servicios externos para <em>armar la respuesta</em>, pero les
    pregunta por aeródromos, nunca por usuarios: la
    <a href="https://ais.anac.gob.ar" target="_blank" rel="noopener" class="text-air hover:underline">ANAC</a>
    para los NOTAM, el
    <a href="https://www.smn.gob.ar/metar" target="_blank" rel="noopener" class="text-air hover:underline">Servicio Meteorológico Nacional</a>
    para METAR, TAF y PRONAREA, y el Servicio de Hidrografía Naval para los
    crepúsculos. Ninguno recibe tu número ni tu mensaje.
  </p>

  <p class="mt-4 text-muted">
    Cuando una consulta está escrita de forma ambigua, el texto de ese mensaje puede
    enviarse a un modelo de lenguaje para interpretarlo. Va el texto de la consulta,
    sin tu número ni tu nombre.
  </p>

  <p class="mt-4 text-muted">
    La conversación viaja además por WhatsApp, que es de Meta y se rige por sus
    propias condiciones.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Cuánto tiempo se guarda</h2>

  <p class="mt-4 text-muted">
    Las <span class="font-medium text-body">suscripciones de aviso vencen solas a las 24 horas</span>
    y se borran: si querés seguir recibiendo avisos, hay que volver a pedirlos.
  </p>

  <p class="mt-4 text-muted">
    El <span class="font-medium text-body">historial de mensajes</span> se conserva
    mientras siga siendo útil para diagnosticar y mejorar el bot. Podés pedir que se
    borre el tuyo en cualquier momento.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Cómo darte de baja o borrar tus datos</h2>

  <ul class="mt-4 grid gap-2.5 text-muted">
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">dejar de recibir avisos</span> de un aeródromo: tocá <span class="font-mono text-[0.9em] text-raw">🔕 Dar de baja</span> en el aviso, o escribile <span class="font-mono text-[0.9em] text-raw">baja SAEZ</span> con el código del aeródromo.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">borrar tu historial</span>, o para saber qué hay guardado sobre vos: escribime a <a href="mailto:dadonefran@gmail.com" class="text-air hover:underline">dadonefran@gmail.com</a> desde donde quieras, indicando el número con el que le escribiste al bot.</span></li>
    <li class="flex gap-3"><span class="text-air">·</span><span>Para <span class="font-medium text-body">cortar del todo</span>: bloqueá el número en WhatsApp y el bot no vuelve a escribirte.</span></li>
  </ul>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Menores</h2>

  <p class="mt-4 text-muted">
    El bot está pensado para pilotos y personas del ambiente aeronáutico. No está
    dirigido a menores de 13 años y no recolecta datos de ellos a sabiendas.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Sobre la información aeronáutica</h2>

  <p class="mt-4 text-muted">
    Aparte de la privacidad, vale decirlo: FlyBot es una herramienta de consulta
    rápida y <span class="font-medium text-body">no reemplaza a las fuentes
    oficiales</span>. Para planificar un vuelo, la información válida es la que
    publican la ANAC y el SMN.
  </p>

  <h2 class="mt-12 text-[1.35rem] font-semibold tracking-[-0.01em]">Cambios</h2>

  <p class="mt-4 text-muted">
    Si esto cambia, cambia la fecha de arriba. Cualquier duda, escribime a
    <a href="mailto:dadonefran@gmail.com" class="text-air hover:underline">dadonefran@gmail.com</a>.
  </p>

</main>

@include('partials.footer', ['home' => '/'])

</body>
</html>
