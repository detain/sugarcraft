<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Mutable SGR state carried through a parsing run.
 *
 * @internal
 */
final class SgrState
{
    public function __construct(
        public ?string $fg = null,
        public ?string $bg = null,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
    ) {}
}