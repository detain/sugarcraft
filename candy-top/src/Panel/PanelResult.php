<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Top\Overlay\Overlay;

/**
 * What {@see Panel::update()} returns: the next panel, an optional Cmd, and
 * config writes the App applies SYNCHRONOUSLY — before it handles the next
 * Msg.
 *
 * `$set` is btop's immediate `Config::set(key, value)` from an input handler
 * (btop_input.cpp:303-391): the App applies each entry in order through
 * {@see \SugarCraft\Top\Config\Config::with()} (a value btop would reject is
 * dropped silently, the others still apply) and then
 * {@see \SugarCraft\Top\App::applyConfig()}. Because candy-core's Program
 * dispatches every key of one terminal read before any Cmd runs, a write
 * routed through a Cmd would land one round-trip late — two `a` presses in
 * one read would both toggle net_auto from the same stale value. Use `$set`
 * for anything a key decides; keep {@see \SugarCraft\Top\Msg\SetOptionMsg}
 * for writes produced asynchronously inside a Cmd.
 *
 * `$overlay` is a menu the panel asks the App to open — btop's
 * `Menu::show()` from an input handler (P-E's `t` / `k` / `s` / `N`). The
 * App pushes it onto its overlay stack after applying `$set`; the panel
 * never draws a popup into its own Region nor keeps a modal of its own.
 */
final class PanelResult
{
    /**
     * @param array<string, bool|int|string> $set option => value, applied in order
     */
    public function __construct(
        public readonly Panel $panel,
        public readonly ?\Closure $cmd = null,
        public readonly array $set = [],
        public readonly ?Overlay $overlay = null,
    ) {
    }
}
