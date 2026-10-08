<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\View\Region;

/**
 * One btop box's content — the extension seam every later phase builds on.
 *
 * The App owns the frame (layout, box outlines, sub-box outlines, title
 * buttons, clock) and the tick cadence; a Panel owns its data, history and
 * interior. Lifecycle per data tick (update_ms):
 *
 *   1. App asks every shown panel for {@see collect()} (with a fresh
 *      {@see PanelContext}) and batches the Cmds.
 *   2. Each Cmd samples its {@see \SugarCraft\Top\Source\Source} OFF the
 *      update path and returns a {@see \SugarCraft\Top\Msg\SampledMsg}.
 *   3. App routes that Msg to the panel whose {@see box()} matches; the
 *      panel's {@see update()} stores snapshot + next source (+ history).
 *   4. view(): App paints chrome, then calls {@see paint()} with a Region
 *      clipped to the panel's box (border rows included — btop embeds
 *      readouts and buttons there), then paints the clock on top.
 *
 * Input precedence (btop Input::process, btop_input.cpp:158-161, 217-222,
 * 301-342), checked by the App for every KeyMsg / MouseMsg:
 *
 *   0. `ctrl+c` always quits.
 *   1. Behind the "terminal size too small" notice no panel is painted and
 *      only `q` and the box toggles `1`-`4` act (btop.cpp:180-198); every
 *      other key and every mouse event is dropped.
 *   2. MODAL: the first visible panel (layout order) whose {@see modal()}
 *      returns true receives every key ALONE — `q`, `1`-`4`, `+`/`-` and
 *      `escape` included — ahead of the App's global keys, and the only
 *      mouse events it gets are bare left-button presses (no shift/alt/ctrl);
 *      every other mouse event — right/middle/modified presses included —
 *      is dropped (btop's proc filter: proc_filtering is checked before
 *      anything else and mouse input collapses to "mouse_click").
 *   3. CLAIM: otherwise every key except the globals `q` and `1`-`4` is
 *      offered to the visible panels in layout order through
 *      {@see capturesKey()} — those two are never asked, so no claim can
 *      disable quit or the box toggles; the first that returns true
 *      receives it ALONE via {@see update()} and the App does nothing else
 *      with it — btop's proc box claims `+`/`-`/`=` while proc_tree is on
 *      (btop_input.cpp:491).
 *   4. Unclaimed keys reach the App's globals (`q`, `1`-`4`, `+`/`-`/`=`);
 *      a key the App did not consume, every mouse event, WindowSizeMsg
 *      (after the App re-laid out) and any other Msg is broadcast to every
 *      panel — ignore what you do not handle and return
 *      `new PanelResult($this)`.
 *
 * Context: every {@see collect()}, {@see modal()}, {@see capturesKey()} and {@see update()}
 * call gets a fresh {@see PanelContext}: the App's CURRENT Config, the
 * CURRENT Layout and this panel's own box (null while hidden), built at the
 * moment of the call — so it already reflects toggles, option writes and
 * resizes. paint() sees the same on {@see PanelFrame}. Never cache either.
 *
 * Config writes: return them in {@see PanelResult::$set}. The App applies
 * them synchronously inside the same update() — validated with
 * {@see Config::with()}, then {@see \SugarCraft\Top\App::applyConfig()}
 * (relayout, tick re-arm, newly-shown-box sampling, colour profile) — so the
 * very next Msg, even a key from the same terminal read, sees the new value,
 * exactly like btop's immediate Config::set. A Cmd that only learns the
 * value asynchronously emits {@see \SugarCraft\Top\Msg\SetOptionMsg}
 * instead, which goes through the same validation.
 *
 * Contract: immutable (update returns a new instance), no I/O outside the
 * Cmd returned by collect()/update(), paint() writes only through the
 * Region and never reads the clock or the filesystem.
 */
interface Panel
{
    /** The btop box name this panel fills: cpu | mem | net | proc. */
    public function box(): string;

    /**
     * Cmd that samples this panel's source; null when there is nothing to
     * sample. `$context` is built at the moment of the call (current Config,
     * Layout and box), so read sampling options — disks_filter, net_iface,
     * proc_sorting, ... — from `$context->config` HERE and capture them into
     * the Cmd; never cache them from an earlier update() or from startup.
     */
    public function collect(PanelContext $context): ?\Closure;

    /**
     * True while this panel owns ALL input (step 2 of the class doc) —
     * P-E returns `$context->config->bool('proc_filtering')`. Only asked
     * while the panel is visible and the frame fits.
     */
    public function modal(PanelContext $context): bool;

    /**
     * True to take `$key` ahead of the App's global keys (step 3 of the
     * class doc). Decide from this panel's own state together with
     * `$context` — e.g. P-E claims `+`/`-`/`=` only while
     * `$context->config` has proc_tree on (btop_input.cpp:491). Return
     * false for anything you do not consume, or that global key stops
     * working.
     */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool;

    public function update(Msg $msg, PanelContext $context): PanelResult;

    /**
     * Paint the box interior (and any border embeds) into `$region`, whose
     * (0, 0) is the box's top-left corner cell.
     */
    public function paint(Region $region, PanelFrame $frame): void;
}
