<?php

declare(strict_types=1);

namespace CandyCore\Lister;

/**
 * Plain-string adapter that satisfies \Stringable for use as a list item.
 */
final class StringItem implements \Stringable
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
