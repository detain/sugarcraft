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
