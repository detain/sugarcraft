<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Attribute;

use Attribute;

/**
 * Registers an alias for a CLI command.
 *
 * Usage:
 *   #[Alias('co')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Alias
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
