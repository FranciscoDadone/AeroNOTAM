<?php

namespace App\DataObjects;

/**
 * A single NOTAM as published by ANAC, plus whatever decoding we managed to
 * add on top of it.
 *
 * Dates are kept as the raw "Y-m-d H:i:s" UTC strings ANAC provides rather
 * than being parsed into Carbon: they are passed through to the user verbatim,
 * and re-formatting a safety-critical time is a good way to introduce a
 * timezone bug that nobody notices until it matters.
 */
final readonly class Notam
{
    public function __construct(
        public string $number,
        public string $locationCode,
        public string $locationName,
        public string $validFrom,
        public ?string $validUntil,
        public bool $permanent,
        public string $textEn,
        public ?string $textEs = null,
        public ?string $decodedEs = null,
        public ?string $decodedBy = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            number: (string) ($row['number'] ?? ''),
            locationCode: (string) ($row['location_code'] ?? ''),
            locationName: (string) ($row['location_name'] ?? ''),
            validFrom: (string) ($row['valid_from'] ?? ''),
            validUntil: $row['valid_until'] ?? null,
            permanent: (bool) ($row['permanent'] ?? false),
            textEn: (string) ($row['text_en'] ?? ''),
            textEs: $row['text_es'] ?? null,
            decodedEs: $row['decoded_es'] ?? null,
            decodedBy: $row['decoded_by'] ?? null,
        );
    }

    /**
     * @param  string|null  $by  Which decoder produced it: 'ai', 'dictionary', or null.
     */
    public function withDecoding(?string $decoded, ?string $by): self
    {
        return new self(
            number: $this->number,
            locationCode: $this->locationCode,
            locationName: $this->locationName,
            validFrom: $this->validFrom,
            validUntil: $this->validUntil,
            permanent: $this->permanent,
            textEn: $this->textEn,
            textEs: $this->textEs,
            decodedEs: $decoded,
            decodedBy: $by,
        );
    }

    /**
     * The decoded Spanish text when we have one, falling back to the raw
     * source — a NOTAM must always be readable, even undecoded.
     */
    public function displayText(): string
    {
        return $this->decodedEs ?? $this->textEn;
    }

    /**
     * The expiry to cache a decoding against: null for permanent NOTAMs,
     * which never go stale.
     */
    public function expiresAt(): ?string
    {
        return $this->permanent ? null : $this->validUntil;
    }
}
