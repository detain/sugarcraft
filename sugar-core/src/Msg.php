<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Marker interface for messages flowing through the {@see Program} loop.
 *
 * A Msg is any event the user's {@see Model::update()} should react to —
 * keypresses, window-size changes, ticks, custom application messages, etc.
 * Implementations are typically immutable readonly value objects.
 *
 * The marker itself declares no members: every property and helper lives on
 * the concrete message class (e.g. {@see Msg\KeyMsg} carries `$type`,
 * `$rune`, `$alt`, `$ctrl`, `$shift` and `string()`; {@see Msg\MouseMsg}
 * carries `$x`, `$y`, `$button`, `$action`; {@see Msg\WindowSizeMsg}
 * carries `$cols`, `$rows`). The README's Message catalogue indexes them.
 *
 * Factory exemption (sc-26 / finding sugar-core-039, DX-19 census: zero
 * `::new()` across src/Msg + src/Exception): upstream
 * charmbracelet/bubbletea declares these messages as bare struct types with
 * no constructor functions (`type SuspendMsg struct{}` — callers compose the
 * literal), so `new <Msg>(...)` is the faithful mirror and no `::new()`
 * counterpart exists to mirror. AGENTS' "factories mirror upstream" therefore
 * exempts this family wholesale, per-file, by this dated note.
 */
interface Msg
{
}
