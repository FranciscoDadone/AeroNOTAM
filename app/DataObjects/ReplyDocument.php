<?php

namespace App\DataObjects;

/**
 * A file the bot sends as an attachment rather than as text — today always a
 * PDF out of the AIP.
 *
 * The URL is the AIP's own: WhatsApp fetches it and forwards the file, so
 * nothing is stored here and no copy of a chart can go stale against the
 * amendment that replaced it. The caption is what a reader sees above the
 * attachment, and is the whole reason a chart arriving on a phone is useful at
 * all — the title of the document, and what it says, before they open it.
 */
final readonly class ReplyDocument
{
    public function __construct(
        public string $url,
        public string $caption,
        public string $filename,
    ) {}
}
