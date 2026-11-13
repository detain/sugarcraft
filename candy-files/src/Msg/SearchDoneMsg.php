<?php

declare(strict_types=1);

namespace SugarCraft\Files\Msg;

use SugarCraft\Core\Msg;

/**
 * Message broadcast when a recursive search walk (CF-17) completes.
 *
 * Same Msg-marker contract as {@see CopyCompletedMsg}: sugar-core routes
 * promise resolutions through a `?Msg`-typed callback, so the Model must
 * receive the finished hits as a message, never a raw promise.
 *
 * The payload carries the QUERY the walk was scheduled for. The user
 * keeps typing while the walk slices through ticks, so every late
 * resolution is only honored while the model's live search query is
 * byte-identical — a stale answer to an abandoned question is dropped.
 *
 * No upstream — house pattern.
 */
final readonly class SearchDoneMsg implements Msg
{
    /**
     * @param string $query The search query this walk was armed for
     * @param list<array{dir: string, name: string, isDir: bool}> $hits Directories and files found under the walked root, below the depth and visit budgets
     * @param bool $truncated True when the walk stopped on its budget instead of finishing the tree
     */
    public function __construct(
        public string $query,
        public array $hits,
        public bool $truncated,
    ) {}
}
