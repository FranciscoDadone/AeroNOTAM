<?php

namespace App\DataObjects;

/**
 * What the wind is doing to one runway end: how much of it is along the runway
 * and how much is across it.
 *
 * The whole thing is one right triangle. The wind blows *from* its reported
 * bearing, so the angle that matters is the one between that bearing and the
 * direction the runway points. Along that angle the wind is a headwind; square
 * to it, a crosswind. See App\Support\RunwayWind for the arithmetic.
 *
 * The gust figures are not decoration. A gust is what the aircraft actually has
 * to be flown for on the flare, and it is the number a crosswind limit is
 * normally checked against — reporting only the steady wind would understate
 * exactly the case that matters.
 */
final readonly class RunwayWindComponent
{
    public function __construct(
        public string $designator,
        public bool $isClosed,
        /** Knots along the runway; negative is a tailwind. */
        public int $headwind,
        /** Knots across the runway, always positive — $side says which way. */
        public int $crosswind,
        /** 'izq' or 'der', from the point of view of someone using this end. */
        public string $side,
        public ?int $gustHeadwind = null,
        public ?int $gustCrosswind = null,
    ) {}

    public function isTailwind(): bool
    {
        return $this->headwind < 0;
    }
}
