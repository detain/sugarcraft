<?php

declare(strict_types=1);

namespace SugarCraft\Code\Palette;

use SugarCraft\Code\Commands\CommandSpec;

/**
 * One pure row of {@see PaletteCatalog::rows()}.
 *
 * Not mirrored from any upstream: this is the repo's own palette vocabulary,
 * widened to carry command rows next to action rows. The struct holds no
 * behaviour — selection semantics live in the consumer that walks the list.
 */
final class PaletteEntry
{
    /**
     * @param PaletteEntryKind $kind        whether this row dispatches by label (Action) or prefills the draft (Prefill)
     * @param string           $label       row identity in the palette: the unchanged action label, or the composed '/name <hint>' prefill label
     * @param string           $category    grouping header text the renderer shows the row under
     * @param ?string          $shortcut    optional keybind glyph, straight from the spec or action
     * @param ?string          $badge       provenance badge for Prefill rows (from {@see CommandSpec::tierBadge()}); null for built-in commands and for ALL Action rows
     * @param ?string          $commandName spec name for Prefill rows — the consumer writes '/'.$commandName.' ' into the draft; null for Action rows
     */
    public function __construct(
        public readonly PaletteEntryKind $kind,
        public readonly string $label,
        public readonly string $category,
        public readonly ?string $shortcut = null,
        public readonly ?string $badge = null,
        public readonly ?string $commandName = null,
    ) {
    }
}
