<?php

namespace App\Contracts;

interface WhatsappSender
{
    /**
     * Deliver a single WhatsApp message body to a recipient.
     *
     * @param  string  $to  Recipient in the provider's address form, e.g. "whatsapp:+5491122334455".
     */
    public function send(string $to, string $body): void;

    /**
     * Show the "typing…" dots to whoever sent us $inboundMessageId, so the
     * chat doesn't look dead while we build the reply.
     *
     * WhatsApp keeps the indicator up for at most 25 seconds, or until our
     * next message arrives — whichever comes first.
     *
     * @param  string  $inboundMessageId  Provider id of the message being answered (Twilio: an SM…/MM… SID).
     */
    public function indicateTyping(string $inboundMessageId): void;
}
