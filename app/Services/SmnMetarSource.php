<?php

namespace App\Services;

/**
 * The SMN's METAR/SPECI screen. Everything but the query parameter and the
 * station pattern is inherited — see SmnReportSource.
 */
class SmnMetarSource extends SmnReportSource
{
    protected function observation(): string
    {
        return 'metar';
    }

    protected function stationFrom(string $raw): string
    {
        return preg_match('/^(?:METAR|SPECI)\s+(?:COR\s+)?([A-Z]{4})\b/', $raw, $m) === 1
            ? $m[1]
            : '';
    }
}
