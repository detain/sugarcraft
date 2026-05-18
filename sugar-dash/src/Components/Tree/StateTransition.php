<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A transition between states in a state machine diagram.
 */
final class StateTransition
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly TransitionType $type = TransitionType::Normal,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a guard transition (conditional).
     */
    public static function guard(string $id, string $from, string $to, string $label): self
    {
        return new self($id, $from, $to, $label, TransitionType::Guard);
    }
}
