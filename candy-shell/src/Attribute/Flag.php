<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Attribute;

use Attribute;

/**
 * Declarative CLI option for a command.
 *
 * Apply to a command class to declare options that the
 * {@see \SugarCraft\Shell\Discovery\CommandScanner} will
 * translate into Symfony Console input options.
 *
 * Usage:
 *   #[Flag(name: 'verbose', short: 'v')]
 *   #[Flag(name: 'format', short: 'f', enum: FormatType::class)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Flag
{
    public function __construct(
        public readonly string $name,
        public readonly string $short = '',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly bool $isFlag = false,
        public readonly ?string $enum = null,
        public readonly mixed $default = null,
    ) {
    }
}
