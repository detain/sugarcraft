<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support\Directories;

/**
 * What {@see DirectoryBrowser::list()} found in one directory: its resolved
 * path, its parent (null at the browse root, or at `/`), its child
 * directories, and whether the list was cut at the cap or could not be read
 * at all — an unreadable directory is reported, not thrown.
 */
final class DirectoryListing
{
    /**
     * @param list<DirectoryEntry> $entries sorted by name, natural and case-insensitive
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $parent,
        public readonly array $entries,
        public readonly bool $truncated,
        public readonly bool $readable,
        public readonly bool $project,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'parent' => $this->parent,
            'entries' => \array_map(static fn (DirectoryEntry $entry): array => $entry->toArray(), $this->entries),
            'truncated' => $this->truncated,
            'readable' => $this->readable,
            'project' => $this->project,
        ];
    }
}
