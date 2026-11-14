<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * One match from a history scan: the storage index and the entry itself.
 *
 * Finding 091: search() used to return an untyped `array{index:int,
 * entry:string}`, so every consumer re-guessed the shape. The carrier is
 * the shape made first-class — readonly, constructed by the storage
 * implementation, read by the prompt.
 */
final class SearchHit
{
    private function __construct(
        public readonly int $index,
        public readonly string $entry,
    ) {
    }

    public static function new(int $index, string $entry): self
    {
        return new self($index, $entry);
    }
}
