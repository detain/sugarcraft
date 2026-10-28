<?php

declare(strict_types=1);

namespace SugarCraft\Code\Palette;

/**
 * Which half of {@see PaletteCatalog::rows()} a row came from.
 *
 * Action  — a row for an existing {@see PaletteAction}: dispatch is by label,
 *           exactly as the palette behaved before the superset landed.
 * Prefill — a row for a slash-visible command that carries no palette action
 *           (the survivors, file commands, MCP prompts). Selecting it writes
 *           the command into the draft; the catalog itself never dispatches —
 *           that behaviour belongs to the consumer (C-EXT-5.b).
 */
enum PaletteEntryKind
{
    case Action;
    case Prefill;
}
