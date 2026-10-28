<?php

declare(strict_types=1);

namespace SugarCraft\Code\Palette;

use SugarCraft\Code\Commands\CommandSpec;

/**
 * The palette row list as a pure function: action rows first, then one prefill
 * row per slash-visible command that has no action of its own.
 *
 * WHY (C-EXT-5 core). The Ctrl+P palette today lists only {@see PaletteAction}
 * rows, so every slash-only survivor — the built-ins typed as "/status", file
 * commands with their tier badge, MCP prompts badged through the `mcp` tier —
 * is invisible until the operator already knows the name. The fix is a
 * superset, and the superset is built here, away from Chat and the renderer,
 * so the dispatch/wiring step (C-EXT-5.b) lands on a row list that is already
 * tested rather than on a render-time merge nobody can pin.
 *
 * INVARIANTS.
 * - The action segment is byte-identical to today's palette: same declared
 *   order from {@see PaletteAction::all()}, same labels, same categories.
 * - A prefill label always starts with '/', and no action label does, so the
 *   two halves of the list can never collide on a label; a command that also
 *   carries a palette action is NOT emitted twice — its action row is its one
 *   appearance, which keeps selection behaviour unchanged for it.
 * - The display text comes only from fields {@see CommandSpec} sanitised at
 *   its load boundary (name via its name pattern, category, argument hint);
 *   the catalog itself adds exactly the '/', the joining ' ', and characters
 *   the name pattern already admits. Badges ride verbatim from
 *   {@see CommandSpec::tierBadge()} so the renderer and the slash popup agree.
 *
 * The catalog holds no state and dispatches nothing: it turns a row list in,
 * hands a row list out, and dedupes on first-seen label.
 */
final class PaletteCatalog
{
    private function __construct()
    {
    }

    /**
     * @param list<CommandSpec> $slashRows the slash-visible command rows (Chat::slashCommandRows() shape; registry rows and file/MCP commands alike)
     * @return list<PaletteEntry> action rows in declared order, then prefill rows in input order, deduped on label
     */
    public static function rows(array $slashRows): array
    {
        /** @var list<PaletteEntry> $entries */
        $entries = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach (PaletteAction::all() as $action) {
            $label = $action->label();
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $entries[] = new PaletteEntry(
                PaletteEntryKind::Action,
                $label,
                $action->category(),
                $action->shortcut(),
            );
        }

        foreach ($slashRows as $spec) {
            if (!$spec->slashVisible || $spec->paletteAction !== null) {
                // Hidden rows never reach any popup; a row with an action was
                // already emitted as that action above.
                continue;
            }
            $label = self::prefillLabel($spec);
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $entries[] = new PaletteEntry(
                PaletteEntryKind::Prefill,
                $label,
                $spec->category,
                $spec->shortcut,
                $spec->tierBadge(),
                $spec->name,
            );
        }

        return $entries;
    }

    /**
     * The command's palette text: '/name' plus its argument hint when it has one.
     */
    private static function prefillLabel(CommandSpec $spec): string
    {
        if ($spec->argumentHint === null || $spec->argumentHint === '') {
            return '/' . $spec->name;
        }

        return '/' . $spec->name . ' ' . $spec->argumentHint;
    }
}
