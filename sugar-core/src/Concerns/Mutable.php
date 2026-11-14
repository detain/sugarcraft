<?php

declare(strict_types=1);

namespace SugarCraft\Core\Concerns;

/**
 * Provides standardized immutable-with pattern via with*() methods.
 *
 * Usage in a class:
 *   use Mutable;
 *
 *   private function mutate(array $changes): static
 *   {
 *       return new static(...array_merge(get_object_vars($this), $changes));
 *   }
 *
 *   public function withFoo(string $foo): static
 *   {
 *       return $this->mutate(['foo' => $foo]);
 *   }
 *
 * For classes with sentinel-bool nullable fields that need the
 *   mutate(field: $val, fieldSet: true, propsAdded: ['field'])
 *   pattern, override mutate() in that class specifically.
 *
 * Consumer roster (sc-26 / finding sugar-core-039 census): zero in-lib
 * consumers — sugar-core's own carriers promote readonly ctor properties or
 * hand-roll `mutate()`. The trait is exported for downstream carriers,
 * which is its only job: the repo-wide census at fix time found the
 * `candy-code` carrier family (Board/Teammate/Chat/Context + Format
 * classes) as the live riders, per the AGENTS "carriers with >=7 positional
 * fields use sugar-core Concerns\Mutable" law. Deleting it here would
 * fatal those consumers; keeping it documented is the ship-default.
 */
trait Mutable
{
    /**
     * Create a new instance with the given changes merged in.
     *
     * @param array<string, mixed> $changes Key-value pairs to change
     * @return static  New instance
     */
    protected function mutate(array $changes): static
    {
        return new static(...array_merge(get_object_vars($this), $changes));
    }
}
