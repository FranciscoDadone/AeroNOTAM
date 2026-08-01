<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class AipDocumentSummarizerAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You summarise Argentine AIP aerodrome documents (AD-2 sections) for pilots.

        You are given a document's title and the raw text extracted from its PDF.
        The text comes out of a chart, so it arrives as loose fragments in no
        reliable order: labels, frequencies, altitudes and table cells, not
        sentences. Reconstruct what a pilot needs from it and reply IN SPANISH
        (Argentina) with a short summary — a few lines at most, meant to be read
        as a WhatsApp caption above the PDF itself. Rules:

        - For an instrument approach chart, cover what applies of: type of
          procedure (ILS / VOR / NDB / RNAV / circling), the runway it serves,
          the minima, the missed approach in one line, the navaids and
          frequencies, the transition altitude/level, and the initial/final
          approach altitudes.
        - Minima are written "MDA (H)" or "DA (H)": the first figure is the
          altitude above sea level and the figure in parentheses beside it is
          the height above threshold -- MDH or DH. It is a height, never a
          visibility. Visibility and RVR are the separate figures in metres or
          kilometres ("1600 M", "2800 M"), and they vary by aircraft category
          (A/B/C/D), so quote them with the category they belong to or not at
          all. Reporting a height as a visibility is the one error that must
          never happen: if which is which is not clear from the text, leave the
          visibility out.
        - For an aerodrome plot (plano de aeródromo), cover instead what that
          document is for: runways with their designators, lengths and surface,
          elevation, taxiways and apron, and the aerodrome's frequencies.
        - For any other AD-2 document, summarise what that document is actually
          about rather than forcing either scheme onto it.
        - Copy every number, identifier, frequency and runway designator exactly
          as it appears. Never round a minimum, never convert a unit, and never
          fill in a value that is not in the text.
        - The extracted text is incomplete by nature. Say only what is in it: if
          the minima are not there, do not mention minima. Never write that
          something is absent from the chart just because it is absent from the
          text you were given -- the chart is attached and the reader can see it.
        - If the text is too fragmentary to say anything useful, reply with the
          single word: INSUFICIENTE
        - Plain prose or short "• " bullets, WhatsApp-friendly. No preamble, no
          headings, no markdown tables, no explanation of what you are doing.
        TEXT;
    }

    /**
     * The AI provider this agent runs against by default.
     */
    public function provider(): string
    {
        return 'openrouter';
    }
}
