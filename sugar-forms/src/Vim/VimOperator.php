<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

/**
 * Vim pending operators that combine with a motion or text object.
 *
 * A bare operator key (c/d/y) puts the handler's consumer into a
 * pending state; the following key(s) select what the operator acts on
 * (`dd`, `ciw`, `ya(`, ...).
 *
 * Mirrors vim operators (:help operator).
 */
enum VimOperator: string
{
    /** Change — delete the target then enter insert mode (vim c). */
    case Change = 'change';

    /** Delete the target (vim d). */
    case Delete = 'delete';

    /** Yank (copy) the target (vim y). */
    case Yank = 'yank';

    /**
     * Map an operator key to its VimOperator, or null when the key
     * is not an operator.
     */
    public static function fromKey(string $key): ?self
    {
        return match ($key) {
            'c'     => self::Change,
            'd'     => self::Delete,
            'y'     => self::Yank,
            default => null,
        };
    }
}
