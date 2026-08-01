<?php

namespace App\Services;

use App\Ai\Agents\AipDocumentSummarizerAgent;
use App\DataObjects\AipDocument;
use Illuminate\Support\Facades\Cache;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The few lines that ride above an AIP PDF sent over WhatsApp: what the
 * document says, for a reader who is about to open it on a phone.
 *
 * Same shape as AiNotamDecoderService, and for the same reason — an approach
 * chart is not a standardised form the way the AD-2.0 record AipAdDetails
 * parses is. Each country, each procedure and each amendment lays one out
 * differently, so a regex over the extracted text would be guessing; a model
 * reading it is not.
 *
 * Everything here degrades to null rather than throwing. The caption is worth
 * having and the PDF is worth sending either way, so no failure on this path —
 * no API key, an unreadable PDF, a provider that is down — may cost the reader
 * the document they asked for.
 */
class AipDocumentSummarizerService
{
    /**
     * Bump this when the prompt changes meaningfully, so previously cached
     * (lower-quality) summaries stop being served for a whole AIRAC cycle.
     */
    protected const PROMPT_VERSION = 2;

    /**
     * How much of the extracted text is worth sending. A chart's text comes out
     * as a few hundred loose fragments; anything past this is the tail of a
     * document that has already said what it has to say, and paying for it on
     * every cold cache is not worth the words it would add.
     */
    protected const MAX_TEXT_LENGTH = 12000;

    /**
     * Below this there is nothing to summarise: a scanned chart with no
     * embedded text extracts to a handful of stray characters, and asking a
     * model to describe those is asking it to invent an approach procedure.
     */
    protected const MIN_TEXT_LENGTH = 120;

    /**
     * What the agent replies when the text it was given says too little. Its
     * own word, so a summary that amounts to nothing never reaches a reader as
     * if it were one.
     */
    protected const INSUFFICIENT = 'INSUFICIENTE';

    public function __construct(protected Parser $parser) {}

    /**
     * A few lines about this document, or null when there are none to be had.
     *
     * Cached against the document's identity rather than its URL: the download
     * link embeds a hash that changes with every AIRAC amendment, so keying on
     * it would re-summarise every chart in the country each cycle even where
     * the chart itself is untouched. A cycle is also the TTL, which is what
     * eventually picks up a chart that really did change.
     */
    public function summarize(AipDocument $document, string $pdfBytes): ?string
    {
        // No key configured is a deployment state, not an error: the PDF goes
        // out with its title alone, which is what happens anyway whenever the
        // text cannot be read.
        if (blank(config('ai.providers.openrouter.key'))) {
            return null;
        }

        $key = 'aip:doc-summary:es:v'.self::PROMPT_VERSION.':'.sha1($document->identity());

        // Read before the PDF is opened rather than inside a remember()
        // callback: parsing a chart is the expensive half of this, and a hit
        // means the bytes never have to be looked at.
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $text = $this->extractText($pdfBytes);

        if ($text === null) {
            return null;
        }

        try {
            $response = AipDocumentSummarizerAgent::make()->prompt(
                $this->buildPrompt($document, $text)
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $summary = trim((string) $response);

        // Nothing useful is not cached: it is as likely to be a bad extraction
        // or a provider having a bad minute as it is to be the document's own
        // fault, and a whole AIRAC cycle is a long time to serve either.
        if ($summary === '' || str_contains($summary, self::INSUFFICIENT)) {
            return null;
        }

        Cache::put($key, $summary, now()->addDays(28));

        return $summary;
    }

    /**
     * The PDF's embedded text, or null when there is not enough of it to be
     * worth a prompt.
     *
     * A chart is a vector drawing with text placed on it, so what comes back is
     * fragments rather than prose — that is expected, and the agent is told so.
     * A chart that was scanned rather than drawn carries no text at all, and
     * that is what the floor below is for.
     */
    protected function extractText(string $pdfBytes): ?string
    {
        try {
            $text = trim($this->parser->parseContent($pdfBytes)->getText());
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // Whitespace is most of what a chart's extracted text is: fragments
        // separated by the gaps between them on the page.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) < self::MIN_TEXT_LENGTH
            ? null
            : mb_substr($text, 0, self::MAX_TEXT_LENGTH);
    }

    protected function buildPrompt(AipDocument $document, string $text): string
    {
        return "Aerodrome: {$document->icaoCode}\n"
            ."Document: {$document->code} — {$document->title}\n\n"
            ."Text extracted from the PDF:\n\n{$text}";
    }
}
