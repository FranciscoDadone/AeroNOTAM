<?php

namespace App\Support;

use App\Services\ShnSunService;
use Illuminate\Support\Str;

/**
 * Works out which of the SHN's localities a message is asking about.
 *
 * The rest of the bot resolves aerodromes; this resolves cities, because that
 * is what the SHN publishes — 34 of them, against ANAC's 712 aerodromes. The
 * two are bridged rather than kept apart: someone who flies asks for "EZE", not
 * for "Buenos Aires", and the sun over Ezeiza and over Aeroparque sets within a
 * minute of each other.
 */
class SunCityResolver
{
    /**
     * How people write each locality, on top of its own name.
     *
     * "Destacamento Naval Esperanza" deliberately has no "esperanza" alias:
     * there is also an Esperanza in Santa Fe, 3.500 km away, and answering with
     * the Antarctic base's twilight would be wrong by hours. Whoever means the
     * base can name it.
     *
     * @var array<string, array<int, string>>
     */
    protected const ALIASES = [
        'BAHIA BLANCA' => ['bahia blanca'],
        'BUENOS AIRES' => ['caba', 'capital federal', 'ciudad de buenos aires', 'aeroparque', 'newbery'],
        'COMODORO RIVADAVIA' => ['comodoro'],
        'DESTACAMENTO NAVAL ESPERANZA' => ['base esperanza', 'antartida'],
        'MAR DEL PLATA' => ['mardel', 'mar del plata'],
        'PERITO MORENO' => ['perito moreno'],
        'RIO GALLEGOS' => ['rio gallegos'],
        'SAN CARLOS DE BARILOCHE' => ['bariloche'],
        'SAN FERNANDO DEL VALLE DE CATAMARCA' => ['catamarca'],
        'SAN MIGUEL DE TUCUMAN' => ['tucuman'],
        'SAN SALVADOR DE JUJUY' => ['jujuy'],
        'SANTIAGO DEL ESTERO' => ['santiago del estero'],
    ];

    /**
     * The aerodrome each locality answers for, by ANAC code.
     *
     * Only the aerodrome that serves the city is listed, not every strip inside
     * it: the sun does not care which runway, and a longer list would only add
     * ways to be surprising. Ezeiza, San Fernando, Morón and El Palomar all map
     * to Buenos Aires — they are 20 to 40 km apart, under a minute of twilight.
     *
     * @var array<string, string>
     */
    protected const AERODROME_CITIES = [
        'ZUL' => 'AZUL',
        'BCA' => 'BAHIA BLANCA',
        'AER' => 'BUENOS AIRES',
        'EZE' => 'BUENOS AIRES',
        'FDO' => 'BUENOS AIRES',
        'MOR' => 'BUENOS AIRES',
        'PAL' => 'BUENOS AIRES',
        'CRV' => 'COMODORO RIVADAVIA',
        'CBA' => 'CORDOBA',
        'ESC' => 'CORDOBA',
        'CRR' => 'CORRIENTES',
        'FSA' => 'FORMOSA',
        'PTA' => 'LA PLATA',
        'IAC' => 'LA QUIACA',
        'LAR' => 'LA RIOJA',
        'MLG' => 'MALARGUE',
        'MDP' => 'MAR DEL PLATA',
        'DOZ' => 'MENDOZA',
        'NEU' => 'NEUQUEN',
        'PAR' => 'PARANA',
        'PTM' => 'PERITO MORENO',
        'POS' => 'POSADAS',
        'RAW' => 'RAWSON',
        'SIS' => 'RESISTENCIA',
        'GAL' => 'RIO GALLEGOS',
        'ROS' => 'ROSARIO',
        'SAL' => 'SALTA',
        'BAR' => 'SAN CARLOS DE BARILOCHE',
        'CAT' => 'SAN FERNANDO DEL VALLE DE CATAMARCA',
        'JUA' => 'SAN JUAN',
        'UIS' => 'SAN LUIS',
        'TUC' => 'SAN MIGUEL DE TUCUMAN',
        'JUJ' => 'SAN SALVADOR DE JUJUY',
        'SVO' => 'SANTA FE',
        'OSA' => 'SANTA ROSA',
        'SDE' => 'SANTIAGO DEL ESTERO',
        'USU' => 'USHUAIA',
        'VIE' => 'VIEDMA',
    ];

    public function __construct(protected AirportResolver $airports) {}

    /**
     * The SHN locality a message names, or null if none.
     *
     * Names first, aerodromes second. The order is what keeps "crepúsculo santa
     * rosa" on Santa Rosa: the aerodrome matcher would also land there, but a
     * city the SHN publishes by name must never depend on an aerodrome existing
     * next to it.
     */
    public function matchFromText(string $message): ?string
    {
        return $this->matchByName($message) ?? $this->matchByAerodrome($message);
    }

    /**
     * @return array<int, string>
     */
    public function cities(): array
    {
        return ShnSunService::CITIES;
    }

    /**
     * The longest name or alias the message contains wins. Length is what
     * separates "santa rosa" from "santa fe" and, more to the point, keeps
     * "santiago del estero" from being read as anything shorter that happens to
     * sit inside it.
     */
    protected function matchByName(string $message): ?string
    {
        $normalized = $this->normalize($message);
        $best = null;
        $length = 0;

        foreach (ShnSunService::CITIES as $city) {
            foreach (array_merge([$this->normalize($city)], self::ALIASES[$city] ?? []) as $name) {
                if (mb_strlen($name) > $length && str_contains($normalized, $name)) {
                    $best = $city;
                    $length = mb_strlen($name);
                }
            }
        }

        return $best;
    }

    /**
     * An aerodrome named by code or by name, mapped to the city it serves.
     * Reuses the resolver the rest of the bot uses, so "SAZR", "OSA" and the
     * ambiguity rules that keep ordinary Spanish words from matching codes all
     * behave here exactly as they do everywhere else.
     */
    protected function matchByAerodrome(string $message): ?string
    {
        $code = $this->airports->matchFromText($message);

        return $code === null ? null : $this->cityFor($code);
    }

    /**
     * The SHN locality an aerodrome answers for, by ANAC code — null when the
     * aerodrome serves none.
     *
     * Public because a tapped button carries a code and nothing else: there is
     * no sentence left to match against, and asking matchFromText() to re-derive
     * one would be inventing text the user never wrote.
     */
    public function cityFor(string $anacCode): ?string
    {
        return self::AERODROME_CITIES[$anacCode] ?? null;
    }

    protected function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower($text));
    }
}
