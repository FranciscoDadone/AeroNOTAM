<?php

namespace App\Support;

/**
 * The half of a MADHEL record the registry import ignores: where the aerodrome
 * is relative to the town it serves, how high, which FIR, and — for the small
 * ones — fuel, telephone and opening hours.
 *
 * Mirrors MadhelIdentifier, and for the same reason: it is the one place that
 * decides what a field means, so the importer and its tests cannot end up
 * disagreeing about it.
 *
 * Two shapes of record, and the difference is not a data-quality problem but a
 * documented split:
 *
 *   - `metadata` is filled in for every aerodrome, the busiest included.
 *   - `data` comes back empty for the ones MADHEL delegates to the AIP
 *     (`is_granted: true`) — Ezeiza, Aeroparque, Córdoba, Rosario, Santa Rosa.
 *     Their fuel, telephone and runways are published in the AIP instead.
 *
 * So a null out of here means "MADHEL does not publish it", never "the
 * aerodrome does not have it", and the caller is expected to say so in those
 * words. Fuel is published for barely one aerodrome in seven; reading its
 * absence as "no hay combustible" would be inventing a fact about a place a
 * pilot is deciding whether to fly to.
 */
final class MadhelDetails
{
    /**
     * @param  array<string, mixed>  $record  One MADHEL detail response.
     * @return array{iata_code: string|null, fir: string|null, city_reference: string|null, distance_km: float|null, direction_reference: string|null, elevation_m: int|null, state: string|null, traffic: string|null, is_aip_delegated: bool, fuel: string|null, telephone: array<int, string>|null, service_schedule: string|null}
     */
    public static function parse(array $record): array
    {
        return [
            'iata_code' => self::text(data_get($record, 'metadata.identifiers.iata')),
            'fir' => self::text(data_get($record, 'metadata.localization.fir')),
            'city_reference' => self::text(data_get($record, 'metadata.localization.city_reference')),
            'distance_km' => self::number(data_get($record, 'metadata.localization.distance_reference')),
            'direction_reference' => self::text(data_get($record, 'metadata.localization.direction_reference')),
            'elevation_m' => self::integer(data_get($record, 'metadata.localization.elevation')),
            'state' => self::text(data_get($record, 'metadata.localization.state')),
            'traffic' => self::text(data_get($record, 'metadata.traffic')),
            'is_aip_delegated' => data_get($record, 'metadata.is_granted') === true,
            'fuel' => self::text(data_get($record, 'data.fuel')),
            'telephone' => self::strings(data_get($record, 'data.telephone')),
            'service_schedule' => self::text(data_get($record, 'data.service_schedule')),
        ];
    }

    /**
     * A published string, or null for every way MADHEL has of saying nothing.
     *
     * The literal "None" is in there because the registry is served by a Python
     * application that has, in places, stringified its own null on the way out.
     * Storing it would put the word "None" in front of a pilot as though it
     * were a fuel grade.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' || strcasecmp($trimmed, 'none') === 0 ? null : $trimmed;
    }

    /**
     * MADHEL sends the distance as a string ("6", "4.5"), so this reads it as
     * one rather than trusting the JSON type.
     */
    private static function number(mixed $value): ?float
    {
        $text = self::text($value);

        return $text !== null && is_numeric($text) ? (float) $text : null;
    }

    private static function integer(mixed $value): ?int
    {
        $text = self::text($value);

        return $text !== null && is_numeric($text) ? (int) round((float) $text) : null;
    }

    /**
     * The telephone list. Null rather than [] when there is nothing, so that
     * "not published" and "published as empty" do not have to be told apart
     * downstream — MADHEL only ever means the first.
     *
     * @return array<int, string>|null
     */
    private static function strings(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $entry) {
            $text = self::text($entry);

            if ($text !== null) {
                $strings[] = $text;
            }
        }

        return $strings === [] ? null : $strings;
    }
}
