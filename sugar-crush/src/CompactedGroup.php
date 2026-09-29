<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * A group of files produced by the Compactor.
 * `$isCompact` is true when this group was formed from small-file bucketing
 * and false when it represents a single large file.
 *
 * @param non-empty-string $label Category label for compact groups, or the
 *                                single file path for large files.
 * @param list<string> $paths Files in this group.
 * @param bool $isCompact True for small-file buckets, false for large files.
 */
final readonly class CompactedGroup
{
    /**
     * @param non-empty-string $label
     * @param list<string> $paths
     * @param bool $isCompact
     */
    public function __construct(
        public string $label,
        public array $paths,
        public bool $isCompact,
    ) {}

    public function count(): int
    {
        return count($this->paths);
    }

    public function totalSize(): int
    {
        $total = 0;
        foreach ($this->paths as $p) {
            if (is_file($p)) {
                $total += (int) filesize($p);
            }
        }
        return $total;
    }
}
