<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $anac_code
 * @property string $designator
 * @property int $heading_true
 * @property bool $is_closed
 * @property string $source
 */
class Runway extends Model
{
    protected $fillable = [
        'anac_code',
        'designator',
        'heading_true',
        'is_closed',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'heading_true' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * The magnetic heading the designator itself stands for — "05" means 050°.
     *
     * Only ever used to explain a number back to a reader, never to compute
     * one: heading_true is what the wind is measured against.
     */
    public function designatedHeading(): int
    {
        return ((int) preg_replace('/\D/', '', $this->designator) % 36) * 10;
    }
}
