<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A station's own last AEROMET reading fetched successfully — what
 * AerometService falls back to, marked stale, when a station is missing
 * from the current bulk fetch. Always the latest: updateOrCreate() by
 * wmo_code overwrites the row in place rather than appending to a history
 * nothing here ever reads.
 *
 * @property string $wmo_code
 * @property string $airport_name
 * @property string $issued_at
 * @property string $raw
 * @property string|null $phenomenon_note
 * @property Carbon $fetched_at
 */
class AerometStationObservation extends Model
{
    protected $fillable = [
        'wmo_code',
        'airport_name',
        'issued_at',
        'raw',
        'phenomenon_note',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }
}
