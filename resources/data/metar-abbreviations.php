<?php

/**
 * METAR/SPECI decoding tables.
 *
 * Transcribed from the NWS/FAA "METAR/TAF List of Abbreviations and Acronyms"
 * and "Key to Decode an ASOS (METAR) Observation":
 * https://www.weather.gov/media/wrh/mesowest/metar_decode_key.pdf
 *
 * `abbreviations` is that document's glossary verbatim, translated to Spanish —
 * it is the fallback used for tokens the structured parser doesn't recognise
 * (remarks, mostly). The tables below it are the ones the parser actually walks
 * to build a sentence, split by the role each group plays in the report, since
 * the same letters mean different things in different positions (e.g. "SH" is a
 * descriptor, "SN" is precipitation, and "IC" is either ice crystals or
 * in-cloud lightning depending on where it appears).
 *
 * Note the source is a US document: it documents statute miles, inches of
 * mercury (A2990) and SAO-era remarks. Argentine reports are ICAO-style —
 * metres, hPa (Q1009), CAVOK — so the parser handles both and the glossary
 * keeps the US entries for the cases where they do turn up.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Glossary
    |--------------------------------------------------------------------------
    |
    | The full abbreviation list from the source document, code => Spanish.
    | Used to explain leftover tokens (mainly remarks) that the structured
    | parser has no dedicated rule for.
    |
    */

    'abbreviations' => [
        '$' => 'indicador de mantenimiento requerido',
        'ACC' => 'altocúmulus castellanus',
        'ACFT MSHP' => 'accidente de aeronave',
        'ACSL' => 'altocúmulus lenticular estacionario',
        'ALP' => 'punto de referencia del aeropuerto',
        'AO1' => 'estación automática sin discriminador de precipitación',
        'AO2' => 'estación automática con discriminador de precipitación',
        'APCH' => 'aproximación',
        'APRNT' => 'aparente',
        'APRX' => 'aproximadamente',
        'ATCT' => 'torre de control de tránsito aéreo',
        'AUTO' => 'informe totalmente automático',
        'B' => 'comenzó',
        'BC' => 'bancos',
        'BKN' => 'nubosidad rota',
        'BL' => 'soplando en altura',
        'BR' => 'neblina',
        'C' => 'central (referido a la designación de pista)',
        'CA' => 'rayo nube-aire',
        'CB' => 'cumulonimbus',
        'CBMAM' => 'cumulonimbus mammatus',
        'CC' => 'rayo nube-nube',
        'CCSL' => 'cirrocúmulus lenticular estacionario',
        'CD' => 'candela',
        'CG' => 'rayo nube-tierra',
        'CHI' => 'indicador de altura de nubes',
        'CHINO' => 'condición del cielo no disponible en la ubicación secundaria',
        'CIG' => 'techo de nubes',
        'CLR' => 'despejado',
        'CONS' => 'continuo',
        'COR' => 'corrección de un informe ya difundido',
        'DOC' => 'Departamento de Comercio (EE.UU.)',
        'DOD' => 'Departamento de Defensa (EE.UU.)',
        'DOT' => 'Departamento de Transporte (EE.UU.)',
        'DR' => 'arrastrado a baja altura',
        'DS' => 'tormenta de polvo',
        'DSIPTG' => 'disipándose',
        'DSNT' => 'distante',
        'DU' => 'polvo extendido',
        'DVR' => 'alcance visual de despacho',
        'DZ' => 'llovizna',
        'E' => 'este / finalizó / techo estimado',
        'FAA' => 'Administración Federal de Aviación (EE.UU.)',
        'FC' => 'nube embudo',
        'FEW' => 'algunas nubes',
        'FG' => 'niebla',
        'FIBI' => 'registrado pero imposible de transmitir',
        'FIRST' => 'primera observación tras una interrupción en estación manual',
        'FMH-1' => 'Manual Meteorológico Federal N.º 1 (observaciones de superficie, METAR)',
        'FMH2' => 'Manual Meteorológico Federal N.º 2 (códigos sinópticos de superficie)',
        'FROPA' => 'pasaje frontal',
        'FRQ' => 'frecuente',
        'FT' => 'pies',
        'FU' => 'humo',
        'FZ' => 'engelante',
        'FZRANO' => 'sensor de lluvia engelante no disponible',
        'G' => 'ráfaga',
        'GR' => 'granizo',
        'GS' => 'granizo pequeño o nieve granulada',
        'HLSTO' => 'piedra de granizo',
        'HZ' => 'calima',
        'IC' => 'cristales de hielo / rayo dentro de la nube',
        'ICAO' => 'Organización de Aviación Civil Internacional',
        'INCRG' => 'en aumento',
        'INTMT' => 'intermitente',
        'KT' => 'nudos',
        'L' => 'izquierda (referido a la designación de pista)',
        'LAST' => 'última observación antes de una interrupción en estación manual',
        'LST' => 'hora estándar local',
        'LTG' => 'actividad eléctrica',
        'LWR' => 'más bajo',
        'M' => 'menos / menor que',
        'MAX' => 'máximo',
        'METAR' => 'informe meteorológico rutinario a intervalos fijos',
        'MI' => 'baja',
        'MIN' => 'mínimo',
        'MOV' => 'se desplaza / desplazamiento',
        'MT' => 'montañas',
        'N' => 'norte',
        'N/A' => 'no aplicable',
        'NCDC' => 'Centro Nacional de Datos Climáticos (EE.UU.)',
        'NE' => 'noreste',
        'NOS' => 'Servicio Nacional Oceánico (EE.UU.)',
        'NOSPECI' => 'la estación no emite informes SPECI',
        'NOTAM' => 'aviso a los aviadores',
        'NW' => 'noroeste',
        'NWS' => 'Servicio Meteorológico Nacional (EE.UU.)',
        'OCNL' => 'ocasional',
        'OFCM' => 'Oficina del Coordinador Federal de Meteorología (EE.UU.)',
        'OHD' => 'sobre la vertical',
        'OVC' => 'cielo cubierto',
        'OVR' => 'sobre',
        'P' => 'mayor que el máximo valor informable',
        'PCPN' => 'precipitación',
        'PK WND' => 'viento máximo',
        'PL' => 'granos de hielo',
        'PNO' => 'cantidad de precipitación no disponible',
        'PO' => 'remolinos de polvo o arena',
        'PR' => 'parcial',
        'PRES' => 'presión',
        'PRESFR' => 'presión descendiendo rápidamente',
        'PRESRR' => 'presión ascendiendo rápidamente',
        'PWINO' => 'sensor identificador de precipitación no disponible',
        'PY' => 'rocío marino',
        'R' => 'derecha (referido a la designación de pista) / pista',
        'RA' => 'lluvia',
        'RTD' => 'observación rutinaria demorada',
        'RV' => 'valor informable',
        'RVR' => 'alcance visual en la pista',
        'RVRNO' => 'valores del sistema RVR no disponibles',
        'RY' => 'pista',
        'S' => 'nieve / sur',
        'SA' => 'arena',
        'SCSL' => 'estratocúmulus lenticular estacionario',
        'SCT' => 'nubes dispersas',
        'SE' => 'sudeste',
        'SFC' => 'superficie',
        'SG' => 'nieve granulada',
        'SH' => 'chaparrones',
        'SKC' => 'cielo despejado',
        'SLP' => 'presión a nivel del mar',
        'SLPNO' => 'presión a nivel del mar no disponible',
        'SM' => 'millas terrestres',
        'SN' => 'nieve',
        'SNINCR' => 'nieve aumentando rápidamente',
        'SP' => 'nieve granulada',
        'SPECI' => 'informe especial emitido fuera de horario al cumplirse ciertos criterios',
        'SQ' => 'turbonada',
        'SS' => 'tormenta de arena',
        'STN' => 'estación',
        'SW' => 'chaparrón de nieve / sudoeste',
        'TCU' => 'cúmulus en torre',
        'TS' => 'tormenta',
        'TSNO' => 'información de tormentas no disponible',
        'TWR' => 'torre',
        'UNKN' => 'desconocido',
        'UP' => 'precipitación desconocida',
        'UTC' => 'tiempo universal coordinado',
        'V' => 'variable',
        'VA' => 'ceniza volcánica',
        'VC' => 'en las proximidades',
        'VIS' => 'visibilidad',
        'VISNO' => 'visibilidad no disponible en la ubicación secundaria',
        'VR' => 'alcance visual',
        'VRB' => 'variable',
        'VV' => 'visibilidad vertical',
        'W' => 'oeste',
        'WG/SO' => 'Grupo de Trabajo de Observaciones de Superficie',
        'WMO' => 'Organización Meteorológica Mundial',
        'WND' => 'viento',
        'WSHFT' => 'cambio de viento',
        'Z' => 'hora zulú (tiempo universal coordinado)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sky condition
    |--------------------------------------------------------------------------
    |
    | Cloud amount, in oktas as the source document defines them. The octa
    | ranges are spelled out because "BKN" reads as "broken" to a pilot but as
    | nothing at all to everyone else.
    |
    */

    'sky_cover' => [
        'FEW' => 'algunas nubes (1 a 2 octavos)',
        'SCT' => 'nubes dispersas (3 a 4 octavos)',
        'BKN' => 'nubosidad rota (5 a 7 octavos)',
        'OVC' => 'cielo cubierto (8 octavos)',
        'SKC' => 'cielo despejado',
        'CLR' => 'sin nubes detectadas por debajo de 12.000 ft',
        'NSC' => 'sin nubes significativas',
        'NCD' => 'sin nubes detectadas',
    ],

    /**
     * Cloud types appended to a sky group, e.g. "BKN020CB". Both are
     * operationally significant — they are the only two ICAO requires be
     * reported — so they are called out rather than passed through.
     */
    'cloud_type' => [
        'CB' => 'cumulonimbus',
        'TCU' => 'cúmulus en torre',
    ],

    /*
    |--------------------------------------------------------------------------
    | Present weather
    |--------------------------------------------------------------------------
    |
    | A weather group is built as intensity + descriptor + phenomena, e.g.
    | "+TSRA" is heavy / thunderstorm / rain. Each part is a separate table
    | because the parser consumes them in that order.
    |
    */

    'weather_intensity' => [
        '-' => 'ligera',
        '+' => 'fuerte',
        'VC' => 'en las proximidades',
    ],

    /**
     * Templates rather than words: a descriptor and the phenomena it qualifies
     * do not compose the same way in Spanish as they do in the code. "MIFG" is
     * "niebla baja" (adjective after the noun) but "SHRA" is "chaparrones de
     * lluvia" (noun first, phenomenon governed by a preposition), so each
     * descriptor carries its own shape and ":x" marks where the phenomena go.
     *
     * A descriptor can also stand alone — "VCSH", showers in the vicinity, is
     * one of the most common groups there is — in which case the placeholder
     * and any dangling connector are dropped.
     */
    'weather_descriptor' => [
        'MI' => ':x baja',
        'BC' => 'bancos de :x',
        'PR' => ':x parcial',
        'DR' => ':x arrastrada a baja altura',
        'BL' => ':x soplando en altura',
        'SH' => 'chaparrones de :x',
        'TS' => 'tormenta con :x',
        'FZ' => ':x engelante',
    ],

    'weather_phenomenon' => [
        // Precipitation
        'DZ' => 'llovizna',
        'RA' => 'lluvia',
        'SN' => 'nieve',
        'SG' => 'nieve granulada',
        'IC' => 'cristales de hielo',
        'PL' => 'granos de hielo',
        'GR' => 'granizo',
        'GS' => 'granizo pequeño o nieve granulada',
        'UP' => 'precipitación desconocida',

        // Obscuration
        'BR' => 'neblina',
        'FG' => 'niebla',
        'FU' => 'humo',
        'VA' => 'ceniza volcánica',
        'DU' => 'polvo extendido',
        'SA' => 'arena',
        'HZ' => 'calima',
        'PY' => 'rocío marino',

        // Other
        'PO' => 'remolinos de polvo o arena',
        'SQ' => 'turbonadas',
        'FC' => 'nube embudo',
        'SS' => 'tormenta de arena',
        'DS' => 'tormenta de polvo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trend and change groups
    |--------------------------------------------------------------------------
    */

    'trend' => [
        'NOSIG' => 'sin cambios significativos previstos en las próximas 2 horas',
        'BECMG' => 'cambio gradual previsto',
        'TEMPO' => 'cambio temporario previsto',
        'NSW' => 'fin del fenómeno meteorológico significativo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Compass points
    |--------------------------------------------------------------------------
    |
    | Spanish abbreviations (O for oeste, not W), indexed by 22.5° sector so a
    | wind bearing can be named as well as given in degrees.
    |
    */

    'compass' => [
        'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
        'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO',
    ],
];
