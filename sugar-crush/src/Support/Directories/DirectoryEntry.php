<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support\Directories;

/**
 * One child directory a {@see DirectoryBrowser} listed: its name, its resolved
 * path, whether it looks like a project root, and whether this process may
 * list it in turn. Never a file — the browser lists directories only.
 */
final class DirectoryEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $project,
        public readonly bool $readable,
    ) {
    }

    /** @return array{name: string, path: string, project: bool, readable: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'path' => $this->path, 'project' => $this->project, 'readable' => $this->readable];
    }
}
