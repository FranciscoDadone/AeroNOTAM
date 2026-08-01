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
     * Deliver a message whose actions are a list rather than buttons.
     *
     * The way past the three-button ceiling: WhatsApp shows a single labelled
     * button that opens a sheet of up to ten rows, each one an id of ours, a
     * title of at most twenty-four characters and an optional description of at
     * most seventy-two. A tapped row comes back the same way a tapped button
     * does.
     *
     * @param  string  $label  The caption on the button that opens the sheet, twenty characters at most.
     * @param  array<int, array{id: string, title: string, description?: string}>  $rows
     */
    public function sendWithList(string $to, string $body, string $label, array $rows): void;

    /**
     * Deliver a document — a PDF, in practice — by public URL.
     *
     * WhatsApp fetches $url itself, so it has to be reachable without our
     * credentials; nothing is uploaded from here. The caption is what the
     * reader sees above the attachment, capped at 1024 characters, and the
     * filename is what the file is called once saved.
     */
    public function sendDocument(string $to, string $url, string $caption, string $filename): void;

    /**
     * Deliver a pin — WhatsApp's own location message, which opens in the
     * reader's maps app.
     *
     * The name and address are drawn under the pin and are optional to
     * WhatsApp; the coordinates are not. Nothing tappable can ride on one, so
     * whatever else is being offered has to travel in a message of its own.
     */
    public function sendLocation(string $to, float $latitude, float $longitude, string $name, string $address): void;

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
