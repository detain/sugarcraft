<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Color;

/**
 * A message sent between participants in a sequence diagram.
 */
final class SequenceMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly bool $isReply = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a reply message.
     */
    public static function reply(string $id, string $from, string $to, string $label, ?Color $color = null): self
    {
        return new self($id, $from, $to, $label, true, $color);
    }
}
