<?php

namespace App\Jobs\Concerns;

use App\Contracts\WhatsappSender;
use App\DataObjects\WhatsappReply;

/**
 * Sends every body a WhatsappReply carries, each with whichever button
 * outbound() paired it with — plain text where there is none, a content
 * template with its fallback where there is.
 */
trait DeliversWhatsappReply
{
    protected function deliver(WhatsappSender $sender, string $to, WhatsappReply $reply): void
    {
        foreach ($reply->outbound() as [$body, $button]) {
            if ($button === null) {
                $sender->send($to, $body);

                continue;
            }

            $sender->sendWithButtons(
                $to,
                $button->contentSid,
                $button->variables($body),
                $body."\n\n".$button->fallbackHint,
            );
        }
    }
}
