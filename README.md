# NOTAMs Argentina

API REST y bot de WhatsApp que consultan los NOTAM activos de aeródromos
argentinos desde [ANAC](https://ais.anac.gob.ar) y el estado del tiempo (METAR)
desde el [SMN](https://www.smn.gob.ar/metar), y los devuelven decodificados a
español legible.

Un NOTAM crudo dice `RWY 13/31 CLSD WIP MAINT`. Este servicio devuelve
_"Pista 13/31 cerrada por trabajos de mantenimiento en progreso"_.

Un METAR crudo dice `03009KT 9999 BKN008 15/14 Q1009`. Este servicio devuelve
_"Viento del 030° (NNE) a 9 nudos. Visibilidad 10 km o más. Nubosidad rota
(5 a 7 octavos) a 800 ft. Temperatura 15 °C, punto de rocío 14 °C. Presión QNH
1009 hPa."_

## Cómo funciona

```
ANAC (scraping HTML) → AnacNotamService → NotamEnricher → API REST
SMN  (scraping HTML) → SmnMetarService  → MetarEnricher → Bot WhatsApp
```

- **`AnacNotamService`** habla con ANAC y parsea su HTML. Devuelve NOTAM crudos
  y nada más.
- **`NotamEnricher`** agrega la decodificación al español. Intenta primero con
  IA (OpenRouter) y, si falla o no hay API key, cae al decodificador
  determinístico offline. **Nunca puede tumbar el dato crudo**: un NOTAM es
  información de seguridad operacional y se sirve aunque la IA no esté.
- Cada NOTAM expone `decoded_by` (`"ai"`, `"dictionary"` o `null`) para que el
  consumidor sepa qué calidad de decodificación recibió.
- **`SmnMetarService`** y **`MetarEnricher`** son el equivalente para METAR.

### Por qué el METAR no usa IA

Un NOTAM es prosa libre: ahí un modelo aporta. Un METAR no — es un código
posicional totalmente especificado, donde cada grupo tiene exactamente un
significado. Por eso `MetarDecoder` es **determinístico y offline**, sin
ninguna llamada a un modelo.

No es un ahorro de costos, es una decisión de seguridad: si a un modelo se le
pide parafrasear `03009KT` puede devolver un rumbo o una velocidad equivocada,
y quien lee el español no tiene forma de notarlo. Un parser, en cambio, o
reconoce el grupo o lo deja tal cual: lo que no entiende aparece como
`Grupo sin decodificar: XYZ`, nunca inventado ni silenciosamente omitido.

Las abreviaturas salen del
[decode key del NWS/FAA](https://www.weather.gov/media/wrh/mesowest/metar_decode_key.pdf),
transcriptas y traducidas en `resources/data/metar-abbreviations.php`.

El registro de aeródromos vive en la tabla `airports`. Es necesario porque el
selector de ANAC sólo lista lugares con NOTAM activos y, por lo tanto, no puede
distinguir "código inexistente" de "aeródromo real sin novedades hoy".

## Puesta en marcha

```bash
composer setup     # instala, genera APP_KEY, migra y siembra los aeródromos
composer dev       # servidor + worker de cola + logs
```

La `OPENROUTER_API_KEY` es opcional: sin ella todo funciona con el
decodificador offline.

## API

| Endpoint | Descripción |
| --- | --- |
| `GET /api/v1/notams?aerodromo=EZE` | NOTAM activos. Acepta código ANAC (`EZE`) u OACI (`SAEZ`). |
| `GET /api/v1/notams?aerodromo=EZE&decode=false` | Igual, pero sin decodificación: no gasta llamadas a la IA. |
| `GET /api/v1/notams/aerodromos` | Aeródromos que ANAC reporta con NOTAM activos ahora. |
| `GET /api/v1/metar?aerodromo=EZE` | METAR vigente, crudo y explicado en español. Mismos códigos que arriba. |
| `GET /api/v1/metar?aerodromo=EZE&decode=false` | Sólo el METAR crudo, sin la explicación. |

El METAR se devuelve con el reporte textual (`metar`) y la explicación como
lista de líneas (`explicacion`), una por grupo decodificado y en el orden en
que aparecen en el reporte. El crudo nunca se omite: es la forma estándar
internacional contra la que un piloto puede contrastar cualquier otra fuente.

Los aeródromos sin código OACI dan 404 en `/metar` (no 502): el SMN indexa las
observaciones sólo por ese código, así que reintentar nunca va a servir.

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
Bariloche?"`, `"viento en SAEZ"`) responde con el METAR en vez de los NOTAM.
Las palabras de pronóstico (`"pronóstico"`, `"mañana"`) **no** disparan METAR a
propósito: un METAR es una observación de lo que está pasando ahora, y
contestar con él una pregunta sobre mañana sería estar equivocado con
confianza.

**Requiere un worker corriendo.** Sin él, el webhook acepta el mensaje y nadie
responde nunca:

```bash
php artisan queue:work --tries=3
```

En producción esto va bajo systemd o supervisor, junto con
`php artisan schedule:work` para el refresco horario de aeródromos
(`notams:refresh-airports`).

Con SQLite, activá WAL para que el worker y el servidor web no se bloqueen
mutuamente:

```sql
PRAGMA journal_mode=WAL;
```

## Nota operativa: el SMN detrás de Cloudflare

`ssl.smn.gob.ar` tiene un Cloudflare adelante que, de forma intermitente,
responde con un desafío en vez de la página. No es un rechazo del pedido: el
mismo pedido funciona segundos después. `SmnMetarService` lo detecta por el
cuerpo de la respuesta (llega tanto con 403 como con 200) y reintenta.

Lo importante: **el bloqueo se endurece cuanto más se insiste**. Reintentar
fuerte lo empeora. La defensa real es la caché (`SMN_METAR_TTL`, 10 minutos por
defecto), que deja el tráfico en régimen en unos pocos pedidos por estación por
hora — los METAR se emiten cada hora, así que no se pierde actualidad.

## Desarrollo

```bash
php artisan test                              # suite completa (Pest)
./vendor/bin/pest --filter=decodes            # un subconjunto
./vendor/bin/phpstan analyse --memory-limit=1G  # nivel 6
./vendor/bin/pint                             # formato
```

La suite está escrita en **Pest**. Los helpers compartidos (`anacFixture()`,
`fakeAnac()`, `pibWith()`, `withoutAi()`, `smnFixture()`, `fakeSmn()`,
`smnWith()`) viven en `tests/Pest.php`, que también enchufa `Tests\TestCase` —
y con él la base migrada y sembrada de aeródromos — a todo lo que hay bajo
`Feature/` y `Unit/`.

`fakeAnac()` se llama dentro de cada test y no en un `beforeEach`: `Http::fake()`
fusiona los stubs y gana el primero, así que un fake posterior no puede pisar a
uno anterior. Lo mismo vale para `fakeSmn()`; como apuntan a hosts distintos,
un test que ejercite ambos canales puede llamar a los dos.

Los tests del scraper corren contra HTML capturado de los sitios reales
(`tests/Fixtures/anac/` y `tests/Fixtures/smn/`). Si ANAC o el SMN cambian su
markup, esos tests son lo que avisa — sin ellos la app devolvería listas vacías
en silencio, como si el aeródromo estuviera despejado.

PHPStan analiza `app/`, `database/`, `routes/` y las clases reales de `tests/`,
pero no los archivos de test de Pest: no sabe qué `TestCase` liga Pest como
`$this` dentro de un closure. El plugin oficial que lo resuelve todavía exige
Pest 5.
