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
     * WhatsApp will not render a button from free text: it has to come from a
     * content template registered with the provider ahead of time, named here by
     * $contentSid and filled in with $variables. When that SID is blank — the
     * account has none registered yet — $fallback is sent as an ordinary message
     * instead, so the feature degrades to the written command rather than
     * failing.
     *
     * @param  array<int, string>  $variables  Template substitutions, keyed 1, 2, … — json_encode
     *                                         renders them as the "1"/"2" keys Twilio expects.
     */
    public function sendWithButtons(string $to, string $contentSid, array $variables, string $fallback): void;

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
