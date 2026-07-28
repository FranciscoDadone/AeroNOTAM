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
     * @param  string  $source  Which source answered: 'smn' or 'noaa'.
     * @param  array<int, string>  $explanation  One plain-Spanish line per decoded group.
     */
    public function __construct(
        public string $station,
        public string $airportName,
        public string $observedAt,
        public string $raw,
        public string $source = 'smn',
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
            // 'observed_at' is the key rows used before the sources were shared
            // with the forecast side; accepting it too keeps whatever is still
            // in the cache at deploy time readable until it expires.
            observedAt: (string) ($row['issued_at'] ?? $row['observed_at'] ?? ''),
            raw: (string) ($row['raw'] ?? ''),
            source: (string) ($row['source'] ?? 'smn'),
            explanation: $row['explanation'] ?? [],
        );
    }

    /**
     * The inverse of fromArray(), for the places that have to hand an
     * observation across a boundary the class shape cannot cross — a cache
     * entry or a queued job payload, either of which can outlive the deploy
     * that wrote it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'station' => $this->station,
            'airport_name' => $this->airportName,
            'issued_at' => $this->observedAt,
            'raw' => $this->raw,
            'source' => $this->source,
            'explanation' => $this->explanation,
        ];
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
            source: $this->source,
            explanation: $explanation,
        );
    }

    /**
     * Whether this observation reached us relayed through another service
     * rather than read from the SMN directly.
     */
    public function isRelayed(): bool
    {
        return $this->source !== 'smn';
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
