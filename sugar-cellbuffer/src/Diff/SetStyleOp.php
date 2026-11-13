<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

use SugarCraft\CellBuffer\Style;

/**
 * Transition the active SGR (Select Graphic Rendition) style
 * before the next cell write.
 *
 * When $style is null, emits SGR reset (\x1b[0m).
 * The emitted SGR is a full reset plus restatement of the pen
 * (SgrEmitter policy), not a minimal delta; the encoder skips the op
 * entirely when the active style has not changed.
 *
 * @readonly
 */
final class SetStyleOp extends DiffOp
{
    public function __construct(
        public readonly ?Style $style,
    ) {}
}
