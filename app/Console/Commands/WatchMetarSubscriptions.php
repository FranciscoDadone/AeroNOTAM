<?php

namespace App\Console\Commands;

use App\DataObjects\Metar;
use App\Jobs\NotifyMetarChange;
use App\Jobs\SendWhatsappMessage;
use App\Models\MetarSubscription;
use App\Services\MetarService;
use App\Services\WhatsappBotService;
use App\Support\MetarConditions;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One round of "has anything changed at the aerodromes people are watching".
 *
 * Scheduled every ten minutes, which lines up with the METAR cache TTL so a
 * round costs at most one real request per watched station however many people
 * are watching it. METARs are issued hourly and SPECIs whenever conditions
 * warrant, so ten minutes is fast enough that nobody learns about a SPECI long
 * after the fact, and slow enough that the SMN never notices us.
 */
class WatchMetarSubscriptions extends Command
{
    protected $signature = 'metar:watch';

    protected $description = 'Notify WhatsApp subscribers whose watched aerodrome has had a significant weather change.';

    public function handle(MetarService $metars, WhatsappBotService $bot): int
    {
        $expired = $this->closeExpired($bot);

        /** @var Collection<string, Collection<int, MetarSubscription>> $byStation */
        $byStation = MetarSubscription::query()
            ->active()
            ->get()
            ->groupBy('icao_code');

        $notified = 0;
        $unreachable = 0;

        foreach ($byStation as $icao => $subscriptions) {
            $current = $this->currentMetar($metars, (string) $icao);

            if ($current === null) {
                $unreachable++;

                continue;
            }

            $notified += $this->reconcile($subscriptions, $current);
        }

        $this->info(sprintf(
            '%d estaciones consultadas (%d sin respuesta), %d avisos despachados, %d alertas vencidas.',
            $byStation->count(),
            $unreachable,
            $notified,
            $expired,
        ));

        return self::SUCCESS;
    }

    /**
     * Tell everyone whose watch ran out, then drop it.
     *
     * Done before anything else so the round never queries the SMN for a
     * station nobody is watching any more.
     */
    protected function closeExpired(WhatsappBotService $bot): int
    {
        $expired = MetarSubscription::query()->expired()->get();

        foreach ($expired as $subscription) {
            SendWhatsappMessage::dispatch($subscription->phone, $bot->expiryNotice($subscription->anac_code));
            $subscription->delete();
        }

        return $expired->count();
    }

    /**
     * The current observation for a station, or null when there is nothing to
     * compare against.
     *
     * A source failure returns null rather than throwing: one blocked station
     * must not end the round for the others, and the cooldown and failover in
     * AviationReportService are already the answer to a source being down.
     */
    protected function currentMetar(MetarService $metars, string $icao): ?Metar
    {
        try {
            $reports = $metars->getMetars($icao);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $reports[0] ?? null;
    }

    /**
     * Compare one station's current observation against what each watcher was
     * last measured from, and notify the ones for whom something changed.
     *
     * @param  Collection<int, MetarSubscription>  $subscriptions
     * @return int Alerts dispatched.
     */
    protected function reconcile(Collection $subscriptions, Metar $current): int
    {
        $conditions = MetarConditions::fromRaw($current->raw);
        $notified = 0;

        foreach ($subscriptions as $subscription) {
            if ($subscription->last_raw === $current->raw) {
                continue;
            }

            // No baseline should be possible — one is written when the watch is
            // created — but a row without one would otherwise compare against
            // nothing and alert on everything. Seed it and wait for the next
            // round.
            if ($subscription->last_raw === null) {
                $subscription->update(['last_raw' => $current->raw]);

                continue;
            }

            $changes = $conditions->changesFrom(MetarConditions::fromRaw($subscription->last_raw));

            // The baseline advances either way, and that is the point. Letting
            // it sit at the last *notified* report would turn a slow drift —
            // a knot an hour, a hectopascal an hour — into an alert six hours
            // later about a change nobody would have noticed happening.
            $subscription->update([
                'last_raw' => $current->raw,
                'last_notified_at' => $changes === [] ? $subscription->last_notified_at : now(),
            ]);

            if ($changes === []) {
                continue;
            }

            NotifyMetarChange::dispatch(
                $subscription->phone,
                $subscription->anac_code,
                $current->toArray(),
                $changes,
                $subscription->expiryLabel(),
            );

            $notified++;
        }

        return $notified;
    }
}
