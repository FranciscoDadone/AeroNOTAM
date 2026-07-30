<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Works out which AEROMET station a message is asking about.
 *
 * AEROMET's network is wider than the aerodromes AirportResolver knows —
 * it also covers towns with no aerodrome at all (Azul, Ceres, Chepes) — so
 * this does not bridge to it the way SunCityResolver/PronareaFirResolver
 * bridge to an ANAC code. It resolves straight from free text to the SMN's
 * own WMO/OMM station code.
 *
 * Source: the SMN's "mensajes" application, "Observaciones AEROMET" screen,
 * Sector "Todo Argentina" — the 119 stations it lists nationwide, read off a
 * live browser session (Cloudflare blocks this the same as everything else
 * on that host, see SmnReportSource).
 */
class AerometStationResolver
{
    /**
     * Every AEROMET station, by WMO/OMM code.
     *
     * @var array<string, string>
     */
    public const STATIONS = [
        'AEROPARQUE J. NEWBERY' => '87582', 'AZUL' => '87641', 'BAHIA BLANCA' => '87750',
        'BARILOCHE' => '87765', 'BENITO JUAREZ' => '87649', 'BERNARDO DE IRIGOYEN' => '87163',
        'BOLIVAR AERO' => '87640', 'BUENOS AIRES OBS. CENTRAL' => '87585', 'CAMPO DE MAYO' => '87570',
        'CATAMARCA' => '87222', 'CERES' => '87257', 'CHACRAS DE CORIA' => '87454',
        'CHAMICAL' => '87320', 'CHAPELCO' => '87761', 'CHEPES' => '87322', 'CHILECITO' => '87213',
        'CIPOLLETTI' => '87719', 'COMODORO RIVADAVIA' => '87860', 'CONCORDIA' => '87395',
        'CORDOBA' => '87344', 'CORDOBA - OBSERVATORIO' => '87345', 'CORONEL PRINGLES' => '87683',
        'CORONEL SUAREZ' => '87637', 'CORRIENTES' => '87166', 'DOLORES' => '87648',
        'EL BOLSON AERO' => '87800', 'EL CALAFATE' => '87904', 'EL PALOMAR' => '87571',
        'EL TREBOL' => '87470', 'ESCUELA DE AVIACION' => '87347', 'ESQUEL' => '87803',
        'EZEIZA' => '87576', 'FORMOSA' => '87162', 'GENERAL PICO' => '87532',
        'GOBERNADOR GREGORES' => '87880', 'GUALEGUAYCHU' => '87497', 'IGUAZU/CATARATAS' => '87097',
        'ITUZAINGO' => '87173', 'JACHAL' => '87305', 'JUJUY' => '87046', 'JUNIN' => '87548',
        'LA PLATA' => '87593', 'LA QUIACA OBSERVATORIO' => '87007', 'LA RIOJA' => '87217',
        'LABOULAYE' => '87534', 'LAS FLORES' => '87563', 'LAS LOMITAS' => '87078',
        'MALARGUE' => '87506', 'MAQUINCHAO' => '87774', 'MAR DEL PLATA' => '87692',
        'MARCOS JUAREZ' => '87467', 'MARIANO MORENO' => '87572', 'MENDOZA' => '87418',
        'MENDOZA OBSERVATORIO' => '87420', 'MERCEDES - CORRIENTES' => '87281',
        'MERLO, BUENOS AIRES' => '87573', 'METAN' => '87050', 'MONTE CASEROS' => '87393',
        'MORON' => '87574', 'NEUQUEN' => '87715', 'NUEVE DE JULIO' => '87550', 'OBERA' => '87187',
        'OLAVARRIA' => '87643', 'ORAN' => '87016', 'P. ROQUE SAENZ PEÑA' => '87148',
        'PARANA' => '87374', 'PASO DE INDIOS' => '87814', 'PASO DE LOS LIBRES' => '87289',
        'PEHUAJO' => '87544', 'PERITO MORENO' => '87852', 'PIGUE' => '87679',
        'PILAR OBSERVATORIO' => '87349', 'POSADAS' => '87178', 'PUERTO DESEADO' => '87896',
        'PUERTO MADRYN' => '87823', 'PUNTA DE VACA' => '87403', 'PUNTA INDIO' => '87596',
        'RAFAELA, SANTA FE' => '87360', 'RECONQUISTA' => '87270', 'RESISTENCIA' => '87155',
        'RIO COLORADO' => '87736', 'RIO CUARTO' => '87453', 'RIO GALLEGOS' => '87925',
        'RIO GRANDE' => '87934', 'RIVADAVIA' => '87065', 'ROSARIO' => '87480', 'SALTA' => '87047',
        'SAN ANTONIO OESTE' => '87784', 'SAN CARLOS' => '87412', 'SAN FERNANDO' => '87553',
        'SAN JUAN' => '87311', 'SAN JULIAN' => '87909', 'SAN LUIS' => '87436',
        'SAN MARTIN MENDOZA' => '87416', 'SAN RAFAEL' => '87509', 'SANTA CRUZ' => '87912',
        'SANTA ROSA' => '87623', 'SANTA ROSA DEL CONLARA' => '87444',
        'SANTIAGO DEL ESTERO' => '87129', 'SAUCE VIEJO' => '87371', 'SUNCHALES AERO' => '87356',
        'TANDIL' => '87645', 'TARTAGAL' => '87022', 'TERMAS DE RIO HONDO' => '87127',
        'TINOGASTA' => '87211', 'TRELEW' => '87828', 'TRENQUE LAUQUEN' => '87540',
        'TRES ARROYOS' => '87688', 'TUCUMAN' => '87121', 'UNIV JUJUY' => '87043',
        'USHUAIA' => '87938', 'USPALLATA' => '87405', 'VENADO TUERTO' => '87468',
        'VICTORICA' => '87616', 'VIEDMA' => '87791', 'VILLA DOLORES' => '87328',
        'VILLA GESELL' => '87663', 'VILLA MARIA DEL RIO SECO' => '87244', 'VILLA REYNOLDS' => '87448',
    ];

    /**
     * How people write the stations whose listed name they would not
     * naturally type in full — a province-qualified name ("MERLO, Buenos
     * Aires") or a secondary station for a city that already has a shorter,
     * more obvious entry of its own ("CORDOBA - OBSERVATORIO" next to plain
     * "CORDOBA"). Deliberately does not give the secondary stations the
     * city's bare name: "cordoba" must resolve to the primary CORDOBA entry,
     * not to its observatory.
     *
     * @var array<string, array<int, string>>
     */
    protected const ALIASES = [
        'MERCEDES - CORRIENTES' => ['mercedes corrientes'],
        'MERLO, BUENOS AIRES' => ['merlo buenos aires'],
        'RAFAELA, SANTA FE' => ['rafaela'],
        'LA QUIACA OBSERVATORIO' => ['la quiaca'],
        'PILAR OBSERVATORIO' => ['pilar'],
        'P. ROQUE SAENZ PEÑA' => ['saenz peña', 'roque saenz peña'],
    ];

    /**
     * The WMO/OMM code a message names, or null if none of the 119 stations
     * matches.
     *
     * The longest name or alias the message contains wins — same rule as
     * SunCityResolver, and for the same reason: it is what keeps "santiago
     * del estero" from being read as anything shorter sitting inside it.
     */
    public function codeFromText(string $message): ?string
    {
        $normalized = $this->normalize($message);
        $best = null;
        $length = 0;

        foreach (self::STATIONS as $name => $code) {
            $candidates = array_merge([$this->normalize($name)], self::ALIASES[$name] ?? []);

            foreach ($candidates as $candidate) {
                if (mb_strlen($candidate) > $length && str_contains($normalized, $candidate)) {
                    $best = $code;
                    $length = mb_strlen($candidate);
                }
            }
        }

        return $best;
    }

    /**
     * The station name AEROMET prints for a code, or the code itself when
     * it names none of the 119 stations — same fallback shape as
     * AirportResolver::nameFor().
     */
    public function nameFor(string $code): string
    {
        return array_search($code, self::STATIONS, true) ?: $code;
    }

    /**
     * The WMO/OMM code for an aerodrome's own name, or null when AEROMET does
     * not cover it.
     *
     * The bridge a caller uses for a station AEROMET also covers as an ANAC
     * aerodrome — "aeromet nin" naming Junín by its ANAC code, which
     * codeFromText() never looks at, since AEROMET's network reaches towns
     * with no aerodrome at all and this class resolves straight from free
     * text instead (see the class docblock). Normalized the same way
     * codeFromText() normalizes, so an accent — ANAC's own "JUNÍN" against
     * this class's "JUNIN" — is never the reason a real match is missed.
     */
    public function codeForName(string $name): ?string
    {
        $normalized = $this->normalize($name);

        foreach (self::STATIONS as $stationName => $code) {
            if ($this->normalize($stationName) === $normalized) {
                return $code;
            }
        }

        return null;
    }

    protected function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower($text));
    }
}
