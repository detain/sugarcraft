<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * The methods a {@see Dispatcher} answers, by name. Registration refuses a
 * duplicate, so two method classes cannot silently claim one name.
 */
final class MethodRegistry
{
    /** @var array<string, MethodSpec> */
    private array $methods = [];

    public static function new(): self
    {
        return new self();
    }

    public function add(MethodSpec $method): void
    {
        if (isset($this->methods[$method->name])) {
            throw new \LogicException(\sprintf('The method %s is registered twice.', $method->name));
        }
        $this->methods[$method->name] = $method;
    }

    public function get(string $name): ?MethodSpec
    {
        return $this->methods[$name] ?? null;
    }

    /** @return list<string> every method name, sorted */
    public function names(): array
    {
        $names = \array_keys($this->methods);
        \sort($names);

        return $names;
    }

    /** @return list<MethodSpec> sorted by name */
    public function all(): array
    {
        return \array_map(fn (string $name): MethodSpec => $this->methods[$name], $this->names());
    }
}
