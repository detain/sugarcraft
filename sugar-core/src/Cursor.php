<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Cursor description carried on a {@see View}. A null cursor on the
 * View hides the cursor; setting a Cursor positions and shapes it.
 *
 * `row` / `col` are 1-based, matching every other CSI cursor
 * coordinate. `null` for either leaves that axis at wherever the
 * renderer left it. `shape` is the DECSCUSR style; `blink` toggles
 * between the steady (default) and blinking variants. `color` is
 * optional — when set, the runtime emits OSC 12 to recolour the
 * cursor.
 */
final class Cursor
{
    public function __construct(
        public readonly ?int $row = null,
        public readonly ?int $col = null,
        public readonly CursorShape $shape = CursorShape::Block,
        public readonly bool $blink = false,
        public readonly ?Util\Color $color = null,
    ) {
    }
}
