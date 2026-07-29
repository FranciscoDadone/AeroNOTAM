<?php

namespace App\DataObjects;

/**
 * What the bot made of a message while answering it: the topic it routed to and
 * the aerodrome it resolved.
 *
 * Filled in as the answer is built rather than returned with it, because the
 * reply itself has no use for any of this — it exists so the admin panel can
 * report on what the bot understood, including when it understood nothing.
 */
final class ReplyContext
{
    public function __construct(
        public ?string $topic = null,
        public ?string $anacCode = null,
    ) {}
}
