<?php

namespace App\Support;

use App\Models\MetarSubscription;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the admin dashboard counts.
 *
 * Timestamps are stored in UTC, but every window here ("hoy", "por hora del
 * día") is cut against Argentine local time — a day that starts at 21:00 the
 * previous evening would make the traffic charts unreadable. Local boundaries
 * are therefore converted back to UTC before they reach the query builder,
 * which formats a Carbon in whatever timezone it carries.
 */
class AdminMetrics
{
    public function __construct(
        protected string $timezone,
    ) {}

    /**
     * Volume and reach, at a glance.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return [
            'messages' => WhatsappMessage::query()->count(),
            'today' => WhatsappMessage::query()->since($this->startOfDay())->count(),
            'week' => WhatsappMessage::query()->since($this->startOfDay(6))->count(),
            'people' => (int) WhatsappMessage::query()->distinct()->count('phone'),
            'people_week' => (int) WhatsappMessage::query()
                ->since($this->startOfDay(6))
                ->distinct()
                ->count('phone'),
            'new_people_week' => $this->newPeopleSince($this->startOfDay(6)),
            'returning_people' => $this->returningPeople(),
            'failed_week' => WhatsappMessage::query()->since($this->startOfDay(6))->failed()->count(),
            'pending' => WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_PENDING)->count(),
        ];
    }

    /**
     * Messages per calendar day, oldest first, with the empty days filled in —
     * a gap in a traffic chart has to read as zero, not as missing.
     *
     * @return Collection<int, array{label: string, date: string, count: int}>
     */
    public function perDay(int $days = 14): Collection
    {
        $counts = WhatsappMessage::query()
            ->since($this->startOfDay($days - 1))
            ->get(['created_at'])
            ->countBy(fn (WhatsappMessage $m) => $m->created_at->setTimezone($this->timezone)->toDateString());

        return collect(range($days - 1, 0))->map(function (int $ago) use ($counts) {
            $day = Carbon::now($this->timezone)->subDays($ago);

            return [
                'label' => $day->format('d/m'),
                'date' => $day->toDateString(),
                'count' => (int) $counts->get($day->toDateString(), 0),
            ];
        });
    }

    /**
     * When people write, by local hour. Tells us when a deploy is cheapest and
     * whether the alert rounds land while anyone is awake.
     *
     * @return Collection<int, array{hour: int, label: string, count: int}>
     */
    public function perHour(int $days = 30): Collection
    {
        $counts = WhatsappMessage::query()
            ->since($this->startOfDay($days - 1))
            ->get(['created_at'])
            ->countBy(fn (WhatsappMessage $m) => (int) $m->created_at->setTimezone($this->timezone)->hour);

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
            'count' => (int) $counts->get($hour, 0),
        ]);
    }

    /**
     * Which aerodromes people ask about. Joined to the registry rather than
     * carrying a name on every message row, so a renamed aerodrome renames its
     * history too.
     *
     * @return Collection<int, array{anac_code: string, name: string, total: int}>
     */
    public function topAirports(int $limit = 12, ?int $days = null): Collection
    {
        return DB::table('whatsapp_messages')
            ->whereNotNull('whatsapp_messages.anac_code')
            ->when(
                $days !== null,
                fn ($query) => $query->where('whatsapp_messages.created_at', '>=', $this->startOfDay($days - 1)),
            )
            ->leftJoin('airports', 'airports.anac_code', '=', 'whatsapp_messages.anac_code')
            ->groupBy('whatsapp_messages.anac_code', 'airports.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get(['whatsapp_messages.anac_code', 'airports.name', DB::raw('count(*) as total')])
            ->map(fn ($row) => [
                'anac_code' => (string) $row->anac_code,
                'name' => (string) ($row->name ?? 'Desconocido'),
                'total' => (int) $row->total,
            ]);
    }

    /**
     * What people ask for, by the topic the bot routed the message to.
     *
     * @return Collection<int, array{topic: string, label: string, total: int}>
     */
    public function topics(): Collection
    {
        $labels = WhatsappMessage::TOPIC_LABELS;

        return DB::table('whatsapp_messages')
            ->whereNotNull('topic')
            ->groupBy('topic')
            ->orderByDesc('total')
            ->get(['topic', DB::raw('count(*) as total')])
            ->map(fn ($row) => [
                'topic' => (string) $row->topic,
                'label' => $labels[(string) $row->topic] ?? (string) $row->topic,
                'total' => (int) $row->total,
            ]);
    }

    /**
     * Messages the bot answered without having identified an aerodrome: either
     * the user was greeting it, or the matcher missed. The samples are what
     * make the difference visible.
     *
     * @return array{total: int, rate: float, samples: Collection<int, WhatsappMessage>}
     */
    public function unmatched(int $days = 30, int $samples = 8): array
    {
        $processed = WhatsappMessage::query()
            ->since($this->startOfDay($days - 1))
            ->where('status', '!=', WhatsappMessage::STATUS_PENDING)
            ->count();

        $query = WhatsappMessage::query()
            ->since($this->startOfDay($days - 1))
            ->where('status', '!=', WhatsappMessage::STATUS_PENDING)
            ->whereNull('anac_code')
            ->where(fn ($q) => $q->whereNull('topic')->orWhere('topic', '!=', 'list'));

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'rate' => $processed === 0 ? 0.0 : round($total * 100 / $processed, 1),
            'samples' => $query->latest('id')->limit($samples)->get(),
        ];
    }

    /**
     * How long the user waits, from the job picking the message up to the last
     * body handed to Twilio. The 95th percentile matters more than the average:
     * the slow answers are the AI-decoded ones, and those are the interesting
     * ones to watch.
     *
     * @return array{count: int, avg: int|null, p50: int|null, p95: int|null, max: int|null}
     */
    public function latency(int $days = 7): array
    {
        /** @var array<int, int> $durations */
        $durations = WhatsappMessage::query()
            ->since($this->startOfDay($days - 1))
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->map(fn ($ms) => (int) $ms)
            ->all();

        if ($durations === []) {
            return ['count' => 0, 'avg' => null, 'p50' => null, 'p95' => null, 'max' => null];
        }

        return [
            'count' => count($durations),
            'avg' => (int) round(array_sum($durations) / count($durations)),
            'p50' => $this->percentile($durations, 0.5),
            'p95' => $this->percentile($durations, 0.95),
            'max' => (int) end($durations),
        ];
    }

    /**
     * Who writes most, and when they last did.
     *
     * @return Collection<int, array{phone: string, name: string, total: int, last_at: Carbon}>
     */
    public function topUsers(int $limit = 10): Collection
    {
        return DB::table('whatsapp_messages')
            ->groupBy('phone')
            ->orderByDesc('total')
            ->limit($limit)
            ->get([
                'phone',
                DB::raw('count(*) as total'),
                DB::raw('max(profile_name) as name'),
                DB::raw('max(created_at) as last_at'),
            ])
            ->map(fn ($row) => [
                'phone' => (string) $row->phone,
                'name' => (string) ($row->name ?? ''),
                'total' => (int) $row->total,
                'last_at' => Carbon::parse((string) $row->last_at),
            ]);
    }

    /**
     * The standing METAR watches, which are the only thing the bot keeps
     * between conversations.
     *
     * @return array{active: int, people: int, expiring_today: int, per_airport: Collection<int, array{anac_code: string, name: string, total: int}>}
     */
    public function subscriptions(): array
    {
        $perAirport = DB::table('metar_subscriptions')
            ->where('expires_at', '>', now())
            ->leftJoin('airports', 'airports.anac_code', '=', 'metar_subscriptions.anac_code')
            ->groupBy('metar_subscriptions.anac_code', 'airports.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get(['metar_subscriptions.anac_code', 'airports.name', DB::raw('count(*) as total')])
            ->map(fn ($row) => [
                'anac_code' => (string) $row->anac_code,
                'name' => (string) ($row->name ?? 'Desconocido'),
                'total' => (int) $row->total,
            ]);

        return [
            'active' => MetarSubscription::query()->active()->count(),
            'people' => (int) MetarSubscription::query()->active()->distinct()->count('phone'),
            'expiring_today' => MetarSubscription::query()
                ->active()
                ->where('expires_at', '<=', Carbon::now($this->timezone)->endOfDay()->utc())
                ->count(),
            'per_airport' => $perAirport,
        ];
    }

    /**
     * Start of a local day, expressed as the UTC instant it began at.
     */
    protected function startOfDay(int $daysAgo = 0): Carbon
    {
        return Carbon::now($this->timezone)->startOfDay()->subDays($daysAgo)->utc();
    }

    /**
     * Phones whose very first message falls inside the window.
     */
    protected function newPeopleSince(Carbon $moment): int
    {
        $firsts = WhatsappMessage::query()
            ->groupBy('phone')
            ->select('phone', DB::raw('min(created_at) as first_at'));

        return DB::query()
            ->fromSub($firsts, 'firsts')
            ->where('first_at', '>=', $moment->toDateTimeString())
            ->count();
    }

    protected function returningPeople(): int
    {
        $counts = WhatsappMessage::query()
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->select('phone');

        return DB::query()->fromSub($counts, 'repeat')->count();
    }

    /**
     * @param  array<int, int>  $sorted  Ascending.
     */
    protected function percentile(array $sorted, float $q): int
    {
        $index = (int) ceil($q * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
