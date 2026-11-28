<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Menu;

use SugarCraft\Core\Msg;

/**
 * Sent through a Cmd when Enter selects a leaf of a {@see Menu}. Only that
 * key sends it: moving, opening and closing cascades all return a null Cmd.
 *
 * `$id` is the leaf's id, or null when the leaf was built without one;
 * `$label` is its sanitized label; `$path` is the index path from the root
 * level down to the leaf, the same shape as {@see MenuCursor::path()}.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no menu component,
 * so there is no upstream Mirrors citation.
 */
final class MenuSelectedMsg implements Msg
{
    /**
     * @param non-empty-list<int> $path
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $label,
        public readonly array $path,
    ) {
    }
}
