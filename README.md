# NOTAMs Argentina

API REST y bot de WhatsApp que consultan los NOTAM activos de aeródromos
argentinos desde [ANAC](https://ais.anac.gob.ar), el estado del tiempo (METAR)
desde el [SMN](https://www.smn.gob.ar/metar) y el pronóstico de aeródromo
(TAF) desde el [mismo SMN](https://www.smn.gob.ar/taf), y los devuelven
decodificados a español legible.

Un NOTAM crudo dice `RWY 13/31 CLSD WIP MAINT`. Este servicio devuelve
_"Pista 13/31 cerrada por trabajos de mantenimiento en progreso"_.

Un METAR crudo dice `03009KT 9999 BKN008 15/14 Q1009`. Este servicio devuelve
_"Viento del 030° (NNE) a 9 nudos. Visibilidad 10 km o más. Nubosidad rota
(5 a 7 octavos) a 800 ft. Temperatura 15 °C, punto de rocío 14 °C. Presión QNH
1009 hPa."_

Un TAF crudo dice `TEMPO 2808/2812 0500 FGDZ BKN005`. Este servicio devuelve
_"Fluctuaciones temporarias (TEMPO) el día 28 entre las 08:00 y las 12:00 UTC,
en períodos de menos de una hora cada vez: Visibilidad 500 m. Fenómenos
presentes: niebla y llovizna. Nubes: nubosidad rota (5 a 7 octavos) a 500 ft."_

## Cómo funciona

```
ANAC (scraping HTML) → AnacNotamService                → NotamEnricher → API REST
SMN  (scraping HTML) ┐                                                 → Bot WhatsApp
NOAA (respaldo, API) ┼→ MetarService (caché + failover) → MetarEnricher
                     └→ TafService   (caché + failover) → TafEnricher
SHN  (scraping HTML) → ShnSunService (caché)                            → Bot WhatsApp

MADHEL      (API JSON) ┐
OurAirports (CSV)      ┼→ notams:import-runways → tabla runways → RunwayWind
NOAA WMM    (API JSON) ┘   (declinación magnética)                 (componente de viento)
                       └→ notams:import-airport-details → tabla airports → ficha
                          (ubicación, elevación, FIR, combustible, teléfono)

AIP (PDF, scraping)    → notams:import-aip-details → tabla airports → ficha
                          (combustible, teléfono, horario, frecuencia ATS —
                          sólo para los aeródromos que MADHEL delega a la AIP)
```

- **`AnacNotamService`** habla con ANAC y parsea su HTML. Devuelve NOTAM crudos
  y nada más.
- **`NotamEnricher`** agrega la decodificación al español. Intenta primero con
  IA (OpenRouter) y, si falla o no hay API key, cae al decodificador
  determinístico offline. **Nunca puede tumbar el dato crudo**: un NOTAM es
  información de seguridad operacional y se sirve aunque la IA no esté.
- Cada NOTAM expone `decoded_by` (`"ai"`, `"dictionary"` o `null`) para que el
  consumidor sepa qué calidad de decodificación recibió.
- **`MetarService`** y **`TafService`** son el equivalente para el tiempo. Los
  dos heredan de `AviationReportService`: la caché, el cooldown y el failover
  son los mismos, porque el problema de acceder al SMN es el mismo.
- **`ShnSunService`** es el único que no habla de aeronáutica sino de astronomía:
  trae del SHN la tabla de salida, puesta y crepúsculo civil. Sólo lo usa el bot,
  y sólo por ciudad — ver [Crepúsculo](#crepúsculo-hasta-qué-hora-hay-luz).
- **`MetarConditions`** es lo único que no lee para mostrar sino para comparar:
  saca del METAR los cinco grupos que deciden si un cambio merece despertar a
  alguien. Es lo que hace posible la suscripción "avisame si cambia" sin
  convertirla en un despertador horario.

### METAR y TAF no son lo mismo

Un METAR es una **observación**: qué está pasando ahora en el aeródromo. Un TAF
es un **pronóstico**: qué se espera en las próximas 24 o 30 horas. Contestar una
pregunta sobre mañana con un METAR es estar equivocado con confianza, y por eso
son dos endpoints separados en vez de "el tiempo".

La diferencia de forma es que un TAF no es una foto sino una secuencia de
períodos. `BECMG`, `TEMPO`, `PROB30` y `FM` abren cada uno una ventana de
tiempo, y los grupos que siguen valen **sólo dentro de esa ventana**. Por eso
`TafDecoder` renderiza cada cambio como un encabezado terminado en `:` con las
condiciones debajo: atribuir un fenómeno al período equivocado es tan grave como
traducirlo mal.

`TEMPO` es el que más se malinterpreta y por eso se explicita. No significa que
las condiciones duren toda la ventana, sino que pueden aparecer dentro de ella
por menos de una hora cada vez. Leído al revés, un `TEMPO` de niebla se
convierte en cuatro horas de aeródromo cerrado que nadie pronosticó.

### Por qué hay dos fuentes de METAR y TAF

El SMN es la autoridad para los aeródromos argentinos y se consulta primero.
Pero tiene un Cloudflare adelante que nos bloquea por tramos (ver más abajo), y
un informe meteorológico que nadie puede leer no sirve de nada.

NOAA **no es otro dato**: los METAR y TAF de aeródromo se intercambian
internacionalmente por los circuitos OPMET de la OMM, así que el reporte que
NOAA sirve para SAEZ es el que emitió el SMN, relayado textual. El estándar
existe justamente para que un reporte signifique lo mismo donde sea que se lea.

Cada reporte expone `fuente` (`"smn"` o `"noaa"`) y el bot lo aclara en el pie
cuando hubo relay. La única diferencia práctica: los grupos `RMK` nacionales del
SMN no siempre viajan por el intercambio, así que un reporte relayado puede
venir un poco más corto. NOAA además devuelve su propia decodificación al lado
del texto; se ignora, porque el punto de un relay es pasar el reporte del SMN
sin tocar.

### Por qué el tiempo no usa IA

Un NOTAM es prosa libre: ahí un modelo aporta. Un METAR o un TAF no — son
códigos posicionales totalmente especificados, donde cada grupo tiene
exactamente un significado. Por eso `MetarDecoder` y `TafDecoder` son
**determinísticos y offline**, sin ninguna llamada a un modelo.

No es un ahorro de costos, es una decisión de seguridad: si a un modelo se le
pide parafrasear `03009KT` puede devolver un rumbo o una velocidad equivocada,
y quien lee el español no tiene forma de notarlo. Un parser, en cambio, o
reconoce el grupo o lo deja tal cual: lo que no entiende aparece como
`Grupo sin decodificar: XYZ`, nunca inventado ni silenciosamente omitido.

Las abreviaturas salen del
[decode key del NWS/FAA](https://www.weather.gov/media/wrh/mesowest/metar_decode_key.pdf),
transcriptas y traducidas en `resources/data/metar-abbreviations.php`. Son las
mismas para los dos códigos, que es exactamente por qué `AviationCodeDecoder`
existe: viento, visibilidad, fenómenos y nubes significan lo mismo se esté
informando lo que el tiempo **es** o lo que se pronostica que **será**. Lo único
que cambia entre `MetarDecoder` y `TafDecoder` es la estructura de alrededor.

El registro de aeródromos vive en la tabla `airports` y se importa entero de
MADHEL, el manual de aeródromos y helipuertos de ANAC (712 lugares). Es
necesario porque el selector de NOTAM de ANAC sólo lista lugares con novedades
activas y, por lo tanto, no puede distinguir "código inexistente" de "aeródromo
real sin novedades hoy". Ver [El registro de aeródromos](#el-registro-de-aeródromos).

## Puesta en marcha

```bash
composer setup     # instala, genera APP_KEY, migra, siembra y compila la landing
composer dev       # servidor + worker de cola + logs + vite
```

La `OPENROUTER_API_KEY` es opcional: sin ella todo funciona con el
decodificador offline.

`composer setup` corre además `npm install && npm run build`, que es lo que
compila el CSS de la landing. Sin ese paso la raíz falla al buscar el
manifiesto de Vite, así que la suite también lo necesita.

### Con Docker

```bash
cp .env.example .env
php artisan key:generate    # o pegar un APP_KEY existente en .env
docker compose up -d --build
curl "http://localhost:8090/api/v1/metar?aerodromo=EZE"
```

La imagen es [FrankenPHP](https://frankenphp.dev): servidor y PHP en el mismo
proceso, sin nginx ni php-fpm que mantener en sincronía. Una etapa `assets`
compila el CSS con Node y sólo viaja el resultado, así que la imagen final no
lleva Node adentro. `compose.yaml` la corre con tres comandos distintos, que son
los tres procesos que la aplicación necesita para estar entera:

| Servicio | Qué hace |
| --- | --- |
| `app` | La API REST y el webhook de WhatsApp, en `:8090` (`APP_PORT` lo cambia). |
| `queue` | `queue:work`. **Sin él el bot no contesta**: toda respuesta sale de un job. |
| `scheduler` | `schedule:work`: `notams:refresh-airports`, `notams:import-madhel`, `notams:import-runways`, `notams:import-airport-details`, `notams:import-aip-details` y la ronda de `metar:watch`. |

Un cuarto servicio, `migrate`, corre una vez antes que los demás y termina:
migra, siembra los aeródromos y pone la base en modo WAL. Tenerlo aparte es lo
que evita que los otros tres apliquen las migraciones a la vez sobre el mismo
archivo SQLite.

La configuración se lee del `.env` del repositorio, así que el contenedor toma
las mismas claves de WhatsApp y OpenRouter que el entorno local. Dos cosas que
conviene mirar antes de exponer el puerto: `APP_ENV=local` deja habilitado el
endpoint `/api/whatsapp/test`, y `APP_DEBUG=true` muestra los stack traces.

La base, la cola, la caché y las suscripciones viven en el volumen `database`
—fuera de la imagen, para que sobrevivan a un `--build`—; los archivos
generados, en `storage`. `docker compose down -v` borra los dos.

```bash
docker compose logs -f queue                     # ver responder al bot
docker compose exec app php artisan metar:watch  # forzar una ronda de alertas
docker compose exec app php artisan tinker
```

### La imagen publicada

El CI publica **`franciscodadone/flybot`** en Docker Hub en cada push a `main`
y cada tag `v*`, después de que pasen pint, phpstan y la suite.

Cada build sale etiquetada con `latest` (sólo en la rama por defecto), el
nombre de la rama, el tag de git si lo hay, y el sha corto del commit — el
único inmutable, y por lo tanto con el que conviene desplegar:

```bash
IMAGE_TAG=sha-1af8cfc docker compose pull
IMAGE_TAG=sha-1af8cfc docker compose up -d
```

Requiere dos secretos en el repositorio: `DOCKERHUB_USERNAME` y
`DOCKERHUB_TOKEN` (un access token de Docker Hub, no la contraseña).

Para que Meta llegue al webhook hace falta una URL pública apuntando a
`http://localhost:8090` (`ngrok http 8090`). La firma viaja sobre el cuerpo del
request y no sobre la URL, así que el túnel no la rompe por más saltos que haya
en el medio.

## La landing

`/` sirve `resources/views/landing.blade.php`, la única vista de la aplicación.
Está escrita para quien vuela, no para quien la despliega: qué contestan los
NOTAM, el METAR, el TAF y las alertas, y cómo escribirle al bot. Lo de acá
abajo —la API, Docker, el bloqueo del SMN— queda fuera a propósito.

Los estilos son [Tailwind](https://tailwindcss.com) compilado por Vite
(`resources/css/app.css`), así que hace falta `npm run build` —o `npm run dev`—
para que la página tenga estilos. La imagen de Docker lo hace en su etapa
`assets` y el CI antes de la suite.

El logo vive en `public/images/`, ya derivado del original a los tamaños que la
página usa: `flybot-logo-128.webp` para el nav y el avatar del chat,
`flybot-logo.webp` para el cierre, `flybot-og.jpg` para las previsualizaciones
al compartir el enlace y `flybot-touch.png` para el ícono de pantalla de inicio.
No pasan por Vite —son archivos estáticos servidos tal cual— así que
reemplazarlos no necesita recompilar nada.

El número del botón sale de `WHATSAPP_NUMBER`, así que la página apunta sola a
donde esté configurado el bot. Sin esa variable no se publica ningún enlace, en
vez de uno roto.

`/privacidad` es la otra vista pública: qué guarda el bot de cada conversación,
para qué y cómo pedir que se borre. Comparte el nav y el pie con la portada
(`resources/views/partials/`). Existe además porque Meta no publica una
aplicación sin una política de privacidad accesible.

## API

| Endpoint | Descripción |
| --- | --- |
| `GET /api/v1/notams?aerodromo=EZE` | NOTAM activos. Acepta código ANAC (`EZE`) u OACI (`SAEZ`). |
| `GET /api/v1/notams?aerodromo=EZE&decode=false` | Igual, pero sin decodificación: no gasta llamadas a la IA. |
| `GET /api/v1/notams/aerodromos` | El registro completo de aeródromos de ANAC (~712), con OACI, clasificación y si tiene NOTAM activo. |
| `GET /api/v1/metar?aerodromo=EZE` | METAR vigente, crudo y explicado en español. Mismos códigos que arriba. |
| `GET /api/v1/metar?aerodromo=EZE&decode=false` | Sólo el METAR crudo, sin la explicación. |
| `GET /api/v1/taf?aerodromo=EZE` | TAF vigente, crudo y explicado en español. |
| `GET /api/v1/taf?aerodromo=EZE&decode=false` | Sólo el TAF crudo, sin la explicación. |

Los dos endpoints de tiempo devuelven el reporte textual (`metar` / `taf`) y la
explicación como lista de líneas (`explicacion`), una por grupo decodificado y
en el orden en que aparecen en el reporte. El crudo nunca se omite: es la forma
estándar internacional contra la que un piloto puede contrastar cualquier otra
fuente.

Se devuelve **un solo reporte: el vigente**. Un METAR queda superado en cuanto
se emite el siguiente y un TAF en cuanto sale la próxima emisión o una enmienda,
y las fuentes no coinciden en cuánto historial entregan — el SMN manda sólo el
último, NOAA unas horas. `AviationReportService` normaliza eso; si no, la
respuesta dependería de qué fuente contestó y quedaría un reporte viejo al lado
del vigente sin nada que los distinga.

El TAF agrega `enmendado` y `cancelado`. Una enmienda (`AMD`) retira el
pronóstico anterior antes de que venciera y una cancelación (`CNL`) lo retira
sin reemplazarlo, así que ninguna de las dos se deja librada a que el lector la
note en el texto crudo.

Los aeródromos sin código OACI dan 404 en `/metar` y `/taf` (no 502): el SMN
indexa por ese código, así que reintentar nunca va a servir.

Las rutas sin `/v1` siguen funcionando como alias. Todas están limitadas a 60
peticiones por minuto por IP: cada consulta sin cachear puede disparar una
llamada paga al modelo por cada NOTAM.

## Bot de WhatsApp

Meta postea a `POST /whatsapp/webhook`, firmado con el secreto de la aplicación
sobre el cuerpo crudo del request: se valida antes de aceptar nada. La respuesta
se arma en una cola (`ProcessWhatsappMessage`) para contestar el 200 enseguida —
lo que no se acusa a tiempo, Meta lo reintenta.

Por eso mismo cada mensaje se guarda con su `wamid` y un reintento no vuelve a
responder: el identificador es único por mensaje y la fila ya existe.

`GET /whatsapp/webhook` responde el saludo de suscripción, que es como Meta da
de alta la URL: devuelve el `hub_challenge` si el `hub_verify_token` coincide
con `WHATSAPP_VERIFY_TOKEN`.

El bot entiende `"EZE"`, `"SAEZ"`, `"ezeiza"` o `"hay notams en Ezeiza?"`.
Cuando un nombre es ambiguo — Córdoba tiene tres aeródromos — pregunta en vez
de elegir: mandar los NOTAM del aeródromo equivocado es peor que repreguntar.

Un mensaje que sólo nombra un lugar (`"osa"`, `"sazr"`, `"santa rosa"`)
devuelve **la ficha del aeródromo**, no sus NOTAM — ver
[La ficha](#la-ficha-qué-es-el-aeródromo). Los NOTAM se piden con la palabra:
`"notam sazr"`, `"hay notams en Ezeiza?"`, o con el botón que la ficha lleva
al pie.

Si el mensaje menciona el tiempo (`"metar EZE"`, `"cómo está el clima en
Bariloche?"`, `"viento en SAEZ"`) responde con el METAR en vez de los NOTAM. Si
pregunta por lo que va a pasar (`"taf EZE"`, `"pronóstico de Aeroparque"`,
`"va a llover en SAEZ?"`, `"cómo va a estar el tiempo mañana?"`) responde con el
TAF.

Las palabras de pronóstico ganan sobre las de observación, porque una pregunta
sobre mañana contiene las dos: _"cómo va a estar el tiempo mañana"_ menciona
"tiempo" tanto como menciona "mañana", y el pronóstico es la lectura honesta.
Antes esas palabras caían a NOTAM justamente para no contestarlas con un METAR;
ahora hay con qué contestarlas.

La palabra `"notam"` gana sobre todo lo demás: quien la escribió sabe lo que
pidió, y _"hay notams para mañana en EZE?"_ no se contesta con un pronóstico.

### La ficha: qué *es* el aeródromo

Todo lo demás que contesta el bot es sobre esta tarde. La ficha es lo otro: a
qué distancia y rumbo de la ciudad está el aeródromo, qué pistas tiene y de qué
largo, ancho y superficie, a qué elevación, si hay combustible y a quién llamar.

```
🛬 SANTA ROSA
Aeródromo público controlado · OSA / SAZR / RSA

📍 4,5 km al nor-noreste de Santa Rosa (La Pampa)
   36°35'18"S 064°16'33"O
⛰️ Elevación 192 m (630 ft)
🗺️ FIR Ezeiza (SAEF) · Tránsito nacional

Pistas
• 01/19 — 2300 × 30 m — asfalto — balizada

⛽ Combustible: AVGAS 100LL y/and JET A-1
☎️ Teléfono: (+54 2954) 434690 · (+54 2954) 434490 · (+54 9 2954) 506705 · (+54 9 2954) 506740
🕐 Horario: LUN a VIE 12:00 a 23:00 UTC. SÁB 17:00 a 23:00 UTC. DOM 13:00 a 21:00 UTC
📻 Frecuencia: TWR/APP SANTA ROSA TORRE — 118.30 MHz (CPPL) · 119.70 MHz (CAUX)

Combustible, teléfono, horario y frecuencia según la AIP.
```

Se pide sola (`"osa"`, `"santa rosa"`, `"sazr"`) o por su nombre (`"info osa"`,
`"combustible en CIF"`, `"dónde queda Arrecifes"`), y es también lo que
contesta cualquier mensaje que nombre un aeródromo sin pedir otra cosa.

Al pie lleva el botón **🛬 Viento en pista**, porque acaba de listar las
cabeceras y "cuál de éstas favorece el viento ahora" es la pregunta que sigue —
y sólo se manda cuando hay rumbos cargados, que es justamente cuando la ficha
tuvo una lista con la que provocarla.

**"Sin dato publicado" nunca quiere decir "no tiene".** MADHEL deja el bloque
`data` vacío para los aeródromos que delega a la AIP — que son justamente los
más consultados, Santa Rosa entre ellos — y publica combustible para apenas
uno de cada siete de los que no delega. Decir "no hay combustible" por eso
sería inventar un dato sobre un lugar al que alguien está por volar. Cuando
la ficha ni siquiera se importó todavía, la respuesta lo dice con esas
palabras, en vez de atribuirle a MADHEL (o a la AIP) un silencio que es
nuestro.

Para los aeródromos delegados, combustible, teléfono y horario —y la
frecuencia de torre/aproximación, que MADHEL nunca publicó para nadie— salen
de ahí en cambio: la AIP publica una ficha AD-2 en PDF por aeródromo, y
`notams:import-aip-details` es lo que la lee. Hasta que ese import corre por
primera vez para un aeródromo delegado, la ficha lo dice así en vez de
mostrar "sin dato publicado":

```
⛽ Combustible: sin dato publicado en la AIP
☎️ Teléfono: sin dato publicado en la AIP

Todavía no importé la ficha de la AIP de este aeródromo (notams:import-aip-details).
```

Las dimensiones y la superficie vienen de las dos mismas fuentes que los rumbos
y con el mismo reparto — ver
[Componente de viento en pista](#componente-de-viento-en-pista). Los datos se
importan con dos comandos semanales, el segundo detrás del primero porque
depende de qué aeródromos quedaron marcados como delegados a la AIP:

```bash
php artisan notams:import-airport-details            # todo el registro MADHEL, ~5 min
php artisan notams:import-airport-details --only=OSA # un aeródromo

php artisan notams:import-aip-details                 # sólo los delegados a la AIP, ~40
php artisan notams:import-aip-details --only=OSA      # un aeródromo
```

### Componente de viento en pista

`"viento cruzado en Ezeiza"`, `"componente de viento en EZE"` o
`"qué pista conviene en SAEZ"` toman el METAR vigente y lo descomponen contra
cada cabecera: cuánto viento queda de frente (o de cola) y cuánto atraviesa la
pista. La respuesta lista todas las cabeceras, la más favorable primero, y bajo
esa agrega el cálculo con la ráfaga — que es el número contra el que se chequea
un límite de viento cruzado.

Es el paso que el METAR deja a medio camino: informa `35015G25KT` y deja al
lector hacer la cuenta que en realidad decide dónde aterrizar.

También se llega con un toque: las respuestas de METAR, las de NOTAM y la ficha
llevan un botón **🛬 Viento en pista** al pie.

```
🛬 EZEIZA / MINISTRO PISTARINI (SAEZ)

35015G25KT
Viento del 350° (N) a 15 kt, ráfagas 25 kt

✅ RWY 35 — de frente 15 kt · cruzado 1 kt (izq)
     con ráfaga: de frente 25 kt · cruzado 2 kt
   RWY 29 — de frente 4 kt · cruzado 14 kt (izq)
   RWY 11 — de cola 4 kt · cruzado 14 kt (der)
   RWY 17 — de cola 15 kt · cruzado 1 kt (der)
```

Estas frases se chequean **antes** que las de METAR, porque todas contienen
"viento" y si no la observación se comería la pregunta. `"pista"` a secas no es
palabra clave: el matching es por substring y hay aeródromos que la llevan en el
nombre (`CORONEL SUÁREZ / LA PISTA`), así que _"notams de la pista"_ se
desviaría solo.

Con viento en calma o variable no hay componente que calcular ni cabecera
favorecida, y lo dice en vez de inventar un número. Una pista cerrada se lista
marcada — que exista y no se pueda usar es información operativa — pero nunca
se recomienda.

#### De dónde salen los rumbos de pista

El designador de una pista es **magnético** y el viento del METAR es
**verdadero**. En Argentina la declinación va de −10° en Buenos Aires a +11,7°
en Ushuaia, cruzando el cero en la Patagonia: no tiene un signo único y
saltearla mete hasta 20° de error en el ángulo del que depende toda la cuenta.
Por eso `runways.heading_true` guarda el rumbo ya corregido, y la corrección se
aplica una sola vez, al importar.

La declinación sale del [WMM de la NOAA](https://www.ngdc.noaa.gov/geomag/WMM/)
y queda cacheada en la fila del aeródromo: deriva una décima de grado por año,
así que se consulta como mucho una vez al año por aeródromo.

Los rumbos vienen de dos fuentes porque ninguna alcanza sola:

| Fuente | Cubre | Problema |
|---|---|---|
| **MADHEL** (ANAC, oficial) | 88 de 139 aeródromos públicos con OACI | Publica `rwy: []` justo en los más consultados (EZE, AEP, COR, MDZ, ROS, TUC): delegan al AIP |
| **OurAirports** (abierto) | 114 de 139, y con rumbo verdadero ya calculado | Le faltan 25 aeródromos chicos, y tiene algún registro erróneo |

Son casi exactamente complementarias: MADHEL tiene los chicos que OurAirports no
conoce y OurAirports tiene los grandes que MADHEL deja en blanco. Juntas cubren
prácticamente todo el registro público.

El mismo reparto vale para el largo, el ancho, la superficie y el balizamiento
que muestra la ficha: MADHEL los escribe en la prosa que sigue al designador
(`05/23 1871x30 M - ASPH`) y OurAirports los publica en cuatro columnas, para
225 de las 233 pistas argentinas. MADHEL decide qué pistas existen; donde deja
un dato en blanco, lo completa OurAirports, y nunca al revés.

Un rumbo publicado por OurAirports se usa sólo si concuerda con su propio
designador dentro de 20°. Eso no es por precisión — un designador está
redondeado a diez grados, así que discrepar unos pocos es normal — sino contra
el disparate: OurAirports publica la pista 05 de SAOC con rumbo 178°, que está a
128° de donde una pista numerada 05 puede apuntar. En ese caso gana el
designador.

```bash
php artisan notams:import-runways            # todo el registro, ~5 min
php artisan notams:import-runways --only=EZE # un aeródromo
```

**No hay snapshot commiteado**: la tabla se llena y se mantiene sólo con este
comando, que corre semanalmente después de `notams:import-madhel`. Una
instalación nueva tiene que correrlo una vez a mano, o el bot contesta
honestamente que no tiene los rumbos.

### Crepúsculo: hasta qué hora hay luz

`"crepusculo santa rosa"`, `"a qué hora atardece en Bariloche?"` o
`"crepusculo EZE"` devuelven el crepúsculo matutino, la salida del sol, la puesta
y el crepúsculo vespertino, en UTC y en hora oficial argentina.

El dato importante es el crepúsculo y no el ocaso: el sol se pone media hora
antes de que oscurezca, y esa media hora es la diferencia entre llegar de día y
llegar de noche. El que se publica es el **crepúsculo civil**, que es el que usa
la reglamentación.

La fuente es el [Servicio de Hidrografía Naval](https://www.hidro.gov.ar/observatorio/Astronomia.asp),
la autoridad argentina en la materia, y de ahí sale la única limitación de esta
respuesta: **el SHN publica 34 ciudades, no los 712 aeródromos**. Un aeródromo
que sirve a una de esas ciudades contesta por ella — Ezeiza, San Fernando, Morón
y El Palomar son todos Buenos Aires, están a menos de un minuto de crepúsculo de
distancia — y para el resto el bot dice que no lo tiene y lista las que sí.

Una consulta al SHN trae el mes entero y esa tabla está publicada de antemano,
así que se cachea treinta días: no hay nada que revalidar.

**Requiere un worker corriendo.** Sin él, el webhook acepta el mensaje y nadie
responde nunca:

```bash
php artisan queue:work --tries=3
```

En producción esto va bajo systemd o supervisor, junto con
`php artisan schedule:work` para el refresco horario de aeródromos
(`notams:refresh-airports`), los imports semanales del registro
(`notams:import-madhel`), de las pistas (`notams:import-runways`), de las
fichas de MADHEL (`notams:import-airport-details`) y de las fichas de la AIP
para los aeródromos delegados (`notams:import-aip-details`), y la ronda de
alertas (`metar:watch`).

### El registro de aeródromos

El selector de NOTAM de ANAC sólo lista los aeródromos que tienen un NOTAM
activo *en este momento*, y su PIB responde 500 tanto para un aeródromo
tranquilo como para un código inventado. Por eso no puede decir qué aeródromos
existen: `ELP` (Club de Planeadores Santa Rosa / El Pampero) es real y durante
años iba a dar 404.

La fuente de verdad es MADHEL, el registro oficial de ANAC, que se importa
entero — 712 aeródromos y helipuertos, públicos y privados:

```bash
php artisan notams:import-madhel              # actualiza la tabla airports
php artisan notams:import-madhel --seed-file  # además regenera el snapshot commiteado
```

`database/seeders/data/airports.php` es ese snapshot: está generado, no se
edita a mano, y existe para que una instalación nueva y los tests tengan el
registro completo sin depender de la red. `notams:refresh-airports` sigue
corriendo cada hora, pero ahora sólo anota qué aeródromos tienen NOTAM activo.

El snapshot lleva sólo lo que trae el endpoint de listado: nombre, códigos,
clasificación y coordenadas. La ubicación relativa, la elevación, el FIR, el
combustible y el teléfono viven en el detalle por aeródromo y los trae
`notams:import-airport-details`, que —como `notams:import-runways`— no tiene
snapshot y hay que correr una vez a mano en una instalación nueva. Detrás de
ese, y por la misma razón, `notams:import-aip-details` trae de la AIP lo que
MADHEL deja en blanco para los aeródromos que le delega.

### Alertas: "avisame si cambia"

Cada respuesta de METAR trae un botón **🔔 Avisarme 12 h**. Tocarlo deja una
suscripción, y a partir de ahí el bot escribe solo cuando el METAR de ese
aeródromo cambia de verdad. También funciona escrito: `"avisame EZE"`,
`"avisame EZE por 6 horas"`, `"mis alertas"`, `"baja EZE"`, `"baja todas"`.

Cada aviso lleva a su vez un botón **🔕 Dar de baja**.

**Qué cuenta como un cambio.** El METAR se reemite cada hora y casi siempre
difiere del anterior: la temperatura se mueve un grado, el viento rola dos
nudos. Avisar por eso sería un despertador horario, así que la comparación
(`App\Support\MetarConditions`) se hace sobre los grupos que un piloto
efectivamente acciona, y por banda antes que por delta:

- la llegada de un **SPECI** — el propio SMN lo emitió porque algo cruzó un
  umbral, y ese criterio manda sobre el nuestro;
- **categoría de vuelo** (VFR / MVFR / IFR / LIFR), siempre por el peor entre
  techo y visibilidad;
- **viento**: ±10 kt, aparición o desaparición de ráfaga, o ±30° de dirección
  pero sólo con 10 kt o más — abajo de eso la dirección deambula sola;
- **visibilidad** y **techo** que cruzan una banda (800 / 1500 / 3000 / 5000 /
  8000 m; 200 / 500 / 1000 / 1500 / 3000 ft);
- **fenómenos** que empiezan, terminan o cambian de intensidad (`-RA` → `+TSRA`);
- **QNH** de ±2 hPa.

La línea de base avanza en cada ronda aunque no se avise. Dejarla en el último
reporte *notificado* convertiría una deriva lenta — un nudo por hora — en un
aviso seis horas después por un cambio que nadie habría visto ocurrir.

**Por qué vencen.** WhatsApp sólo deja escribirle libremente a alguien dentro de
las 24 horas de su último mensaje; fuera de esa ventana hace falta una plantilla
aprobada. Una suscripción dura 12 horas por defecto y 24 como máximo
(`METAR_WATCH_TTL` / `METAR_WATCH_MAX_TTL`), así que todo aviso cae dentro de la
ventana. Volver a suscribirse la renueva; hay un tope de 5 aeródromos por número
(`METAR_WATCH_MAX`).

La ronda corre cada diez minutos, alineada con el TTL de caché del METAR, así
que cuesta como mucho un pedido real por estación vigilada por más gente que la
esté mirando:

```bash
php artisan metar:watch
```

### Los botones

Van dentro del mismo pedido que el texto (`ReplyButton` → mensaje `interactive`
de la Cloud API): no hay nada que registrar de antemano ni aprobación que
esperar. Cada botón es un identificador que elegimos nosotros y un título que el
lector ve; al tocarlo, el identificador vuelve tal cual y `WhatsappBotService` lo
parsea con sus patrones `BUTTON_*`. Ahí viaja el aeródromo, que es lo que hace
que un toque no necesite adivinar nada.

Nada en tiempo de ejecución ata los identificadores que se emiten con la
gramática que los lee, así que eso lo cuida `tests/Unit/ReplyButtonTest.php`.

**WhatsApp dibuja como mucho tres botones por mensaje**, y de ahí sale la forma
que tiene todo esto. Los menús de seguimiento ya gastan los tres, así que la
oferta **🛬 Viento en pista** viaja en el mensaje mismo, donde había lugar:

- Bajo cada METAR van **dos**: 🔔 Avisarme 12 h y 🛬 Viento en pista.
- Sólo el 🛬 va bajo el último mensaje de una respuesta de NOTAM, al pie de la
  ficha, y bajo el METAR de un aeródromo al que el lector ya está suscripto —
  ahí el de alta prometería algo que ya pasa.

Bajo los NOTAM y la ficha el botón se ofrece **sólo si hay rumbos de pista
cargados**, al revés que bajo el METAR: ahí está solo, así que se manda
únicamente cuando va a contestar algo. Un botón que lleva a _"no tengo los
rumbos de pista"_ es peor que ningún botón, y además un mensaje con botones se
parte al presupuesto más corto (1024 caracteres en vez de los 1500 habituales),
que costaría mensajes de más para nada.

Los menús de seguimiento ofrecen otros tres temas para el mismo aeródromo, sin
repetir el que se acaba de contestar. El de la ficha es el que más se manda,
porque la ficha es la respuesta por defecto: ofrece NOTAM, METAR y TAF, que es
lo que alguien quiere saber justo después de enterarse de que el lugar existe.
El menú es un mensaje aparte y no más botones sobre la respuesta, porque un
mensaje dibuja un solo juego.

Con SQLite, activá WAL para que el worker y el servidor web no se bloqueen
mutuamente:

```sql
PRAGMA journal_mode=WAL;
```

## Panel de administración

En `/admin`, detrás de un login. Dos pantallas: el **log de mensajes entrantes**
con la respuesta que dio el bot, y las **métricas** de uso.

No hay registro público. Las cuentas se crean por consola, y `is_admin` no es
asignable en masa justamente para que no haya otro camino:

```bash
php artisan admin:create vos@ejemplo.com          # pide la contraseña por teclado
php artisan admin:create vos@ejemplo.com --password=...   # o sin interacción
```

Volver a correrlo sobre un correo existente lo promueve y le cambia la
contraseña, así que sirve igual para recuperar el acceso.

Cada mensaje que entra se guarda en `whatsapp_messages` **antes** de encolarse:
un mensaje que nunca se contestó — cola caída, ANAC inalcanzable, reintentos
agotados — deja rastro igual, y ésas son las filas que interesan. De cada uno
queda el teléfono, el nombre de perfil de WhatsApp si lo informó, el texto, el
tema al que enrutó, el aeródromo que resolvió, las respuestas enviadas y cuánto
tardó.

Las métricas salen de ahí: consultas por aeródromo (histórico y de la semana),
mensajes por día, actividad por hora local, temas pedidos, personas distintas —
nuevas y recurrentes —, latencia (media, mediana, p95), fallos, alertas METAR
activas por aeródromo, y quiénes escriben más.

La que más sirve para mejorar el bot es **"sin aeródromo identificado"**: los
mensajes que el matcher no supo resolver, con ejemplos y un filtro propio en el
log. Ahí aparecen los nombres que la gente usa y el registro no tiene.

El panel es lo único que lee en hora local (`APP_DISPLAY_TIMEZONE`, por defecto
`America/Argentina/Buenos_Aires`). Todo lo que el bot contesta sigue siendo UTC.

## Nota operativa: el SMN detrás de Cloudflare

`ssl.smn.gob.ar` tiene un Cloudflare adelante que responde con un desafío en vez
de la página. A veces es un caso aislado — el mismo pedido funciona segundos
después — y a veces es un bloqueo sostenido de horas contra la IP.
`SmnReportSource` lo detecta por el cuerpo de la respuesta (llega tanto con 403
como con 200).

Lo importante, medido: **el bloqueo se endurece cuanto más se insiste**. Con
reintentos agresivos la tasa de éxito pasó de ~1 de cada 6 pedidos a 0 de 12.
Insistir mantiene vivo el bloqueo propio.

De ahí las tres capas, en orden de importancia:

1. **Caché** (`METAR_TTL` 10 min, `TAF_TTL` 30 min) — baja el volumen a unos
   pocos pedidos por estación por hora. Los METAR se emiten cada hora y los TAF
   cada seis, así que no se pierde actualidad.
2. **Cooldown** (`WEATHER_SOURCE_COOLDOWN`, 15 min) — cuando una fuente falla se
   la deja en paz un rato. Sin esto, cada mensaje entrante dispara pedidos
   nuevos y el bloqueo nunca expira. Es el arreglo del bloqueo que se
   realimenta.
3. **Failover a NOAA** — mientras el SMN descansa, contesta el respaldo.

El cooldown es **uno solo para METAR y TAF**, y eso es deliberado: el bloqueo es
contra nosotros en la puerta del SMN, no contra una de sus páginas. Pedirle un
TAF segundos después de que nos negó un METAR mantendría vivo el bloqueo igual
que reintentar.

Los reintentos dentro de `SmnReportSource` (`SMN_METAR_ATTEMPTS`, 2) cubren
**sólo** el desafío aislado. Subirlos empeora las cosas.

## Desarrollo

```bash
php artisan test                              # suite completa (Pest)
./vendor/bin/pest --filter=decodes            # un subconjunto
./vendor/bin/phpstan analyse --memory-limit=1G  # nivel 6
./vendor/bin/pint                             # formato
```

La suite está escrita en **Pest**. Los helpers compartidos (`anacFixture()`,
`fakeAnac()`, `pibWith()`, `withoutAi()`, `smnFixture()`, `fakeMetar()`,
`fakeTaf()`, `smnMetarWith()`, `smnTafWith()`) viven en `tests/Pest.php`, que
también enchufa `Tests\TestCase` — y con él la base migrada y sembrada de
aeródromos — a todo lo que hay bajo `Feature/` y `Unit/`.

`fakeAnac()` se llama dentro de cada test y no en un `beforeEach`: `Http::fake()`
fusiona los stubs y gana el primero, así que un fake posterior no puede pisar a
uno anterior. Lo mismo vale para `fakeMetar()` y `fakeTaf()`; como no se
solapan, un test que ejercite varios canales puede llamar a los tres.

`fakeMetar()` y `fakeTaf()` matchean por endpoint y no por host, justamente
porque el SMN sirve observación y pronóstico desde la misma página: un stub a
nivel de host contestaría una consulta de TAF con un METAR.

Los tests del scraper corren contra HTML capturado de los sitios reales
(`tests/Fixtures/anac/` y `tests/Fixtures/smn/`). Si ANAC o el SMN cambian su
markup, esos tests son lo que avisa — sin ellos la app devolvería listas vacías
en silencio, como si el aeródromo estuviera despejado.

PHPStan analiza `app/`, `database/`, `routes/` y las clases reales de `tests/`,
pero no los archivos de test de Pest: no sabe qué `TestCase` liga Pest como
`$this` dentro de un closure. El plugin oficial que lo resuelve todavía exige
Pest 5.
