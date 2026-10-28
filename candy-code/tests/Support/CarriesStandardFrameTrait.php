<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

/**
 * THE STANDARD TEST FRAME: 120 columns by 40 rows.
 *
 * WHY A TRAIT. Three menu-bar suites — `App/MenuBarPaneTabClickTest`,
 * `App/PanesSubmenuToggleTest` and `Tui/MenuHoverTest` — all drive the
 * shell through `TuiRenderer::renderView()` / `setSize()` at the same
 * geometry: 120 columns is the width where every menu title and the full
 * `Currently:` indicator fit without folding (P-MENU.e's monotonic budget
 * only starts shedding below 61), and 40 rows leaves the transcript, the
 * input and the status band each a live band. Each file declared the pair
 * privately; the third copy tripped
 * {@see \SugarCraft\Code\Tests\Support\DuplicatedTestHelperDriftTest}'s
 * identical-constant polity, whose prescribed motion is to consolidate the
 * copies behind one provider rather than license them. This file is that
 * provider: it owns the pair and nothing else, and every call site keeps
 * reading `self::FRAME_COLS` / `self::FRAME_ROWS` unchanged.
 *
 * WHAT STAYS PER-SUITE ON PURPOSE: what the frame is used to prove. The
 * click, toggle and hover laws are each pinned in their own file; this
 * carrier makes the stage identical, not the acts.
 */
trait CarriesStandardFrameTrait
{
    /** Columns of the standard test frame (wide enough to fold nothing). */
    public const FRAME_COLS = 120;

    /** Rows of the standard test frame. */
    public const FRAME_ROWS = 40;
}
