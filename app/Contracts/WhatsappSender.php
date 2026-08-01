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
     * Deliver a message that carries tappable buttons underneath it.
     *
     * WhatsApp renders at most three, each one an id we choose — which comes
     * back to us verbatim when it is tapped — and a caption of at most twenty
     * characters. The body they hang under is capped at 1024, shorter than a
     * plain message's.
     *
     * @param  array<int, array{id: string, title: string}>  $buttons
     */
    public function sendWithButtons(string $to, string $body, array $buttons): void;

    /**
     * Show the "typing…" dots to whoever sent us $inboundMessageId, so the
     * chat doesn't look dead while we build the reply.
     *
     * WhatsApp keeps the indicator up for at most 25 seconds, or until our
     * next message arrives — whichever comes first.
     *
     * @param  string  $inboundMessageId  Provider id of the message being answered (Meta: a "wamid.…").
     */
    public function indicateTyping(string $inboundMessageId): void;
}
