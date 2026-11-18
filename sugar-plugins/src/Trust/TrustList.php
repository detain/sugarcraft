<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Trust;

/**
 * The names a user has opted into, matched exactly.
 *
 * Trust is by exact name and never case-folded: `Foo` and `foo` are different
 * plugins, and a checked-out project cannot widen the list it is judged by.
 */
final readonly class TrustList
{
    /** @var list<string> */
    private array $names;

    /** @param list<string> $names */
    public function __construct(array $names)
    {
        $this->names = \array_values($names);
    }

    public function contains(string $name): bool
    {
        return \in_array($name, $this->names, true);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }
}
