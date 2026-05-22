<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * Set <key> <value> directive — configures terminal settings.
 * Allowed keys: Theme, FontSize, Width, Height, TypingSpeed, FontFamily, Padding, Margin.
 */
final readonly class SetDirective implements Directive
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
