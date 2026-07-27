<?php

namespace App\Services;

/**
 * The SMN's TAF screen — the same legacy page as the METAR one, so the scraping
 * is inherited wholesale from SmnReportSource.
 */
class SmnTafSource extends SmnReportSource
{
    protected function observation(): string
    {
        return 'taf';
    }

    /**
     * "TAF SAEZ ...", "TAF AMD SAEZ ...", "TAF COR SAEZ ..." — the amendment
     * and correction markers sit between the keyword and the station.
     */
    protected function stationFrom(string $raw): string
    {
        return preg_match('/^TAF\s+(?:(?:AMD|COR)\s+)?([A-Z]{4})\b/', $raw, $m) === 1
            ? $m[1]
            : '';
    }
}
