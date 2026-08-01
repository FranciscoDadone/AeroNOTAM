<?php

namespace App\DataObjects;

/**
 * One row of the AIP's "Ad" listing: a document the AIP publishes about one
 * aerodrome.
 *
 * The listing is the only index there is — the AIP has no per-aerodrome API —
 * and every row of it is an aerodrome's ICAO code, the AD section the document
 * belongs to, its title and a download link. The link is not stable: it embeds
 * a hash that changes with each AIRAC amendment, which is why nothing here is
 * ever cached by URL and why a document is named by the other three fields.
 */
final readonly class AipDocument
{
    public function __construct(
        public string $icaoCode,
        public string $code,
        public string $title,
        public string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            icaoCode: (string) ($row['icao_code'] ?? ''),
            code: (string) ($row['code'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            url: (string) ($row['url'] ?? ''),
        );
    }

    /**
     * What names this document across AIRAC cycles: the aerodrome, the section
     * and the title, but never the URL. The chart is the same chart after an
     * amendment moves its download link.
     */
    public function identity(): string
    {
        return "{$this->icaoCode}|{$this->code}|{$this->title}";
    }
}
