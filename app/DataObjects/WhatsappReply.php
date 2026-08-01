<?php

namespace App\DataObjects;

/**
 * Everything the bot wants to say in answer to one message: the files to
 * attach, the bodies to send, in order, optionally a button to offer
 * underneath, and optionally a menu offering the other topics for the same
 * aerodrome as a message of its own.
 *
 * The button belongs to the reply rather than to each body because it is only
 * ever attached to the last one. A long answer arrives as a numbered run of
 * messages, and repeating the same button on every part of it would read as
 * several offers rather than one.
 *
 * The documents lead: an attachment carries its own caption, so it says what it
 * is on its own, and whatever text follows is about the whole set of them
 * rather than an introduction to files that have not arrived yet.
 */
final readonly class WhatsappReply
{
    /**
     * @param  array<int, string>  $messages
     * @param  array<int, ReplyDocument>  $documents
     */
    public function __construct(
        public array $messages,
        public ?ReplyButton $button = null,
        public ?ReplyMenu $menu = null,
        public array $documents = [],
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

    /**
     * @param  array<int, ReplyDocument>  $documents
     * @param  array<int, string>  $messages  What to say after the attachments, if anything.
     */
    public static function ofDocuments(array $documents, array $messages = [], ?ReplyButton $button = null): self
    {
        return new self(array_values($messages), $button, null, array_values($documents));
    }

    public function withMenu(?ReplyMenu $menu): self
    {
        return new self($this->messages, $this->button, $menu, $this->documents);
    }

    /**
     * The bodies to send, each paired with the button that rides on it — the
     * last answer body with $button, the menu (if any) with its own, everything
     * else with none. The one place this pairing is worked out, so producing a
     * reply never has to restate it.
     *
     * @return array<int, array{0: string, 1: ?ReplyButton}>
     */
    public function outbound(): array
    {
        $last = count($this->messages) - 1;

        $pairs = [];

        foreach ($this->messages as $i => $message) {
            $pairs[] = [$message, $i === $last ? $this->button : null];
        }

        if ($this->menu !== null) {
            $pairs[] = [$this->menu->body, $this->menu->button];
        }

        return $pairs;
    }
}
