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
| `scheduler` | `schedule:work`: `notams:refresh-airports`, `notams:import-madhel` y la ronda de `metar:watch`. |

Un cuarto servicio, `migrate`, corre una vez antes que los demás y termina:
migra, siembra los aeródromos y pone la base en modo WAL. Tenerlo aparte es lo
que evita que los otros tres apliquen las migraciones a la vez sobre el mismo
archivo SQLite.

La configuración se lee del `.env` del repositorio, así que el contenedor toma
las mismas claves de Twilio y OpenRouter que el entorno local. Dos cosas que
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

Para que Twilio llegue al webhook hace falta una URL pública apuntando a
`http://localhost:8090` (`ngrok http 8090`); la app ya confía en los headers del
proxy, que es lo que hace que la firma del webhook valide sin importar cuántos
saltos haya en el medio.

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

El número del botón sale de `TWILIO_WHATSAPP_FROM`, así que la página apunta
sola a donde esté configurado el bot. Sin esa variable no se publica ningún
enlace, en vez de uno roto.

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

Twilio postea a `POST /whatsapp/webhook`, cuya firma se valida antes de aceptar
nada. La respuesta se arma en una cola (`ProcessWhatsappMessage`) para no
hacer esperar a Twilio.

El bot entiende `"EZE"`, `"SAEZ"`, `"ezeiza"` o `"hay notams en Ezeiza?"`.
Cuando un nombre es ambiguo — Córdoba tiene tres aeródromos — pregunta en vez
de elegir: mandar los NOTAM del aeródromo equivocado es peor que repreguntar.

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

**Requiere un worker corriendo.** Sin él, el webhook acepta el mensaje y nadie
responde nunca:

```bash
php artisan queue:work --tries=3
```

En producción esto va bajo systemd o supervisor, junto con
`php artisan schedule:work` para el refresco horario de aeródromos
(`notams:refresh-airports`), el import semanal del registro
(`notams:import-madhel`) y la ronda de alertas (`metar:watch`).

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

WhatsApp no dibuja un botón desde texto libre: hace falta una *content template*
de Twilio. Se crean una sola vez por cuenta y sus SID van al `.env`:

```bash
php artisan whatsapp:content-templates
# TWILIO_CONTENT_SID_METAR=HX...
# TWILIO_CONTENT_SID_ALERT=HX...
```

No se someten a aprobación de WhatsApp y no hace falta: la aprobación compra el
derecho a escribirle a alguien de la nada, y estas dos sólo salen dentro de la
ventana que abrió el mensaje del propio usuario.

**Sin esos SID el bot funciona igual.** Los dos mensajes salen en texto plano
con el comando escrito equivalente al pie (_"Respondeme «avisame SAEZ»"_). El
botón ahorra tipear; nunca es el único camino.

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
