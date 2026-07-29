<?php

namespace App\DataObjects;

/**
 * The follow-up offered after answering about an aerodrome: "want anything
 * else about it?", with a button for each of the other topics.
 *
 * A message of its own rather than more buttons on the answer, because a
 * WhatsApp message renders buttons from exactly one content template — the
 * METAR already spends its own on the watch offer, and the two cannot be
 * merged onto one message. The type pairs body and button so neither can
 * exist without the other.
 */
final readonly class ReplyMenu
{
    public function __construct(
        public string $body,
        public ReplyButton $button,
    ) {}
}
