<?php

namespace App\Support;

use App\DataObjects\AipDocument;
use Illuminate\Support\Str;

/**
 * What each AIP document is, read off its title — and what kind of document a
 * message is asking for, read off the message.
 *
 * Separate from AipService for the same reason AirportResolver is separate from
 * the services that fetch: one of them knows how to get the AIP's listing, this
 * one knows what its rows mean. The two questions change for different reasons.
 *
 * Classification is by title and not by AD-2 section number on purpose. The
 * numbering is the AIP's own and nothing published says which section a country
 * files its approach charts under; the titles, on the other hand, say
 * "Aproximación por instrumentos" in words a reader would recognise. A title is
 * also what the reader is shown, so a document that matches is a document that
 * looks like it should have.
 */
class AipDocumentClassifier
{
    public const APPROACH = 'approach';

    public const AERODROME = 'aerodrome';

    /**
     * The words that name each kind, in the AIP's titles and in the messages
     * that ask for one — the same list serves both, because someone asking for
     * "la carta de aproximación" is naming the document nearly as the AIP does.
     *
     * Written the way the rest of the bot's keyword lists are: lower case,
     * accents stripped. Matched at a word boundary rather than as a bare
     * substring, which is what lets "IAC" be on the list at all — as a
     * substring it lives inside "aviación".
     *
     * @var array<string, array<int, string>>
     */
    protected const KIND_KEYWORDS = [
        self::APPROACH => ['aproximacion', 'aproximaciones', 'iac', 'approach'],
        self::AERODROME => ['plano de aerodromo', 'plano del aerodromo', 'plano de ad', 'adc'],
    ];

    /**
     * Which kind of document a message is asking for, or null when it asks for
     * documents without saying which — "los documentos de la AIP de Tandil",
     * which is answered with the list of everything rather than by guessing.
     *
     * The aerodrome plot is checked first: "plano de aeródromo" names a
     * document of its own, and a message carrying both words means the plot.
     */
    public function requestedKind(string $message): ?string
    {
        $normalized = self::normalize($message);

        foreach ([self::AERODROME, self::APPROACH] as $kind) {
            if ($this->mentions($normalized, self::KIND_KEYWORDS[$kind])) {
                return $kind;
            }
        }

        return null;
    }

    public function isOfKind(AipDocument $document, string $kind): bool
    {
        return $this->mentions(self::normalize($document->title), self::KIND_KEYWORDS[$kind] ?? []);
    }

    /**
     * A title or a message in the form the keyword lists are written in.
     *
     * The entity decoding is belt and braces — AipService already decodes what
     * it parses — but this also classifies text that never went through it.
     */
    public static function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function mentions(string $normalized, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
