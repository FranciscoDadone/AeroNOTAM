<?php

namespace App\Services;

use App\Ai\Agents\NotamDecoderAgent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AiNotamDecoderService
{
    /**
     * Bump this when the prompt or glossary logic changes meaningfully, so
     * previously cached (lower-quality) decodes stop being served forever.
     */
    protected const PROMPT_VERSION = 4;

    public function __construct(protected NotamAbbreviationDecoder $decoder) {}

    /**
     * Decode raw NOTAM text into a natural Spanish sentence via AI.
     *
     * Primarily fed the English source (never relying on text_es alone,
     * since ANAC's Spanish field is frequently truncated or missing
     * entirely) — but text_es, when present, is passed along as a wording
     * hint, since ANAC's own translation occasionally nails a term (e.g.
     * "GROVE" -> "arboleda") that isn't in any standard abbreviation list.
     *
     * The decode is cached only until the NOTAM's own expiry ($validUntil,
     * "Y-m-d H:i:s" UTC, as ANAC gives it) — once the NOTAM itself is no
     * longer active there's no reason to keep serving its cached wording.
     * A null $validUntil means a permanent NOTAM, cached forever.
     */
    public function decode(string $textEn, ?string $textEs = null, ?string $validUntil = null): ?string
    {
        $textEn = $this->normalizeContactInfo(trim($textEn));

        if ($textEn === '') {
            return null;
        }

        // No key configured is a deployment state, not an error: NotamEnricher
        // falls back to the offline dictionary decoder. Only genuine provider
        // failures (thrown from the agent call below) propagate as exceptions.
        if (blank(config('ai.providers.openrouter.key'))) {
            return null;
        }

        $cacheKey = 'notam:ai-decode:es:v'.self::PROMPT_VERSION.':'.sha1($textEn.'|'.$textEs);

        $callback = function () use ($textEn, $textEs) {
            $response = NotamDecoderAgent::make()->prompt(
                $this->buildPrompt($textEn, $textEs)
            );

            $decoded = trim((string) $response);

            return $decoded === '' ? null : $decoded;
        };

        $expiresAt = $this->cacheExpirationFor($validUntil);

        return $expiresAt === null
            ? Cache::remember($cacheKey, now()->addHour(), $callback)
            : Cache::remember($cacheKey, $expiresAt, $callback);
    }

    protected function cacheExpirationFor(?string $validUntil): ?Carbon
    {
        if ($validUntil === null || trim($validUntil) === '') {
            return null;
        }

        try {
            $expiresAt = Carbon::createFromFormat('Y-m-d H:i:s', $validUntil, 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (! $expiresAt instanceof Carbon) {
            return null;
        }

        // A NOTAM that's already expired by the time we're decoding it
        // shouldn't block caching entirely — give it a short, harmless TTL
        // instead of a zero/negative one.
        return $expiresAt->isFuture() ? $expiresAt : now()->addMinute();
    }

    protected function buildPrompt(string $textEn, ?string $textEs): string
    {
        $prompt = "Decode this NOTAM:\n\n{$textEn}";

        $glossary = $this->decoder->glossaryFor($textEn);

        if ($glossary !== []) {
            $lines = [];

            foreach ($glossary as $code => $meaning) {
                $lines[] = "{$code}: {$meaning}";
            }

            $prompt .= "\n\nKnown abbreviation meanings found in this text — use these exact ".
                "meanings, do not guess or leave them untranslated:\n".implode("\n", $lines);
        }

        $textEs = trim((string) $textEs);

        if ($textEs !== '') {
            $prompt .= "\n\nFor reference only, ANAC's own Spanish version of this NOTAM ".
                '(it may be incomplete or partially untranslated, but can help with wording '.
                "for terms not covered above):\n{$textEs}";
        }

        return $prompt;
    }

    /**
     * ANAC sometimes obfuscates the "@" in an email address as "(A)" (e.g.
     * "AEROPTALAPLATA(A)YPF.COM"), which reads as gibberish to the model and
     * has caused it to hallucinate a fake place name instead of recognizing
     * a contact address. Un-obfuscating it before it ever reaches the prompt
     * is far more reliable than asking the model to spot the pattern itself.
     */
    protected function normalizeContactInfo(string $text): string
    {
        return preg_replace('/(\S)\(A\)(\S)/i', '$1@$2', $text) ?? $text;
    }
}
