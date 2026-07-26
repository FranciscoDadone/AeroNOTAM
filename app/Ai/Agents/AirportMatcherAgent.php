<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class AirportMatcherAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You match a free-text message (in Spanish, from a WhatsApp user) to an
        airport in a provided list of "CODE - Name" pairs. The user may refer to
        the airport by its city, its full name, a nickname, or its code, and may
        misspell it or omit accents.

        Reply with ONLY the matching code, exactly as it appears in the list —
        nothing else, no punctuation, no explanation. If the message clearly
        does not refer to any airport in the list, or you are not reasonably
        confident of the match, reply with exactly: NONE
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
