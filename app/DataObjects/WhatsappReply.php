<?php

namespace App\DataObjects;

/**
 * Everything the bot wants to say in answer to one message: the bodies to send,
 * in order, and optionally a button to offer underneath.
 *
 * The button belongs to the reply rather than to each body because it is only
 * ever attached to the last one. A long answer arrives as a numbered run of
 * messages, and repeating the same button on every part of it would read as
 * several offers rather than one.
 */
final readonly class WhatsappReply
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public array $messages,
        public ?ReplyButton $button = null,
    ) {}

    public static function of(string ...$messages): self
    {
        return new self(array_values($messages));
    }

    /**
     * @param  array<int, string>  $messages
     */
    public static function ofMany(array $messages, ?ReplyButton $button = null): self
    {
        return new self(array_values($messages), $button);
    }

    public function withButton(?ReplyButton $button): self
    {
        return new self($this->messages, $button);
    }
}
