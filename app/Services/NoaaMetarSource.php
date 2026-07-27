<?php

namespace App\Services;

/**
 * NOAA's relay of the SMN's observations — see NoaaReportSource.
 */
class NoaaMetarSource extends NoaaReportSource
{
    protected function endpoint(): string
    {
        return 'metar';
    }

    protected function rawField(): string
    {
        return 'rawOb';
    }

    protected function hours(): int
    {
        return (int) config('services.noaa.metar_hours');
    }
}
