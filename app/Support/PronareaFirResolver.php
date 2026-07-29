<?php

namespace App\Support;

/**
 * Works out which FIR's PRONAREA covers an aerodrome.
 *
 * PRONAREA is issued per Región de Información de Vuelo (FIR), not per
 * aerodrome, so this bridges the two the same way SunCityResolver bridges
 * ANAC aerodromes to SHN localities — a fixed map, built from the SMN's own
 * "Pronósticos emitidos por FIR" table, rather than anything geometric.
 *
 * No text-matching lives here: PRONAREA piggybacks on the aerodrome the rest
 * of the bot already resolves via AirportResolver, so all this needs to
 * answer is "given that aerodrome, which FIR speaks for it".
 */
class PronareaFirResolver
{
    /**
     * The five FIRs, by the code PRONAREA itself prints after "PRONAREA FIR".
     */
    public const FIRS = ['EZE', 'CRV', 'CBA', 'DOZ', 'SIS'];

    /**
     * Every aerodrome the SMN lists under each FIR, by ANAC code — the same
     * codes AirportResolver and SunCityResolver already use. Source: SMN,
     * "PRONAREA (Pronóstico de Área)", tabla "Pronósticos emitidos por FIR".
     *
     * @var array<string, string>
     */
    protected const AERODROME_FIRS = [
        // FIR Ezeiza
        'AER' => 'EZE', 'BCA' => 'EZE', 'BAR' => 'EZE', 'CPO' => 'EZE',
        'DIA' => 'EZE', 'PAL' => 'EZE', 'EZE' => 'EZE', 'GPI' => 'EZE',
        'GUA' => 'EZE', 'NIN' => 'EZE', 'LYE' => 'EZE', 'PTA' => 'EZE',
        'MDP' => 'EZE', 'ENO' => 'EZE', 'MOR' => 'EZE', 'NEC' => 'EZE',
        'NEU' => 'EZE', 'OLA' => 'EZE', 'PAR' => 'EZE', 'PEH' => 'EZE',
        'ROS' => 'EZE', 'FDO' => 'EZE', 'OSA' => 'EZE', 'SVO' => 'EZE',
        'DIL' => 'EZE', 'GES' => 'EZE',

        // FIR Comodoro Rivadavia
        'CRV' => 'CRV', 'BOL' => 'CRV', 'ECA' => 'CRV', 'ESQ' => 'CRV',
        'MLV' => 'CRV', 'JSM' => 'CRV', 'PTM' => 'CRV', 'ADO' => 'CRV',
        'DRY' => 'CRV', 'GAL' => 'CRV', 'GRA' => 'CRV', 'BIO' => 'CRV',
        'SAN' => 'CRV', 'SJU' => 'CRV', 'SCZ' => 'CRV', 'TRE' => 'CRV',
        'USU' => 'CRV', 'VIE' => 'CRV',

        // FIR Mendoza
        'CHM' => 'DOZ', 'MLG' => 'DOZ', 'DOZ' => 'DOZ', 'JUA' => 'DOZ',
        'UIS' => 'DOZ', 'SRA' => 'DOZ', 'RYD' => 'DOZ',

        // FIR Resistencia
        'IRI' => 'SIS', 'CRR' => 'SIS', 'FSA' => 'SIS', 'GOY' => 'SIS',
        'IGU' => 'SIS', 'ITA' => 'SIS', 'RCE' => 'SIS', 'MCS' => 'SIS',
        'LIB' => 'SIS', 'POS' => 'SIS', 'PSP' => 'SIS', 'RTA' => 'SIS',
        'SIS' => 'SIS',

        // FIR Córdoba
        'CAT' => 'CBA', 'ERE' => 'CBA', 'CBA' => 'CBA', 'ESC' => 'CBA',
        'JUJ' => 'CBA', 'LAR' => 'CBA', 'MJZ' => 'CBA', 'TRC' => 'CBA',
        'SAL' => 'CBA', 'SRC' => 'CBA', 'SDE' => 'CBA', 'TAR' => 'CBA',
        'TRH' => 'CBA', 'TUC' => 'CBA', 'LDR' => 'CBA', 'MRS' => 'CBA',
    ];

    /**
     * The FIR an aerodrome falls under, or null when it is not one of the
     * ones the SMN's table names.
     */
    public function firFor(string $anacCode): ?string
    {
        return self::AERODROME_FIRS[strtoupper($anacCode)] ?? null;
    }
}
