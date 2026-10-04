<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * One commit of the home memory directory's history ({@see MemoryHistory}) —
 * a row of `/memory log`.
 */
final class MemoryRevision
{
    public function __construct(
        public readonly string $sha,
        public readonly string $shortSha,
        public readonly \DateTimeImmutable $committedAt,
        public readonly string $subject,
    ) {}
}
