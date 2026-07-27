<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Airport extends Model
{
    protected $fillable = [
        'anac_code',
        'icao_code',
        'name',
        'is_aerodrome',
        'last_seen_active_at',
    ];

    protected function casts(): array
    {
        return [
            'is_aerodrome' => 'boolean',
            'last_seen_active_at' => 'datetime',
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
     * Whether an ANAC place indicator denotes an actual aerodrome rather than
     * a FIR-wide advisory bulletin.
     */
    public static function isAerodromeCode(string $anacCode): bool
    {
        return ctype_alpha($anacCode);
    }
}
