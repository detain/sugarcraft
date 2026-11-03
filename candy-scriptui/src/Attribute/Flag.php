<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Attribute;

use Attribute;

/**
 * Declarative CLI option for a command.
 *
 * Apply to a command CLASS to declare options that the
 * {@see \SugarCraft\ScriptUi\Discovery\CommandScanner} will
 * translate into Symfony Console input options. The scanner reads
 * class attributes only — a `#[Flag]` on a method registers nothing.
 *
 * Usage:
 *   #[Flag(name: 'verbose', short: 'v')]
 *   #[Flag(name: 'format', short: 'f', enum: FormatType::class)]
 *
 * The `enum:` part is ENFORCED at runtime for scans into a
 * {@see \SugarCraft\ScriptUi\Application}: the scanner registers the
 * constraint and the application refuses invalid values with exit code 2
 * before the command executes. Commands run outside that application
 * (e.g. through CommandTester) should still call
 * {@see ValueEnum::validate()} in their own execute() — that shipped
 * call-it-yourself validator remains available.
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
