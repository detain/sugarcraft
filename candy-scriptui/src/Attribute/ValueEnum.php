<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Attribute;

use Attribute;

/**
 * Marks a backed enum as a constrained-value set for CLI options.
 *
 * Use with {@see \SugarCraft\ScriptUi\Attribute\Flag} to restrict
 * an option to a fixed set of values:
 *
 *   #[Flag(name: 'format', enum: FormatType::class)]
 *   enum FormatType: string {
 *       case Json  = 'json';
 *       case Yaml  = 'yaml';
 *       case Toml  = 'toml';
 *   }
 *
 * NOTE: scans into a {@see \SugarCraft\ScriptUi\Application} now enforce
 * `enum:` at runtime — the application validates the parsed value in
 * doRunCommand() before execute() runs (review 2026-11-02 M10 close).
 * This class still ships the call-it-yourself check for commands driven
 * outside that application (CommandTester harnesses, custom runners),
 * and {@see self::validate()} is the wording authority the runtime
 * refusal mirrors.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ValueEnum
{
    /**
     * @param array<string, string> $values Map of enum case name => value.
     */
    public function __construct(
        public readonly array $values,
    ) {
    }

    public static function validate(mixed $value, self $attribute, string $optionName): string
    {
        if (in_array($value, $attribute->values, true)) {
            return $value;
        }

        $allowed = implode('|', $attribute->values);
        throw new \InvalidArgumentException(
            "Invalid value for --{$optionName}: '{$value}'. Allowed: {$allowed}."
        );
    }
}
