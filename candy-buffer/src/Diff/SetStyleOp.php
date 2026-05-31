<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Style;

/**
 * Transition the active SGR (Select Graphic Rendition) style
 * before the next cell write.
 *
 * When $style is null, emits SGR reset (\x1b[0m).
 * Otherwise emits the minimum SGR sequence to transition from
 * the previously active style to $style.
 *
 * @readonly
 */
final class SetStyleOp extends DiffOp
{
    public function __construct(
        public readonly ?Style $style,
    ) {}
}
