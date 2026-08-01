<?php

namespace App\Jobs\Concerns;

use App\Contracts\WhatsappSender;
use App\DataObjects\WhatsappReply;

/**
 * Sends every attachment a WhatsappReply carries, then every body, each with
 * whichever button outbound() paired it with.
 */
trait DeliversWhatsappReply
{
    protected function deliver(WhatsappSender $sender, string $to, WhatsappReply $reply): void
    {
        foreach ($reply->documents as $document) {
            $sender->sendDocument($to, $document->url, $document->caption, $document->filename);
        }

        foreach ($reply->outbound() as [$body, $button]) {
            if ($button === null) {
                $sender->send($to, $body);

                continue;
            }

            if ($button->listLabel !== null) {
                $sender->sendWithList($to, $body, $button->listLabel, $button->buttons);

                continue;
            }

            $sender->sendWithButtons($to, $body, $button->buttons);
        }
    }
}
