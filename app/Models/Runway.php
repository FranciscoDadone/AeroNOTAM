<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $anac_code
 * @property string $designator
 * @property int $heading_true
 * @property bool $is_closed
 * @property string $source
 * @property int|null $length_m
 * @property int|null $width_m
 * @property string|null $surface
 * @property bool|null $is_lighted
 */
class Runway extends Model
{
    protected $fillable = [
        'anac_code',
        'designator',
        'heading_true',
        'is_closed',
        'source',
        'length_m',
        'width_m',
        'surface',
        'is_lighted',
    ];

    protected function casts(): array
    {
        return [
            'heading_true' => 'integer',
            'is_closed' => 'boolean',
            'length_m' => 'integer',
            'width_m' => 'integer',
            'is_lighted' => 'boolean',
        ];
    }

    /**
     * The end at the other side of the same strip — "05" for "23", "01L" for
     * "19R" — or null when the designator is not one this can be derived from.
     *
     * Both ends of a runway carry the same dimensions, so the ficha lists them
     * once as "01/19" rather than twice. Pairing by arithmetic rather than by
     * comparing the stored dimensions is what keeps "05/23 700x15" and
     * "09/27 700x15" at an aerodrome that has both from being merged into one.
     *
     * The side flips with the number: standing on 01L you are looking down the
     * same asphalt someone on 19R is looking back along.
     */
    public function oppositeDesignator(): ?string
    {
        if (preg_match('/^(\d{2})([LCR])?$/', $this->designator, $m) !== 1) {
            return null;
        }

        $opposite = ((int) $m[1] + 17) % 36 + 1;

        return sprintf('%02d', $opposite).match ($m[2] ?? '') {
            'L' => 'R',
            'R' => 'L',
            default => $m[2] ?? '',
        };
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
