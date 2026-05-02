<?php

declare(strict_types=1);

namespace CandyCore\Bits\Key;

/**
 * Human-readable label + description for a {@see Binding}, used by the
 * Help component.
 */
final class Help
{
    public function __construct(
        public readonly string $key  = '',
        public readonly string $desc = '',
    ) {}
}
