<?php

namespace App\Services;

use App\DataObjects\Notam;
use Throwable;

/**
 * Adds the human-readable Spanish decoding to raw NOTAMs coming out of
 * AnacNotamService.
 *
 * Kept deliberately separate from the scraping/parsing in AnacNotamService so
 * that a failure here — a dead AI provider, an exhausted quota, a missing API
 * key — degrades the response instead of destroying it. The raw NOTAM is the
 * safety-critical payload; the decoding is a convenience on top of it, and it
 * must never be able to take the payload down with it.
 */
class NotamEnricher
{
    public function __construct(
        protected AiNotamDecoderService $ai,
        protected NotamAbbreviationDecoder $decoder,
    ) {}

    /**
     * @param  array<int, Notam>  $notams
     * @return array<int, Notam>
     */
    public function enrich(array $notams): array
    {
        return array_map($this->enrichOne(...), $notams);
    }

    protected function enrichOne(Notam $notam): Notam
    {
        [$decoded, $source] = $this->decode(
            $notam->textEn,
            $notam->textEs,
            $notam->expiresAt(),
        );

        return $notam->withDecoding($decoded, $source);
    }

    /**
     * @return array{0: string|null, 1: string|null} The decoded text and which
     *                                               decoder produced it ('ai', 'dictionary', or null for neither).
     */
    protected function decode(string $textEn, ?string $textEs, ?string $validUntil): array
    {
        try {
            $decoded = $this->ai->decode($textEn, $textEs, $validUntil);

            if ($decoded !== null && $decoded !== '') {
                return [$decoded, 'ai'];
            }
        } catch (Throwable $e) {
            report($e);
        }

        // The dictionary decoder is offline and free, so it costs nothing to
        // fall back to — a bluntly-worded expansion still beats handing a
        // pilot raw "RWY 11/29 CLSD DUE WIP".
        try {
            $fallback = $this->decoder->decodeEs($textEn);

            if ($fallback !== null && $fallback !== '') {
                return [$fallback, 'dictionary'];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [null, null];
    }
}
