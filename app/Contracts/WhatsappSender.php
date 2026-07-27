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
}
