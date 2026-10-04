<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Schema;

/**
 * Checks a decoded JSON value against a {@see Schema} node — exactly the
 * subset {@see Schema} can build, so a "valid" here means valid, not "valid
 * as far as this validator understood".
 *
 * Two PHP facts are folded in: `json_decode(…, true)` turns `{}` into `[]`,
 * so an empty array satisfies `object` as well as `array`; and a JSON number
 * with a fraction is a float, so `integer` takes ints only while `number`
 * takes both.
 *
 * Errors name the PATH (`params.sessionId`) and what was expected, never the
 * value — a validation error is answered to the client, and nothing a client
 * sent is echoed back.
 */
final class SchemaValidator
{
    /**
     * @param array<string, array<string, mixed>> $defs `$defs` a `$ref` resolves against
     */
    private function __construct(private readonly array $defs)
    {
    }

    /** @param array<string, array<string, mixed>> $defs */
    public static function new(array $defs = []): self
    {
        return new self($defs);
    }

    /**
     * Every way $value breaks $schema, as `path: expectation`; empty when it fits.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public function errors(mixed $value, array $schema, string $path = '$'): array
    {
        if (isset($schema['$ref'])) {
            $name = \substr((string) $schema['$ref'], \strlen('#/$defs/'));
            $target = $this->defs[$name] ?? null;
            if ($target === null) {
                return [$path . ': unknown definition ' . $name];
            }

            return $this->errors($value, $target, $path);
        }

        if (isset($schema['oneOf'])) {
            $matches = 0;
            $first = [];
            foreach ($schema['oneOf'] as $option) {
                $errors = $this->errors($value, $option, $path);
                if ($errors === []) {
                    $matches++;
                } elseif ($first === []) {
                    $first = $errors;
                }
            }

            return match (true) {
                $matches === 1 => [],
                $matches === 0 => $first !== [] ? $first : [$path . ': matches none of its alternatives'],
                default => [$path . ': matches more than one of its alternatives'],
            };
        }

        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            return [$path . ': must be one of ' . \implode(', ', \array_map(static fn (mixed $v): string => \is_string($v) ? $v : \var_export($v, true), $schema['enum']))];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = null;
            foreach ($types as $type) {
                if (self::is($value, (string) $type)) {
                    $matched = (string) $type;
                    break;
                }
            }
            if ($matched === null) {
                return [$path . ': must be ' . \implode(' or ', $types)];
            }

            return match ($matched) {
                'object' => $this->objectErrors((array) $value, $schema, $path),
                'array' => $this->arrayErrors((array) $value, $schema, $path),
                'string' => isset($schema['maxLength']) && \strlen((string) $value) > $schema['maxLength'] ? [$path . ': longer than ' . $schema['maxLength']] : [],
                'integer', 'number' => self::boundErrors($value, $schema, $path),
                default => [],
            };
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function objectErrors(array $value, array $schema, string $path): array
    {
        $errors = [];
        foreach ($schema['required'] ?? [] as $name) {
            if (!\array_key_exists($name, $value)) {
                $errors[] = $path . '.' . $name . ': is required';
            }
        }
        $properties = $schema['properties'] ?? [];
        $additional = $schema['additionalProperties'] ?? true;
        foreach ($value as $name => $item) {
            $name = (string) $name;
            if (isset($properties[$name])) {
                \array_push($errors, ...$this->errors($item, $properties[$name], $path . '.' . $name));
            } elseif ($additional === false) {
                $errors[] = $path . ': has a property it does not allow';
            } elseif (\is_array($additional)) {
                // A map's keys are the client's, so the path names the map, not the key.
                \array_push($errors, ...$this->errors($item, $additional, $path . '.*'));
            }
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function arrayErrors(array $value, array $schema, string $path): array
    {
        $errors = [];
        if (isset($schema['maxItems']) && \count($value) > $schema['maxItems']) {
            $errors[] = $path . ': more than ' . $schema['maxItems'] . ' items';
        }
        if (isset($schema['items'])) {
            foreach (\array_values($value) as $i => $item) {
                \array_push($errors, ...$this->errors($item, $schema['items'], $path . '[' . $i . ']'));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function boundErrors(int|float $value, array $schema, string $path): array
    {
        $errors = [];
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = $path . ': below ' . $schema['minimum'];
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = $path . ': above ' . $schema['maximum'];
        }

        return $errors;
    }

    private static function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => \is_array($value) && ($value === [] || !\array_is_list($value)) || $value instanceof \stdClass,
            'array' => \is_array($value) && \array_is_list($value),
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
