<?php

namespace App\DataObjects;

/**
 * A pin on the map, sent as WhatsApp's own location message rather than as a
 * link: it opens in whatever maps app the reader already uses, and it can be
 * forwarded or navigated to without anything having to be copied out of a
 * message.
 *
 * The name and the address are what WhatsApp draws under the pin, and they are
 * the only thing that says which aerodrome it is once the message has been
 * forwarded away from the conversation it was asked for in.
 */
final readonly class ReplyLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $name,
        public string $address = '',
    ) {}
}
