<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $anac_code
 * @property string|null $icao_code
 * @property string $name
 * @property bool $is_aerodrome
 * @property string $kind
 * @property string|null $access
 * @property bool $is_controlled
 * @property bool $is_closed
 * @property Carbon|null $last_seen_active_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $magnetic_variation
 * @property Carbon|null $magnetic_variation_at
 * @property string|null $iata_code
 * @property string|null $fir
 * @property string|null $city_reference
 * @property float|null $distance_km
 * @property string|null $direction_reference
 * @property int|null $elevation_m
 * @property string|null $state
 * @property string|null $traffic
 * @property bool $is_aip_delegated
 * @property string|null $fuel
 * @property array<int, string>|null $telephone
 * @property string|null $service_schedule
 * @property Carbon|null $details_updated_at
 * @property string|null $aip_fuel
 * @property array<int, string>|null $aip_telephone
 * @property string|null $aip_service_schedule
 * @property string|null $aip_ats_frequency
 * @property array<int, array{type: string, id: string|null, frequency: string, unit: string, hours: string|null}>|null $aip_navaids
 * @property Carbon|null $aip_details_updated_at
 */
class Airport extends Model
{
    protected $fillable = [
        'anac_code',
        'icao_code',
        'name',
        'is_aerodrome',
        'kind',
        'access',
        'is_controlled',
        'is_closed',
        'last_seen_active_at',
        'latitude',
        'longitude',
        'magnetic_variation',
        'magnetic_variation_at',
        'iata_code',
        'fir',
        'city_reference',
        'distance_km',
        'direction_reference',
        'elevation_m',
        'state',
        'traffic',
        'is_aip_delegated',
        'fuel',
        'telephone',
        'service_schedule',
        'details_updated_at',
        'aip_fuel',
        'aip_telephone',
        'aip_service_schedule',
        'aip_ats_frequency',
        'aip_navaids',
        'aip_details_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_aerodrome' => 'boolean',
            'is_controlled' => 'boolean',
            'is_closed' => 'boolean',
            'last_seen_active_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'magnetic_variation' => 'float',
            'magnetic_variation_at' => 'datetime',
            'distance_km' => 'float',
            'elevation_m' => 'integer',
            'is_aip_delegated' => 'boolean',
            'telephone' => 'array',
            'details_updated_at' => 'datetime',
            'aip_telephone' => 'array',
            'aip_navaids' => 'array',
            'aip_details_updated_at' => 'datetime',
        ];
    }

    /**
     * ANAC's list mixes real aerodromes with FIR-wide advisory pseudo-codes
     * ("---" for all FIRs, "-EF" for the Ezeiza FIR). Those are bulletins, not
     * places, and matching a user's "cordoba" against one would hand them the
     * region-wide advisory instead of the airport they asked for.
     *
     * @param  Builder<Airport>  $query
     * @return Builder<Airport>
     */
    public function scopeRealAerodromes(Builder $query): Builder
    {
        return $query->where('is_aerodrome', true);
    }

    /**
     * The aerodromes a person might plausibly name in free text: open to the
     * public and not closed. MADHEL's other ~450 entries are private strips,
     * agricultural runways and heliports — nobody asks for "the NOTAMs of
     * Coronel Bogado / Agroservicios" by name, and carrying them into a name
     * match or an AI prompt only adds noise and tokens.
     *
     * @param  Builder<Airport>  $query
     * @return Builder<Airport>
     */
    public function scopePubliclyKnown(Builder $query): Builder
    {
        return $query->realAerodromes()
            ->where('access', 'publico')
            ->where('is_closed', false);
    }

    /**
     * Whether an ANAC place indicator denotes an actual aerodrome rather than
     * a FIR-wide advisory bulletin.
     */
    public static function isAerodromeCode(string $anacCode): bool
    {
        return ctype_alpha($anacCode);
    }
}
