<?php

namespace App\DataObjects;

/**
 * A single AEROMET observation as published by the SMN, plus the plain
 * Spanish explanation built from it.
 *
 * Same split as Metar: the raw line is the SMN's own report, the explanation
 * a convenience laid on top of it. There is no separate station code the way
 * a METAR carries an ICAO indicator — AEROMET's line opens with the
 * station's name instead ("JUNIN 090/06KT ..."), which is what $station
 * holds.
 */
final readonly class AerometObservation
{
    /**
     * @param  string  $observedAt  Raw "DD - HH:MM" as the SMN prints it (UTC).
     * @param  string  $source  Which source answered: always 'smn' — AEROMET has none other.
     * @param  string|null  $phenomenonNote  The SMN's own plain-Spanish gloss of a weather
     *                                       phenomenon in the report (e.g. "Lluvia. Continua,
     *                                       no congelandose, debil..."), null when there is none.
     * @param  bool  $stale  Whether this is the last observation fetched successfully rather
     *                       than a fresh one — see AerometService, which has no second source
     *                       to fail over to when Cloudflare blocks the SMN.
     * @param  array<int, string>  $explanation  One plain-Spanish line per decoded group.
     */
    public function __construct(
        public string $station,
        public string $observedAt,
        public string $raw,
        public string $source = 'smn',
        public ?string $phenomenonNote = null,
        public bool $stale = false,
        public array $explanation = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            station: (string) ($row['airport_name'] ?? ''),
            observedAt: (string) ($row['issued_at'] ?? ''),
            raw: (string) ($row['raw'] ?? ''),
            source: (string) ($row['source'] ?? 'smn'),
            phenomenonNote: isset($row['phenomenon_note']) ? (string) $row['phenomenon_note'] : null,
            explanation: $row['explanation'] ?? [],
        );
    }

    public function withStale(bool $stale): self
    {
        return new self(
            station: $this->station,
            observedAt: $this->observedAt,
            raw: $this->raw,
            source: $this->source,
            phenomenonNote: $this->phenomenonNote,
            stale: $stale,
            explanation: $this->explanation,
        );
    }

    /**
     * @param  array<int, string>  $explanation
     */
    public function withExplanation(array $explanation): self
    {
        return new self(
            station: $this->station,
            observedAt: $this->observedAt,
            raw: $this->raw,
            source: $this->source,
            phenomenonNote: $this->phenomenonNote,
            stale: $this->stale,
            explanation: $explanation,
        );
    }
}
