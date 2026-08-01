<?php

namespace App\Models;

use App\DataObjects\ReplyContext;
use Database\Factories\WhatsappMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One inbound WhatsApp message and the bot's answer to it.
 *
 * @property int $id
 * @property string $phone
 * @property string|null $profile_name
 * @property string|null $message_sid
 * @property string $body
 * @property string|null $button_payload
 * @property string|null $topic
 * @property string|null $anac_code
 * @property string|null $icao_code
 * @property string $status
 * @property array<int, string>|null $reply
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Airport|null $airport
 */
class WhatsappMessage extends Model
{
    /** @use HasFactory<WhatsappMessageFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_FAILED = 'failed';

    /**
     * The topics WhatsappBotService routes to, as they read in the panel.
     */
    public const TOPIC_LABELS = [
        'notam' => 'NOTAM',
        'metar' => 'METAR',
        'taf' => 'TAF',
        'crepusculo' => 'Crepúsculo',
        'subscribe' => 'Alta de alerta',
        'unsubscribe' => 'Baja de alerta',
        'list' => 'Alertas activas',
    ];

    protected $fillable = [
        'phone',
        'profile_name',
        'message_sid',
        'body',
        'button_payload',
        'topic',
        'anac_code',
        'icao_code',
        'status',
        'reply',
        'error',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'reply' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Airport, $this>
     */
    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'anac_code', 'anac_code');
    }

    /**
     * Store what the bot understood, before it is known whether the answer
     * reached the user: even a message that fails to send tells us how it was
     * interpreted.
     */
    public function recordUnderstanding(ReplyContext $context): void
    {
        $this->update([
            'topic' => $context->topic,
            'anac_code' => $context->anacCode,
            'icao_code' => $context->anacCode === null
                ? null
                : Airport::query()->where('anac_code', $context->anacCode)->value('icao_code'),
        ]);
    }

    /**
     * @param  array<int, string>  $messages
     * @param  float  $startedAt  microtime(true) from when the job picked the message up.
     */
    public function recordReply(array $messages, float $startedAt): void
    {
        $this->update([
            'reply' => array_values($messages),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),

            // A reply did go out, but if the answer had to be replaced by an
            // apology the row stays failed — that is what makes it findable.
            'status' => $this->status === self::STATUS_FAILED
                ? self::STATUS_FAILED
                : self::STATUS_ANSWERED,
        ]);
    }

    public function recordFailure(?string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    /**
     * The number as a person would read it, without the channel prefix.
     */
    public function phoneNumber(): string
    {
        return Str::after($this->phone, 'whatsapp:');
    }

    public function senderName(): string
    {
        return $this->profile_name ?: $this->phoneNumber();
    }

    public function replyText(): string
    {
        return implode("\n\n", $this->reply ?? []);
    }

    public function topicLabel(): string
    {
        return self::TOPIC_LABELS[$this->topic] ?? ($this->topic ?? '—');
    }

    /**
     * The moment as the panel shows it. Stored UTC like everything else the
     * bot handles; only the panel reads in local time.
     */
    public function localTime(): Carbon
    {
        return $this->created_at->setTimezone((string) config('app.display_timezone'));
    }

    /**
     * Nothing matched — neither the deterministic rules nor the model. Only
     * meaningful once the message has actually been processed.
     */
    public function isUnmatched(): bool
    {
        return $this->status !== self::STATUS_PENDING
            && $this->anac_code === null
            && $this->topic !== 'list';
    }

    /**
     * @param  Builder<WhatsappMessage>  $query
     * @return Builder<WhatsappMessage>
     */
    public function scopeSince(Builder $query, Carbon $moment): Builder
    {
        // Qualified because the metrics queries join the aerodrome registry,
        // which carries a created_at of its own.
        return $query->where($query->qualifyColumn('created_at'), '>=', $moment);
    }

    /**
     * @param  Builder<WhatsappMessage>  $query
     * @return Builder<WhatsappMessage>
     */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ANSWERED);
    }

    /**
     * @param  Builder<WhatsappMessage>  $query
     * @return Builder<WhatsappMessage>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
