<?php

namespace App\DataObjects;

/**
 * A single TAF — the forecast for an aerodrome — plus the plain Spanish
 * explanation built from it.
 *
 * The raw text is kept verbatim, exactly as with Notam and Metar: it is the
 * authoritative, internationally-standard form of the forecast, and the
 * explanation is a convenience laid on top of it — never a replacement.
 */
final readonly class Taf
{
    /**
     * @param  string  $issuedAt  Raw "DD - HH:MM" as the SMN prints it (UTC).
     * @param  string  $source  Which source answered: 'smn' or 'noaa'.
     * @param  array<int, string>  $explanation  One plain-Spanish line per decoded group.
     */
    public function __construct(
        public string $station,
        public string $airportName,
        public string $issuedAt,
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
            issuedAt: (string) ($row['issued_at'] ?? ''),
            raw: (string) ($row['raw'] ?? ''),
            source: (string) ($row['source'] ?? 'smn'),
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
            issuedAt: $this->issuedAt,
            raw: $this->raw,
            source: $this->source,
            explanation: $explanation,
        );
    }

    /**
     * Whether this forecast reached us relayed through another service rather
     * than read from the SMN directly.
     */
    public function isRelayed(): bool
    {
        return $this->source !== 'smn';
    }

    /**
     * Whether this is an amendment (AMD) or a correction (COR) — a forecast
     * reissued before its cycle was up, which means the aerodrome's outlook
     * changed enough to withdraw the previous one. Worth flagging rather than
     * presenting as the routine six-hourly forecast.
     */
    public function isAmended(): bool
    {
        return preg_match('/^TAF\s+(?:AMD|COR)\b/', ltrim($this->raw)) === 1;
    }

    /**
     * Whether the forecast was cancelled ("TAF SAEZ 271700Z 2718/2818 CNL"),
     * meaning the aerodrome is currently without a valid TAF at all.
     */
    public function isCancelled(): bool
    {
        return preg_match('/\bCNL\b/', $this->raw) === 1;
    }
}
