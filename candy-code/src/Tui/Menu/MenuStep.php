<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

/**
 * The result of feeding one key to {@see MenuCursor::key()}: where the cursor
 * ended up, plus — when the key was a choice (Enter/o on a leaf) — the node
 * chosen. A plain move, or a key the cursor does not bind, carries no choice;
 * the caller dispatches on {@see MenuStep::$chosen} and then re-renders from
 * {@see MenuStep::$cursor}.
 */
final class MenuStep
{
    private function __construct(
        public readonly MenuCursor $cursor,
        public readonly ?MenuNode $chosen,
    ) {
    }

    public static function new(MenuCursor $cursor, ?MenuNode $chosen = null): self
    {
        return new self($cursor, $chosen);
    }
}
