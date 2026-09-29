<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Color;

/**
 * A participant (object/actor) in a sequence diagram.
 */
final class SequenceParticipant
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create an actor participant.
     */
    public static function actor(string $id, string $label): self
    {
        return new self($id, $label, Color::hex('#CBA6F7'));
    }

    /**
     * Create an object participant.
     */
    public static function object(string $id, string $label): self
    {
        return new self($id, $label, Color::hex('#89B4FA'));
    }
}
