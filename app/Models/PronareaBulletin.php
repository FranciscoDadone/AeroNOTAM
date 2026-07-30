<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A FIR's own last PRONAREA bulletin fetched successfully — what
 * SmnPronareaService falls back to, marked stale, when a fresh fetch fails.
 * Always the latest: updateOrCreate() by fir overwrites the row in place
 * rather than appending to a history nothing here ever reads.
 *
 * @property string $fir
 * @property string $raw
 * @property Carbon $fetched_at
 */
class PronareaBulletin extends Model
{
    protected $fillable = [
        'fir',
        'raw',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }
}
