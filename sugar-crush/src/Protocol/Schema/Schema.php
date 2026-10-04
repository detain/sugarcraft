<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Schema;

/**
 * One JSON Schema (draft 2020-12) node, built in PHP (roadmap O-3c, Appendix O
 * §6.11): the vocabulary `docs/protocol/sugarcrush.v1.schema.json` is written
 * in, and the one {@see SchemaValidator} checks a request's params against.
 *
 * DELIBERATELY A SUBSET: types, `enum`, `properties` / `required` /
 * `additionalProperties`, `items`, bounds, `oneOf`, `$ref` and descriptions —
 * enough for every shape the protocol has, and few enough that the validator
 * is exact for all of it rather than approximately right for more.
 *
 * OBJECTS ARE OPEN BY DEFAULT: a client must ignore a field it does not know
 * (§6.2), so a result may grow, and a request may carry a field a newer
 * server reads — within a major, every change is additive.
 *
 * Immutable: every `with*()`/modifier answers a new node.
 */
final class Schema
{
    /** @param array<string, mixed> $node */
    private function __construct(private readonly array $node)
    {
    }

    public static function string(?int $maxLength = null): self
    {
        return new self(\array_filter(['type' => 'string', 'maxLength' => $maxLength], static fn (mixed $v): bool => $v !== null));
    }

    public static function integer(?int $minimum = null, ?int $maximum = null): self
    {
        return new self(\array_filter(['type' => 'integer', 'minimum' => $minimum, 'maximum' => $maximum], static fn (mixed $v): bool => $v !== null));
    }

    public static function number(int|float|null $minimum = null): self
    {
        return new self(\array_filter(['type' => 'number', 'minimum' => $minimum], static fn (mixed $v): bool => $v !== null));
    }

    public static function boolean(): self
    {
        return new self(['type' => 'boolean']);
    }

    /** @param list<string|int|bool> $values */
    public static function enum(array $values): self
    {
        return new self(['enum' => \array_values($values)]);
    }

    public static function arrayOf(self $items, ?int $maxItems = null): self
    {
        return new self(\array_filter(['type' => 'array', 'items' => $items->node, 'maxItems' => $maxItems], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * An object of these properties; those in $required must be present.
     *
     * @param array<string, self> $properties
     * @param list<string> $required
     */
    public static function object(array $properties = [], array $required = [], bool $open = true): self
    {
        foreach ($required as $name) {
            if (!isset($properties[$name])) {
                throw new \InvalidArgumentException(\sprintf('required property %s has no schema', $name));
            }
        }
        $node = ['type' => 'object'];
        if ($properties !== []) {
            $node['properties'] = \array_map(static fn (self $schema): array => $schema->node, $properties);
        }
        if ($required !== []) {
            $node['required'] = \array_values($required);
        }
        if (!$open) {
            $node['additionalProperties'] = false;
        }

        return new self($node);
    }

    /** An object whose every value is $values (a map keyed by any string). */
    public static function map(self $values): self
    {
        return new self(['type' => 'object', 'additionalProperties' => $values->node]);
    }

    /** Anything at all. */
    public static function any(): self
    {
        return new self([]);
    }

    /** A reference to the shared definition $name (`#/$defs/<name>`). */
    public static function ref(string $name): self
    {
        return new self(['$ref' => '#/$defs/' . $name]);
    }

    public static function oneOf(self ...$schemas): self
    {
        return new self(['oneOf' => \array_map(static fn (self $schema): array => $schema->node, $schemas)]);
    }

    /** This, or `null`. */
    public function nullable(): self
    {
        $node = $this->node;
        if (isset($node['type']) && \is_string($node['type'])) {
            $node['type'] = [$node['type'], 'null'];

            return new self($node);
        }

        return new self(['oneOf' => [$node, ['type' => 'null']]]);
    }

    public function describe(string $description): self
    {
        return new self([...$this->node, 'description' => $description]);
    }

    /**
     * This object with $properties added (and $required too).
     *
     * @param array<string, self> $properties
     * @param list<string> $required
     */
    public function with(array $properties, array $required = []): self
    {
        $node = $this->node;
        foreach ($properties as $name => $schema) {
            $node['properties'][$name] = $schema->node;
        }
        if ($required !== []) {
            $node['required'] = \array_values(\array_unique([...($node['required'] ?? []), ...$required]));
        }

        return new self($node);
    }

    /** @return array<string, mixed> the JSON Schema node */
    public function toArray(): array
    {
        return $this->node;
    }
}
