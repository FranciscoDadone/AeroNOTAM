<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A standing request to be told when an aerodrome's METAR changes.
 *
 * @property int $id
 * @property string $phone
 * @property string $anac_code
 * @property string $icao_code
 * @property Carbon $expires_at
 * @property string|null $last_raw
 * @property Carbon|null $last_notified_at
 */
class MetarSubscription extends Model
{
    protected $fillable = [
        'phone',
        'anac_code',
        'icao_code',
        'expires_at',
        'last_raw',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MetarSubscription>  $query
     * @return Builder<MetarSubscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<MetarSubscription>  $query
     * @return Builder<MetarSubscription>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * @param  Builder<MetarSubscription>  $query
     * @return Builder<MetarSubscription>
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * How the expiry reads in a WhatsApp message: UTC, because every other time
     * the bot prints — NOTAM validity, observation time, TAF periods — is UTC,
     * and mixing the two in one conversation is how someone reads a closure
     * window three hours off.
     */
    public function expiryLabel(): string
    {
        return $this->expires_at->utc()->format('d/m H:i').' UTC';
    }
}
