<?php

use App\DataObjects\AipDocument;
use App\Services\AipDocumentSummarizerService;
use Illuminate\Support\Facades\Cache;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    Cache::flush();
});

function summarizerFor(Parser $parser): AipDocumentSummarizerService
{
    return new AipDocumentSummarizerService($parser);
}

function chart(string $title = 'Carta de aproximación por instrumentos - VOR RWY 19'): AipDocument
{
    return new AipDocument('SAZR', 'AD-2.C', $title, 'https://ais.anac.gob.ar/descarga/aip-test-osa-vor');
}

/**
 * The key a summary is cached under. Read off the service rather than written
 * out here: the version in it is bumped whenever the prompt changes, and a test
 * that spelled it out would fail on every bump for no reason.
 */
function summaryCacheKey(AipDocument $document): string
{
    $version = (new ReflectionClassConstant(AipDocumentSummarizerService::class, 'PROMPT_VERSION'))->getValue();

    return 'aip:doc-summary:es:v'.$version.':'.sha1($document->identity());
}

/**
 * A parser that hands back whatever text a test wants out of the PDF, or blows
 * up the way pdfparser does on a file it cannot read. The real one is exercised
 * end to end by AipAdDetailsTest; what matters here is what this service does
 * around it.
 */
function parserReturning(?string $text): Parser
{
    return new class($text) extends Parser
    {
        public function __construct(protected ?string $text) {}

        public function parseContent(string $content): Document
        {
            if ($this->text === null) {
                throw new RuntimeException('PDF ilegible.');
            }

            return new class($this->text) extends Document
            {
                public function __construct(protected string $text) {}

                public function getText(?int $pageLimit = null): string
                {
                    return $this->text;
                }
            };
        }
    };
}

/**
 * No key configured is a deployment state, not a failure — the same one
 * AiNotamDecoderService answers null to. The chart still goes out, with its
 * title alone above it.
 */
it('says nothing without an OpenRouter key', function () {
    withoutAi();

    expect(summarizerFor(parserReturning(str_repeat('VOR RWY 19 MDA 1200 ', 20)))->summarize(chart(), 'bytes'))
        ->toBeNull();
});

/**
 * A chart that was scanned rather than drawn carries no embedded text at all,
 * and a handful of stray characters is not something to ask a model to
 * describe — that is how an approach procedure gets invented.
 */
it('says nothing when the PDF carries too little text to describe', function (string $text) {
    config(['ai.providers.openrouter.key' => 'test-key']);

    expect(summarizerFor(parserReturning($text))->summarize(chart(), 'bytes'))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ["   \n \t "],
    'a few stray glyphs' => ['SAZR 19'],
]);

/**
 * An unreadable PDF must never cost the reader the document: the caption is
 * worth having, the chart is worth sending, and only one of those depends on
 * this.
 */
it('says nothing rather than throwing when the PDF cannot be parsed', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    expect(summarizerFor(parserReturning(null))->summarize(chart(), 'bytes'))->toBeNull();
});

/**
 * The download link embeds a hash that changes with every AIRAC amendment, so
 * a cache keyed on it would re-summarise every chart in the country each cycle
 * even where the chart itself is untouched. Same document, new URL, same key.
 */
it('names a document by what does not change with the AIRAC cycle', function () {
    $document = chart();
    $reissued = new AipDocument(
        $document->icaoCode,
        $document->code,
        $document->title,
        'https://ais.anac.gob.ar/descarga/aip-test-osa-vor-02-26',
    );

    expect($document->identity())->toBe($reissued->identity())
        ->and($document->identity())->not->toContain('descarga');
});

/**
 * Once a summary is cached, nothing else on this path runs — not the model,
 * and not the PDF parsing that feeds it.
 */
it('serves a cached summary without reading the PDF again', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    $document = chart();

    Cache::put(summaryCacheKey($document), 'Aproximación VOR a la pista 19.', now()->addDay());

    // The parser would throw if it were reached at all.
    expect(summarizerFor(parserReturning(null))->summarize($document, 'bytes'))
        ->toBe('Aproximación VOR a la pista 19.');
});
