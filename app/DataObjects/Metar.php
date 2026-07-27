<?php

namespace App\DataObjects;

/**
 * A single METAR/SPECI observation as published by the SMN, plus the plain
 * Spanish explanation built from it.
 *
 * The raw text is kept verbatim, exactly as with Notam: it is the
 * authoritative, internationally-standard form of the observation, and the
 * explanation is a convenience laid on top of it — never a replacement.
 */
final readonly class Metar
{
    /**
     * @param  string  $observedAt  Raw "DD - HH:MM" as the SMN prints it (UTC).
     * @param  array<int, string>  $explanation  One plain-Spanish line per decoded group.
     */
    public function __construct(
        public string $station,
        public string $airportName,
        public string $observedAt,
        public string $raw,
        public array $explanation = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            station: (string) ($row['station'] ?? ''),
            airportName: (string) ($row['airport_name'] ?? ''),
            observedAt: (string) ($row['observed_at'] ?? ''),
            raw: (string) ($row['raw'] ?? ''),
            explanation: $row['explanation'] ?? [],
        );
    }

    /**
     * @param  array<int, string>  $explanation
     */
    public function withExplanation(array $explanation): self
    {
        return new self(
            station: $this->station,
            airportName: $this->airportName,
            observedAt: $this->observedAt,
            raw: $this->raw,
            explanation: $explanation,
        );
    }

    /**
     * Whether this observation is a SPECI — an unscheduled report issued
     * because conditions crossed a significant threshold, and therefore worth
     * flagging rather than presenting as a routine hourly reading.
     */
    public function isSpeci(): bool
    {
        return str_starts_with(ltrim($this->raw), 'SPECI');
    }
}
