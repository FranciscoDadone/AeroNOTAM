<?php

namespace App\Jobs\Concerns;

use App\Contracts\WhatsappSender;
use App\DataObjects\WhatsappReply;

/**
 * Sends every body a WhatsappReply carries, each with whichever button
 * outbound() paired it with.
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

            $sender->sendWithButtons($to, $body, $button->buttons);
        }
    }
}
