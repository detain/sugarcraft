<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Attribute;

use Attribute;

/**
 * Marks a backed enum as a constrained-value set for CLI options.
 *
 * Use with {@see \SugarCraft\Shell\Attribute\Flag} to restrict
 * an option to a fixed set of values:
 *
 *   #[Flag(name: 'format', enum: FormatType::class)]
 *   enum FormatType: string {
 *       case Json  = 'json';
 *       case Yaml  = 'yaml';
 *       case Toml  = 'toml';
 *   }
 *
 * The scanner validates the provided value against the enum
 * and throws a clear error for invalid values.
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
