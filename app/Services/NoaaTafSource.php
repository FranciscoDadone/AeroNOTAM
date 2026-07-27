<?php

namespace App\Services;

/**
 * NOAA's relay of the SMN's forecasts — see NoaaReportSource.
 */
class NoaaTafSource extends NoaaReportSource
{
    protected function endpoint(): string
    {
        return 'taf';
    }

    protected function rawField(): string
    {
        return 'rawTAF';
    }

    /**
     * The look-back window has to clear one issue cycle: TAFs go out every six
     * hours, so a shorter window would come back empty for stations that had
     * simply not reissued yet, and be read as "no forecast published".
     */
    protected function hours(): int
    {
        return (int) config('services.noaa.taf_hours');
    }
}
