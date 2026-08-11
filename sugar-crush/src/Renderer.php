<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Shine\Renderer as Markdown;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentStatusBar;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\Pane;

/**
 * Pure view function for {@see Chat} — the renderer actually reached by a
 * real user running `bin/sugarcrush` (`Chat::view()` calls
 * {@see self::render()}). `src/Tui/Renderer.php` + its `App`-keyed
 * Pane/Component system is a second, parallel renderer that nothing in the
 * live path ever constructs; this class is deliberately kept independent of
 * it (see the "R20 wiring decision" note below).
 *
 * Lays out the conversation scrollback (with each turn rendered through
 * CandyShine) above a fixed input area at the bottom, plus — when the
 * matching {@see Chat} state is present — a session tab strip and an agent
 * status/view section.
 *
 * Rendered shape:
 *
 *   session-a | [session-b] | session-c        ← only when ≥2 sessions exist
 *   ┌─ SugarCrush ───────────────────────┐
 *   │ user> hello                        │
 *   │ assistant: ## Hi there!             │
 *   │            paragraph of markdown    │
 *   │ user> question                     │
 *   │ assistant: …                        │
 *   ├─────────────────────────────────────┤
 *   │ > █                                 │   ← input area
 *   └─────────────────────────────────────┘
 *   Enter to send · Esc / ^C to quit
 *   ● reviewer [working] Reviews code…  0s  0 tok | $0.0000   ← only when
 *   ┌─ agents ────────────────────────────┐                     Chat has an
 *   │ ● reviewer [working]  Reviews code… │                     AgentManager
 *   └──────────────────────────────────────┘                    with active agents
 *
 * The CandyShine renderer is constructed once per call (cheap;
 * just holds a theme reference). Only the assistant's Markdown gets
 * rendered through CandyShine; the raw user/system turns and the
 * in-progress input are run through {@see Sanitize::untrusted()}
 * first (see the render methods for why).
 *
 * ## R20 wiring decision (agent status/view + session tabs)
 *
 * {@see \SugarCraft\Crush\Tui\AgentStatusBar} and
 * {@see \SugarCraft\Crush\Tui\AgentViewPane} already accept plain
 * `list<AgentDisplayState>` + primitives as their render() arguments, NOT
 * an `App` — so option (a) from the R20 brief ("adapt the components to
 * accept the specific Chat-derived data they actually need") was already
 * true for them with zero changes to those two classes. That made it the
 * smaller move versus option (b) (building a throwaway `App::new(...)`
 * adapter here): `App::new()` requires a real `ProviderInterface`, which
 * `Chat` does not hold (it holds the unrelated `Backend` interface), so
 * satisfying that constructor here would mean fabricating a fake provider
 * purely to appease a type signature we don't otherwise need. This class
 * builds `AgentDisplayState` values directly from
 * `Chat::agentManager()->active()` (real `Agent` registrations) instead.
 *
 * ### R20.fix: `agentManager` is not yet populated in production
 *
 * The rendering below is only reachable when `Chat::agentManager()` is
 * non-null. Today, `SugarCraft\Crush\Cli\Bootstrap::chat()` — the
 * construction path `bin/sugarcrush` actually runs — never passes an
 * `agentManager:` argument (constructing a real one needs a
 * `ProviderInterface` + `SkillRegistry`, which `Bootstrap::backend()`
 * builds internally but does not currently expose for this purpose), so
 * `renderAgentView()` always returns `''` for a real `bin/sugarcrush` user
 * regardless of config. This is honestly a currently-unreachable code path
 * pending that follow-up wiring in `Bootstrap.php` (not in this item's file
 * scope) — it is exercised today only by tests that construct
 * `new Chat(agentManager: ...)` directly. `Chat::handleAgentsCommand()`
 * (and the Ctrl+A shortcut that dispatches through it) degrades to a
 * "not configured" message rather than throwing when `agentManager` is
 * null, so this gap is inert rather than crashing — see that method's
 * docblock.
 *
 * Only `Agent::isActive`/`name`/`description` are real, live data from that
 * path — `AgentWorkerPool`/`AgentManager`'s public API (deliberately not
 * touched by this item; both are out of its file scope) exposes only
 * aggregate counts (`getActiveCount()`/`getQueueSize()`), not a per-agent
 * live output buffer, elapsed time, or token/cost accounting. So
 * `elapsedSeconds`/`tokensUsed`/`costUsd` are honestly reported as `0`
 * rather than fabricated, and {@see \SugarCraft\Crush\Tui\AgentOutputPane}
 * (which needs a real streaming output buffer) and the P5.S7/S8 split-pane
 * renderer (`self::renderWithSplit()`/`renderForCurrentEnvironment()` on
 * `Tui\Renderer`, meant for laying out *multiple* agents' live output side
 * by side) are explicitly NOT wired into `render()` here — with no real
 * per-agent output text to show, a split view would only ever display empty
 * tiles, which is worse than the honest single-column status line this
 * renders instead. Wiring either one for real needs a public
 * "current live output buffer" accessor on `AgentManager`/`AgentWorkerPool`
 * first, which is out of scope for this pass (those files are not in R20's
 * file list). `src/Tui/Components/AgentsPane.php` — also in R20's file list
 * — was left unmodified for the same reason `Tui\Renderer.php` itself is
 * untouched: it belongs entirely to the disconnected `App`-keyed system, so
 * fixing its stub body would not make anything reachable from this, the
 * live, path.
 *
 * `Tui\SessionTabs` is not instantiated here either: its constructor always
 * seeds one synthetic "main" tab when started empty, a shape built for a
 * fresh single-session boot rather than for hydrating N pre-existing rows
 * from a `SessionStore`. Retrofitting that would mean changing
 * `SessionTabs.php` itself (not in this item's file scope) or fabricating
 * and discarding a placeholder tab. Its real, tested key surface
 * (`CTRL_TAB`/`CTRL_SHIFT_TAB`, `cycleForward()`/`cycleBackward()`'s
 * wraparound semantics) is instead the design this renderer's tab strip and
 * {@see Chat}'s Ctrl+Tab handling both follow directly against
 * `SessionStore::listSessions()`'s real, persisted row order — see
 * `Chat::cycleSessionTab()`'s docblock for the matching switching half.
 *
 * ### R20.fix: no production code path ever calls `createSession()`
 *
 * `renderSessionTabStrip()` reads real rows from
 * {@see \SugarCraft\Crush\Session\SessionStore::listSessions()}, but nothing
 * in `src/` or `bin/sugarcrush` ever calls
 * `SessionStore::createSession()`/`EnhancedSessionStore::createSession()` —
 * `Chat::init()` returns no startup `Cmd` that would create one either. So
 * `listSessions()` returns `[]` for the entire lifetime of a real
 * `bin/sugarcrush` process today, independent of the `currentSessionId`
 * gap documented above: even a hypothetical fix that seeded a
 * `currentSessionId` into `Bootstrap::chat()` would still show a tab strip
 * with zero rows, because no session row would exist on disk for it to
 * point at. `count($rows) < 2` already degrades this to `''` rather than
 * rendering an empty/malformed strip, so this is inert, not broken — but it
 * is a real gap, and the tests exercising this method
 * (`RendererTest::testRendersSessionTabStripWithMultipleSessionsAndBracketsCurrent`)
 * only do so by constructing a `SessionStore` and calling `createSession()`
 * directly, a path no production code takes. Wiring an actual session-create
 * call into `Bootstrap::chat()`/`Chat::init()` is out of this item's file
 * scope (`Bootstrap.php` is not in R20's file list) and is left as follow-up
 * work alongside the `currentSessionId` seeding noted above.
 */
final class Renderer
{
    /** Maximum rows AgentViewPane renders before clipping (see AgentViewPane::render()). */
    private const AGENT_VIEW_MAX_ROWS = 10;

    /**
     * Maximum diff rows {@see renderDiff()} paints before it clips and prints
     * an "N more lines" trailer. A single Edit can rewrite hundreds of lines;
     * without a cap the diff alone would fill the viewport and evict the whole
     * transcript once {@see render()}'s tail-clipping runs.
     */
    private const DIFF_MAX_ROWS = 24;

    /**
     * Columns the shell's border + padding(1, 2) consume, subtracted before
     * anything inside it is truncated to width.
     */
    private const SHELL_CHROME_COLS = 6;

    /**
     * Rows/characters {@see collapseToolOutput()} keeps of a tool body before
     * it clips and prints an overflow trailer (crush_feat.md §1 E5). Both
     * limits matter independently: a `Grep` result can be 400 short lines
     * (blows the line budget) and a `Bash` result can be one 200KB line
     * (blows the character budget while still being "1 line").
     */
    private const TOOL_OUTPUT_MAX_LINES = 10;

    private const TOOL_OUTPUT_MAX_CHARS = 2000;

    /**
     * Zone-id prefix every session tab carries (crush_feat.md §8 E2). Public
     * because {@see Chat::update()} parses it back off a click's zone id —
     * one literal, defined next to the code that writes it, rather than the
     * same string spelled out independently on both sides of the hit test.
     */
    public const SESSION_TAB_ZONE_PREFIX = 'tab:';

    /**
     * Zone-id prefix every clickable pane region carries (crush_feat.md §8
     * E3). The suffix is always a {@see Pane} case's own `value`, so
     * {@see Chat::update()} can turn a click straight back into the enum
     * case rather than matching hand-spelled strings on both sides.
     */
    public const PANE_ZONE_PREFIX = 'pane:';

    /**
     * The zone-id charset {@see Mark::wrap()} accepts, duplicated here
     * because Mark's own copy is private and it THROWS on a violation.
     * Session ids arrive from disk (`SessionStore::listSessions()`), so an id
     * carrying anything outside this set would take the whole TUI down from
     * inside `view()`; {@see markSessionTab()} checks first and renders that
     * tab unmarked (still visible, still keyboard-switchable) instead.
     */
    private const ZONE_ID_CHARSET = '/\A[A-Za-z0-9._:-]+\z/';

    /**
     * Zone registry the root scan pass writes into, shared across renders
     * because a mouse event arrives *between* frames: the click handler has
     * only the previously-painted frame's boxes to hit-test against.
     * Mirrors bubblezone's single global manager.
     */
    private static ?Scanner $scanner = null;

    /**
     * Zone registry produced by the most recent {@see scanRoot()} pass.
     * Hit-tested by {@see Chat::zoneAt()}.
     */
    public static function scanner(): Scanner
    {
        return self::$scanner ??= Scanner::new();
    }

    /**
     * How many content lines the most recent frame had to drop off the top
     * to fit the terminal — i.e. the largest {@see Chat::scrollOffset()}
     * that still shows a full screen of transcript.
     *
     * Static for the same reason {@see $scanner} is: a wheel event arrives
     * *between* frames, so the only content height it can be clamped
     * against is the one already painted. {@see Chat} is immutable and
     * would discard a per-instance copy of it anyway.
     */
    private static int $maxScrollOffset = 0;

    /**
     * Upper clamp for {@see Chat::scrollOffset()}, measured on the last
     * rendered frame. 0 when the transcript fits the window (nothing to
     * scroll) or when nothing has been rendered yet.
     */
    public static function maxScrollOffset(): int
    {
        return self::$maxScrollOffset;
    }

    /**
     * The zone-scan pass, run exactly ONCE per frame and only at the root.
     *
     * candy-mouse (like bubblezone) records absolute bounding boxes as it
     * walks the string, so scanning a sub-widget's output would register
     * boxes relative to that widget's own origin rather than the terminal's
     * — every nested scan would have to be thrown away and redone here
     * anyway. Scanning after the last compositing step (including the
     * palette overlay) is therefore both the cheapest and the only correct
     * placement.
     *
     * The scan is skipped outright when `SUGARCRUSH_DISABLE_MOUSE` is set —
     * with tracking off no mouse coordinates ever arrive, so keeping a stale
     * zone registry around would be pure waste. Sentinel *stripping* is
     * unconditional: the markers are Private-Use codepoints a terminal would
     * paint as a replacement glyph, and candy-core's line diff counts them
     * as content.
     *
     * Marker-free frames take a `str_contains()` fast path (~0.0004ms) and
     * skip both the parse and the strip. That is not a micro-optimisation:
     * {@see \SugarCraft\Mouse\Scan::parse()} walks the frame cluster by
     * cluster through `grapheme_extract()`, which measures ~24ms on a
     * full-screen frame — roughly doubling the cost of a keystroke repaint.
     * {@see markSessionTab()} is currently the only caller of
     * {@see \SugarCraft\Mouse\Mark::zone()}, and it only fires when the
     * session tab strip is drawn at all (≥2 sessions on disk), so a
     * single-session run still takes this branch on every frame.
     *
     * The scan is also non-fatal. `Scan::parse()` throws on malformed markup
     * (duplicate/unclosed ids), and this runs inside `Chat::view()`, where an
     * escaping exception kills the whole TUI. Degrading to "no zones this
     * frame" costs at most an unclickable frame; the alternative is a crash.
     * Untrusted text is sentinel-stripped on the way in (see
     * {@see untrusted()}), so a throw here means OUR markup is wrong, not
     * that a model injected something.
     *
     * @param string $frame The fully-composited root frame.
     * @param int    $width Viewport width; zone end columns clamp to it.
     */
    public static function scanRoot(string $frame, int $width): string
    {
        if (!str_contains($frame, Sentinel::OPEN) && !str_contains($frame, Sentinel::CLOSE)) {
            self::scanner()->clear();

            return $frame;
        }

        if (Chat::mouseMode() === MouseMode::Off) {
            self::scanner()->clear();
        } else {
            try {
                self::scanner()->scan($frame, $width);
            } catch (\Throwable) {
                self::scanner()->clear();
            }
        }

        return self::stripZoneMarkers($frame);
    }

    /**
     * {@see Sanitize::untrusted()} plus zone-sentinel removal, for every
     * string that originated outside this process (model replies, tool
     * output, pasted keystrokes).
     *
     * The sentinel strip is the security half. `Sanitize::untrusted()` only
     * removes ANSI/C0/C1/DEL; `U+E000`/`U+E001` are well-formed 3-byte UTF-8
     * Private-Use codepoints, so they survive it untouched and would reach
     * {@see scanRoot()}'s parser verbatim. A model reply — or any tool output
     * echoed into a message: a file read, a web fetch, a shell command run in
     * a hostile repo — could then either crash the render (duplicate ids make
     * `Scan::parse()` throw) or, worse, register attacker-chosen boxes in the
     * hit-test registry {@see Chat::zoneAt()} reads, hijacking clicks meant
     * for real UI. Stripping at the boundary keeps the invariant that only
     * {@see \SugarCraft\Mouse\Mark}-emitted markers ever reach the scan.
     */
    private static function untrusted(string $text): string
    {
        return self::stripSentinels(Sanitize::untrusted($text));
    }

    /**
     * Remove bare zone sentinels, for content that must NOT go through
     * {@see untrusted()} — assistant Markdown, which CandyShine renders into
     * legitimate SGR that `Sanitize::untrusted()` would strip back out.
     */
    private static function stripSentinels(string $text): string
    {
        return str_replace([Sentinel::OPEN, Sentinel::CLOSE], '', $text);
    }

    /**
     * Remove every `U+E000 <id> U+E001` open/close sentinel pair emitted by
     * {@see \SugarCraft\Mouse\Mark}. Matched byte-wise against Mark's own id
     * charset (plus the closing `/`) so a frame carrying invalid UTF-8 can
     * never make the pattern fail open and leak markers to the terminal.
     */
    private static function stripZoneMarkers(string $frame): string
    {
        return (string) preg_replace(
            '/\xEE\x80\x80\/?[A-Za-z0-9._:-]*\xEE\x80\x81/',
            '',
            $frame
        );
    }

    public static function render(Chat $chat): string
    {
        $theme = $chat->theme();
        $body = self::renderHistory($chat->history, $theme, max(20, $chat->cols() - self::SHELL_CHROME_COLS), $chat->expanded());
        if ($chat->inFlight) {
            // Visible in the chat window itself, not just the status bar -
            // a spinner-only status line is easy to miss; this sits right
            // where the reply is about to appear.
            $thinking = Style::new()->foreground($theme->assistantLabel)->faint()->render('⠴ assistant is thinking…');
            $body = $body === '' ? $thinking : $body . "\n\n" . $thinking;
        }
        $input = self::renderInput($chat, $theme);
        $slashMenu = self::renderSlashMenu($chat, $theme);

        $shell = Style::new()
            ->border(Border::rounded())
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->render($body);

        $content = $shell . "\n" . $input . ($slashMenu !== '' ? "\n" . $slashMenu : '');

        $tabStrip = self::renderSessionTabStrip($chat);
        if ($tabStrip !== '') {
            $content = $tabStrip . "\n" . $content;
        }

        $agentView = self::renderAgentView($chat);
        if ($agentView !== '') {
            $content .= "\n" . $agentView;
        }

        // Full-window usage: fit the frame to exactly $rows lines, always.
        // candy-core's Renderer repaints changed rows via an ABSOLUTE
        // cursorTo($row, 1) - it has no concept of scrolling. If $content
        // is ever taller than the real terminal, every cursorTo() past the
        // terminal's last row gets silently clamped there by the terminal
        // itself, so distinct logical rows (input box, status bar, the
        // newest history lines) all collide on that one physical row -
        // which is exactly what "text/cursor ends up in the status bar"
        // looks like once a conversation grows past one screen. Clipping
        // to the tail keeps the input box (the last part of $content)
        // and the newest history visible, scrolling older turns off the
        // top - the same tradeoff any fixed-viewport TUI makes. Short
        // conversations still get padded so the status bar lands on the
        // true last line instead of leaving most of the window blank.
        //
        // $chat->rows() (sourced from WindowSizeMsg - the size candy-core's
        // Program actually dispatches, live, on every resize) is the
        // authoritative value here - NOT a second, independent
        // TuiRenderer::getTerminalSize() query. That second query has its
        // own statically-cached, never-invalidated detection of the SAME
        // terminal that can silently disagree with what Program itself
        // knows (and never learns about a live resize either), which
        // reintroduces the exact row-collision this clipping is meant to
        // prevent even after clipping was added.
        //
        // Which $available-line window of $content is shown is the one thing
        // the tail clip above leaves to the user: $chat->scrollOffset() is a
        // distance in lines from the BOTTOM (0 = pinned to the newest line,
        // the historical behaviour), so scrolling back moves the window's
        // start earlier by exactly that many lines (crush_feat.md §8 E4).
        // It is re-clamped against THIS frame's overflow rather than trusted:
        // the offset was clamped against the frame that was on screen when
        // the wheel turned, and the transcript can have shrunk since (/clear,
        // a session switch, a resize), which would otherwise slice past the
        // start of the content and show a short frame.
        $rows = $chat->rows();
        $available = max(1, $rows - 1);
        $contentLines = explode("\n", $content);
        $overflow = max(0, count($contentLines) - $available);
        self::$maxScrollOffset = $overflow;
        if ($overflow > 0) {
            $offset = max(0, min($chat->scrollOffset(), $overflow));
            $contentLines = array_slice($contentLines, $overflow - $offset, $available);
        } else {
            while (count($contentLines) < $available) {
                $contentLines[] = '';
            }
        }

        $frame = implode("\n", $contentLines) . "\n" . self::renderStatusBar($chat);

        $palette = self::renderPalette($chat, $theme);
        if ($palette !== '') {
            // A fresh Veil per render call (rather than one persisted on
            // Chat) means its own frame-diffing never kicks in - fine here,
            // since Chat already does its own diffing at a higher level in
            // view() and double-diffing isn't needed for correctness.
            $frame = Veil::new()->withBackdrop(50)->composite($palette, $frame, Position::CENTER, Position::CENTER);
        }

        return self::scanRoot($frame, $chat->cols());
    }

    /**
     * The bottom status bar: the existing processing indicator/help text,
     * plus a context-usage percentage from {@see Chat::contextUsagePercent()}
     * so a user can see how full the context window is without running
     * /compact speculatively.
     */
    private static function renderStatusBar(Chat $chat): string
    {
        // The "Ctrl+P menu" hint is the live path's only affordance for
        // Pane::Menu (the palette is what the disconnected App system's
        // MenuBar pane would have been), so it is the region that carries
        // the `pane:menu` click zone — crush_feat.md §8 E3's "click the
        // pane's title region to jump straight to it". While a request is in
        // flight the hint is not drawn at all, so no zone is marked either.
        $processing = $chat->inFlight
            ? '⠴ thinking… · Esc Esc to cancel'
            : 'Enter to send · ' . self::markPane(Pane::Menu, 'Ctrl+P menu') . ' · /exit or ^C to quit';
        $percent = (int) round($chat->contextUsagePercent() * 100);
        $bar = "{$percent}% context · {$processing}";

        // The bar is the frame's LAST line, so it is the one line that must
        // never wrap: a wrapped bar makes the frame rows+1 physical rows tall,
        // which is precisely the absolute-cursorTo row collision render()'s
        // tail clip exists to prevent (renderDiff() guards the same
        // one-logical-line-per-row invariant with Width::truncate). The scroll
        // readout is therefore fitted to whatever room the bar leaves instead
        // of being prepended unconditionally — the bar is already ~62 columns,
        // and any transcript tall enough to scroll produces 2-3 digit offsets,
        // so the long form alone pushes it past 80 columns.
        //
        // "Fitted" means picking a narrower form or dropping it — never
        // truncating the assembled string. $bar carries markPane(Pane::Menu)'s
        // sentinel PAIR, and a cut between them leaves an unmatched open
        // marker, which makes Scan::parse() throw and costs the WHOLE frame
        // its click zones (same failure mode markPaneHeader() documents). The
        // sentinels are invisible on screen, so they come off before measuring.
        $room = $chat->cols() - Width::of(self::stripZoneMarkers($bar));
        foreach (self::scrollIndicators($chat) as $indicator) {
            if (Width::of($indicator) <= $room) {
                return $indicator . $bar;
            }
        }

        return $bar;
    }

    /**
     * "How far back am I?" readout, shown only while the transcript is
     * scrolled off the bottom (crush_feat.md §8 E4's scrollbar-during-scroll).
     *
     * A fixed-height frame has no spare column for crush's real scrollbar
     * gutter, and the frame is clipped to the terminal anyway, so the
     * position is reported as text on the status bar the frame already
     * reserves. It hides on the state that matters — being back at the
     * newest line — rather than on a timer: the offset persists until the
     * user scrolls back down, so a timed hide would blank the only clue
     * that the newest output is off-screen while it still is.
     *
     * Reads {@see maxScrollOffset()} rather than recomputing: this runs
     * inside {@see render()}, AFTER the window slice recorded this frame's
     * own overflow.
     *
     * Returns the candidate forms widest-first so {@see renderStatusBar()} can
     * take the most informative one the row still has room for; an empty list
     * means "not scrolled, draw nothing".
     *
     * @return list<string>
     */
    private static function scrollIndicators(Chat $chat): array
    {
        $max = self::maxScrollOffset();
        $offset = max(0, min($chat->scrollOffset(), $max));
        if ($offset === 0) {
            return [];
        }

        // The compact fallback keeps the number that actually matters — how
        // far back the window is — when the full "of how many" readout would
        // not fit. Losing the readout entirely on a narrow terminal would
        // leave no clue at all that the newest output is off-screen.
        return ["↑ {$offset}/{$max} scrolled · ", "↑{$offset} "];
    }

    /**
     * Render the agent status line + agent list pane, or '' when Chat has
     * no AgentManager or the manager has no active agents. See the "R20
     * wiring decision" note on this class's docblock for why the fields
     * beyond name/status/operation are 0 rather than fabricated, and why
     * AgentOutputPane / the split-pane renderer are not called here.
     */
    private static function renderAgentView(Chat $chat): string
    {
        $manager = $chat->agentManager();
        if ($manager === null) {
            return '';
        }

        $agents = $manager->active();
        if ($agents === []) {
            return '';
        }

        $states = array_map(self::agentDisplayState(...), $agents);

        $cols = $chat->cols();
        $width = max(40, $cols - 4);

        // The status bar's first row is this pane's header, so it carries the
        // `pane:agents` click zone (crush_feat.md §8 E3) — clicking it runs
        // the same /agents dispatch Ctrl+A does. See {@see markPaneHeader()}
        // for why the whole block is not marked.
        return self::markPaneHeader(Pane::Agents, AgentStatusBar::render($states))
            . "\n" . AgentViewPane::render($states, -1, $width, self::AGENT_VIEW_MAX_ROWS);
    }

    /**
     * Map a real registered {@see Agent} to the display-state shape
     * AgentStatusBar/AgentViewPane render. elapsedSeconds/tokensUsed/costUsd
     * are 0 — Chat's AgentManager/AgentWorkerPool accessors expose no
     * per-agent live telemetry to source real values from (see class
     * docblock); reporting 0 is honest, not fabricated.
     */
    private static function agentDisplayState(Agent $agent): AgentDisplayState
    {
        return AgentDisplayState::new(
            name: $agent->name,
            status: $agent->isActive ? 'working' : 'stopped',
            operation: $agent->description,
            elapsedSeconds: 0,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * Render a one-line session tab strip from real {@see Chat::sessionStore()}
     * rows, with the current session bracketed. Returns '' when there is no
     * session store or fewer than 2 sessions exist — a single session isn't
     * worth a tab strip, and {@see Chat}'s Ctrl+Tab handler is itself a no-op
     * below 2 sessions (see `Chat::cycleSessionTab()`). See the "R20 wiring
     * decision" note on this class's docblock for why `Tui\SessionTabs`
     * itself is not instantiated to build this strip.
     */
    private static function renderSessionTabStrip(Chat $chat): string
    {
        $store = $chat->sessionStore();
        if ($store === null) {
            return '';
        }

        $rows = $store->listSessions();
        if (count($rows) < 2) {
            return '';
        }

        $current = $chat->currentSessionId();
        $labels = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $rawName = (string) ($row['name'] ?? '');
            $name = $rawName !== '' ? $rawName : $id;
            $label = ($id !== '' && $id === $current) ? "[{$name}]" : " {$name} ";
            $labels[] = self::markSessionTab($id, $label);
        }

        return implode('|', $labels);
    }

    /**
     * Wrap one session tab label in a `tab:<id>` click zone (crush_feat.md
     * §8 E2), so the cells it lands on can be turned back into the session
     * they belong to by {@see Chat::update()}.
     *
     * Only the label is marked, never the `|` separators: a click on the gap
     * between two tabs is ambiguous, and leaving it unmarked makes it a no-op
     * rather than a coin flip.
     *
     * Marking is skipped entirely when clicks are off. That is not just an
     * optimisation of a dead feature: with no marker anywhere in the frame,
     * {@see scanRoot()} keeps its `str_contains()` fast path and skips the
     * ~24ms grapheme walk, so disabling the mouse also buys back the render
     * cost of supporting it.
     */
    private static function markSessionTab(string $id, string $label): string
    {
        $zoneId = self::SESSION_TAB_ZONE_PREFIX . $id;

        if (
            $id === ''
            || !Chat::mouseClicksEnabled()
            || preg_match(self::ZONE_ID_CHARSET, $id) !== 1
            || strlen($zoneId) > Mark::MAX_ID_BYTES
        ) {
            return $label;
        }

        return Mark::zone($zoneId, $label);
    }

    /**
     * Wrap one on-screen region in a `pane:<name>` click zone (crush_feat.md
     * §8 E3), so a click on it can be turned back into the {@see Pane} it
     * belongs to by {@see Chat::update()}.
     *
     * No id validation here, unlike {@see markSessionTab()}: a pane id is a
     * `Pane` case's own `value` (a lowercase ASCII literal in the enum), not
     * a string that arrived from disk, so it cannot fall outside
     * {@see Mark}'s charset and make `Mark::wrap()` throw inside `view()`.
     *
     * Marking is skipped when clicks are off, for the reason spelled out on
     * {@see markSessionTab()}: with no marker anywhere in the frame
     * {@see scanRoot()} keeps its `str_contains()` fast path.
     *
     * @param string $content MUST be a single line — see {@see markPaneHeader()}.
     */
    private static function markPane(Pane $pane, string $content): string
    {
        if ($content === '' || !Chat::mouseClicksEnabled()) {
            return $content;
        }

        return Mark::zone(self::PANE_ZONE_PREFIX . $pane->value, $content);
    }

    /**
     * Mark only the FIRST line of a multi-line block as the pane's zone —
     * its header row, which is the "title/border region" §8 E3 asks for.
     *
     * Deliberately not the whole block. {@see render()} clips `$content` to
     * the terminal's height by dropping leading LINES, so a zone spanning
     * several rows can lose its opening sentinel while the closing one
     * survives. That unmatched close makes {@see \SugarCraft\Mouse\Scan::parse()}
     * throw, and {@see scanRoot()} answers a throw by clearing the registry
     * — costing the WHOLE frame its zones, session tabs included. A
     * single-line zone is clipped whole or not at all, so it can never
     * desync.
     */
    private static function markPaneHeader(Pane $pane, string $block): string
    {
        $lines = explode("\n", $block);
        $lines[0] = self::markPane($pane, $lines[0]);

        return implode("\n", $lines);
    }

    /**
     * @param list<Message>       $history
     * @param int                 $width    usable columns inside the shell's border +
     *                                      padding, so nested boxes (tool diffs) can
     *                                      truncate rather than wrap into a second row
     * @param array<string, bool> $expanded {@see Chat::expanded()} - tool-call ids the
     *                                      user has expanded, keyed by id
     */
    private static function renderHistory(array $history, Theme $theme, int $width, array $expanded = []): string
    {
        if ($history === []) {
            return '_(empty conversation — type a question and press Enter)_';
        }
        $md = new Markdown($theme->markdown);
        $blocks = [];
        foreach ($history as $msg) {
            // Defense-in-depth (candy-buffer #1362): User and System content is
            // untrusted and reaches the terminal wire verbatim. A raw ESC would
            // desync the frame-diff line model or forge SGR that escapes the
            // renderer's own styling (e.g. a smuggled reset() breaking out of the
            // system FAINT wrapper); NUL/BEL/DEL garble or beep the terminal.
            // These turns are plain text with no legitimate SGR, so untrusted()
            // (full ANSI + C0/DEL/lone-C1 strip) is correct — the Assistant path
            // stays raw because CandyShine emits legitimate, already-processed SGR.
            if ($msg->toolResults !== []) {
                $blocks[] = self::renderToolResults($msg, $theme, $width, $expanded);

                continue;
            }
            if ($msg->pendingToolCallId !== null) {
                $blocks[] = self::renderPendingToolCall($msg, $theme);

                continue;
            }
            $blocks[] = match ($msg->role) {
                Role::User      => Style::new()->foreground($theme->userLabel)->bold()->render('user>') . " " . self::untrusted($msg->content),
                Role::Assistant => self::renderAssistantTurn($msg, $theme, $md),
                Role::System    => Style::new()->foreground($theme->systemLabel)->faint()->render("system: " . self::untrusted($msg->content)),
            };
        }
        return implode("\n\n", $blocks);
    }

    /**
     * An assistant turn's label + (when present) its {@see Message::$reasoning}
     * line + rendered Markdown body. §12 D3's final wiring step - the
     * extractor already splits reasoning out at the provider layer and
     * {@see \SugarCraft\Crush\Backend\EngineBackend} threads it onto the root
     * {@see Message} DTO; this is where it actually reaches the user instead
     * of being computed and discarded.
     */
    private static function renderAssistantTurn(Message $msg, Theme $theme, Markdown $md): string
    {
        $label = Style::new()->foreground($theme->assistantLabel)->bold()->render('assistant');
        // Sentinels stripped BEFORE CandyShine, not after: the rendered output
        // is legitimate SGR that untrusted() would destroy, but the model's
        // raw text can still smuggle U+E000/U+E001 into the frame.
        $body = trim($md->render(self::stripSentinels($msg->content)));

        if ($msg->reasoning === null || trim($msg->reasoning) === '') {
            return $label . "\n" . $body;
        }

        return $label . "\n" . self::renderReasoning($msg->reasoning, $theme) . "\n" . $body;
    }

    /**
     * Dimmed, single-line, collapsed rendering of a model's extracted
     * "thinking" text - per crush_feat.md §12 D3 ("surface the result
     * rendered dimmed/collapsed in the TUI"). Collapsed to one flattened,
     * truncated line rather than rendered in full: a MiniMax-M2.7 thinking
     * trace can run to thousands of tokens, and showing it verbatim would
     * push the actual answer off-screen turn after turn. Reasoning is raw
     * model output that never passes through CandyShine's Markdown renderer,
     * so - like every other untrusted turn in this method - it goes through
     * {@see Sanitize::untrusted()} before display.
     */
    private static function renderReasoning(string $reasoning, Theme $theme): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', self::untrusted($reasoning)) ?? '');
        if (mb_strlen($flat) > 120) {
            $flat = mb_substr($flat, 0, 120) . '…';
        }

        return Style::new()->foreground($theme->systemLabel)->faint()->render('💭 ' . $flat);
    }

    /**
     * A message carrying {@see ToolResult}s (see {@see Message::withToolResults()})
     * gets a distinct "🔧 tool" marker per result instead of the plain
     * assistant bubble {@see renderHistory()} uses for real replies -
     * otherwise a tool call is visually indistinguishable from the model's
     * own words, which is exactly what made tool execution look silent.
     *
     * A result that carries a unified diff ({@see ToolResult::hasDiff()} -
     * `Edit`/`Write` produce one, see `Tools\BuiltIn\Edit::unifiedDiff()`)
     * additionally gets that diff painted below the marker, per crush_feat.md
     * §1 E3. The diff is consumed verbatim from the result; it is never
     * recomputed here, because the renderer has neither the pre-edit file
     * contents nor any business touching the filesystem.
     *
     * Bodies are no longer dumped in full forever (crush_feat.md §1 E5). A
     * SUCCESSFUL result is the case where the output is least likely to be
     * worth screen space - the model already read it, and the user mostly
     * needs to know the call happened - so its body is hidden entirely until
     * the tool-call id appears in $expanded ({@see Chat::toggleToolOutput()},
     * Ctrl+O). An ERROR body is never hidden, because that is precisely the
     * output the user is looking for, but it is still clipped through
     * {@see collapseToolOutput()} so a multi-megabyte stderr can't evict the
     * conversation it belongs to. Expanding shows the body verbatim.
     *
     * @param array<string, bool> $expanded {@see Chat::expanded()}
     */
    private static function renderToolResults(Message $msg, Theme $theme, int $width, array $expanded = []): string
    {
        $lines = [];
        foreach ($msg->toolResults as $result) {
            // The name is model-chosen, not ours: Chat::executeToolCall() copies
            // it verbatim off the parsed tool call, so an unknown-tool reply can
            // carry an OSC title-set or a screen-clear. Unlike assistant
            // Markdown it has no legitimate SGR of its own, so it takes the full
            // {@see untrusted()} scrub rather than only the sentinel strip.
            $status = $result->isError()
                ? Style::new()->foreground($theme->systemLabel)->bold()->render('✗ error')
                : Style::new()->foreground($theme->assistantLabel)->bold()->render('✓ ok');
            $label = Style::new()->foreground($theme->systemLabel)->faint()->render('🔧 tool: ' . self::untrusted($result->name)) . ' ' . $status;
            $body = self::untrusted($result->isError() ? ($result->error ?? '') : $result->result);
            $isExpanded = ($expanded[$result->id ?? $result->name] ?? false) === true;

            $block = $label;
            if ($body !== '') {
                $block .= "\n" . self::renderToolBody($body, $result->isError(), $isExpanded, $theme);
            }

            if ($result->hasDiff()) {
                $block .= "\n" . self::renderDiff((string) $result->diff, $theme, $width);
            }

            $lines[] = $block;
        }

        return implode("\n\n", $lines);
    }

    /**
     * One tool result's body under the collapse/expand policy documented on
     * {@see renderToolResults()}: verbatim when expanded, a faint one-line
     * "N lines hidden" affordance when a successful call is collapsed, and a
     * {@see collapseToolOutput()}-clipped excerpt (plus trailer) when a failed
     * call is collapsed.
     */
    private static function renderToolBody(string $body, bool $isError, bool $isExpanded, Theme $theme): string
    {
        if ($isExpanded) {
            return $body;
        }

        if (!$isError) {
            $count = substr_count($body, "\n") + 1;
            $hint = "… {$count} line" . ($count === 1 ? '' : 's') . ' hidden (ctrl+o)';

            return Style::new()->foreground($theme->systemLabel)->faint()->render($hint);
        }

        $collapsed = self::collapseToolOutput($body, self::TOOL_OUTPUT_MAX_LINES, self::TOOL_OUTPUT_MAX_CHARS);
        if (!$collapsed['overflow']) {
            return $collapsed['output'];
        }

        return $collapsed['output'] . "\n"
            . Style::new()->foreground($theme->systemLabel)->faint()->render('… output truncated (ctrl+o to expand)');
    }

    /**
     * Clip a tool's raw output to at most $maxLines lines AND $maxChars
     * characters, reporting whether anything was dropped (crush_feat.md
     * §1 E5).
     *
     * Deliberately a pure function over plain strings - no Theme, no Style,
     * no Chat - so the clipping policy is unit-testable on its own and can be
     * reused by any other surface that has to show tool output.
     *
     * The line budget is applied before the character budget so a result that
     * is short in lines but enormous in one of them still gets clipped; both
     * limits set `overflow`. Character counting is mb_*-based because tool
     * output is arbitrary UTF-8 and a byte-wise cut could split a codepoint
     * and put a lone continuation byte on the terminal wire.
     *
     * @param int $maxLines Maximum lines to keep; values below 1 are treated as 1
     * @param int $maxChars Maximum characters to keep; values below 1 are treated as 1
     *
     * @return array{output: string, overflow: bool}
     */
    public static function collapseToolOutput(string $output, int $maxLines, int $maxChars): array
    {
        if ($output === '') {
            return ['output' => '', 'overflow' => false];
        }

        $maxLines = max(1, $maxLines);
        $maxChars = max(1, $maxChars);

        $overflow = false;
        $rows = preg_split('/\r\n|\r|\n/', $output) ?: [];
        if (count($rows) > $maxLines) {
            $rows = array_slice($rows, 0, $maxLines);
            $overflow = true;
        }

        $clipped = implode("\n", $rows);
        if (mb_strlen($clipped) > $maxChars) {
            $clipped = mb_substr($clipped, 0, $maxChars);
            $overflow = true;
        }

        return ['output' => $clipped, 'overflow' => $overflow];
    }

    /**
     * Paint a raw unified diff (`--- a/…` / `+++ b/…` / `@@ … @@` / ` `+`/`-`
     * lines, exactly what `diff -u` emits) as a bordered, colour-coded block.
     *
     * Additions/removals are coloured with bare ANSI green/red rather than a
     * {@see Theme} field: every theme in the palette agrees on what "added"
     * and "removed" look like, and the diff has to stay readable even under
     * the `ansi` theme, which has no room for two more accent colours.
     *
     * Every line is {@see Sanitize::untrusted()}-stripped before display -
     * diff bodies are verbatim file contents, so an edited file containing a
     * raw ESC would otherwise forge SGR straight onto the terminal wire - then
     * hard-truncated to $width so the frame keeps its one-logical-line-per-row
     * invariant (candy-core's Renderer repaints by absolute row; a wrapped
     * line silently shifts every row below it). The row count is capped at
     * {@see self::DIFF_MAX_ROWS} with a trailer for the same reason
     * {@see render()} tail-clips: a 400-line diff must not evict the
     * conversation it belongs to.
     */
    private static function renderDiff(string $diff, Theme $theme, int $width): string
    {
        // Border (2 cols) + padding(0, 1) (2 cols) sit outside the text.
        $inner = max(8, $width - 4);

        $rows = preg_split('/\r\n|\r|\n/', rtrim($diff, "\r\n")) ?: [];
        $overflow = count($rows) - self::DIFF_MAX_ROWS;
        if ($overflow > 0) {
            $rows = array_slice($rows, 0, self::DIFF_MAX_ROWS);
        }

        $painted = [];
        foreach ($rows as $row) {
            $text = Width::truncate(self::untrusted($row), $inner);
            $painted[] = self::styleDiffLine($text, $theme)->render($text);
        }

        if ($overflow > 0) {
            $trailer = Width::truncate("… {$overflow} more diff line" . ($overflow === 1 ? '' : 's'), $inner);
            $painted[] = Style::new()->foreground($theme->systemLabel)->faint()->render($trailer);
        }

        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render(implode("\n", $painted));
    }

    /**
     * Pick the {@see Style} for one unified-diff line from its marker column.
     * The `---`/`+++` file headers are matched before the bare `-`/`+` markers
     * they start with, otherwise a diff's own header would render as a giant
     * removal followed by a giant addition.
     */
    private static function styleDiffLine(string $line, Theme $theme): Style
    {
        if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
            return Style::new()->foreground($theme->systemLabel)->bold();
        }
        if (str_starts_with($line, '@@')) {
            return Style::new()->foreground(Color::ansi(6));
        }
        if (str_starts_with($line, '+')) {
            return Style::new()->foreground(Color::ansi(2));
        }
        if (str_starts_with($line, '-')) {
            return Style::new()->foreground(Color::ansi(1));
        }

        return Style::new()->foreground($theme->systemLabel)->faint();
    }

    /**
     * A "tool X is running" placeholder (see {@see Message::toolRunning()}) -
     * shown the moment a tool call is dispatched, before it finishes, so a
     * slow command doesn't look like nothing is happening. Replaced in
     * history with {@see renderToolResults()}'s finished marker once the
     * real result arrives (see Chat's ToolResultsMsg handling).
     */
    private static function renderPendingToolCall(Message $msg, Theme $theme): string
    {
        $spinner = Style::new()->foreground($theme->assistantLabel)->render('⠴');

        return $spinner . ' ' . Style::new()->foreground($theme->systemLabel)->faint()->render('running: ' . self::untrusted($msg->content));
    }

    /**
     * The "/" popup: {@see Chat::slashMenuMatches()}'s filtered command list,
     * with the highlighted row ({@see Chat::slashMenuIndex()}) marked with
     * "▸" and rendered brighter than the rest. Returns '' (nothing rendered)
     * once matches is empty - inputBuf isn't slash-prefixed, already
     * contains a space, or the typed prefix matches no command.
     */
    private static function renderSlashMenu(Chat $chat, Theme $theme): string
    {
        $matches = $chat->slashMenuMatches();
        if ($matches === []) {
            return '';
        }

        $selected = $chat->slashMenuIndex();
        $lines = [];
        foreach ($matches as $index => $spec) {
            $label = '/' . $spec->name . ' — ' . $spec->description;
            $lines[] = $index === $selected
                ? Style::new()->foreground($theme->userLabel)->bold()->render('▸ ' . $label)
                : Style::new()->foreground($theme->systemLabel)->faint()->render('  ' . $label);
        }

        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render(implode("\n", $lines));
    }

    /**
     * The Ctrl+P command palette's content, composited over the whole frame
     * by {@see render()} via {@see Veil}. Returns '' (nothing composited)
     * when the palette is closed - see {@see Chat::palette()}.
     *
     * Rows come from {@see Chat::paletteMatchResults()} rather than the bare
     * label list so the matched characters can be highlighted through
     * {@see Highlighter} (crush_feat.md §4 E3). With no query typed, the root
     * list arrives category-grouped and MRU-biased and gets a faint header
     * per category (§4 E6/E7); a typed query stays a flat relevance-ranked
     * list, headers omitted, so the best match is always the first row.
     */
    private static function renderPalette(Chat $chat, Theme $theme): string
    {
        $palette = $chat->palette();
        if ($palette === null) {
            return '';
        }

        $results = $chat->paletteMatchResults();
        $selected = $palette->selectedIndex;
        $grouped = $palette->query === '' && $palette->mode !== 'providers' && $palette->mode !== 'themes';

        $lines = ['🔍 ' . self::untrusted($palette->query) . '█', ''];
        if ($results === []) {
            $lines[] = Style::new()->foreground($theme->systemLabel)->faint()->render('No matches');
        } else {
            $highlighter = new Highlighter();
            // Underlined as well as recoloured: the selected row is already
            // bold userLabel, so colour alone would make its matched run
            // indistinguishable from the rest of the row.
            $matchStyle = Style::new()->foreground($theme->userLabel)->bold()->underline();
            $lastCategory = null;

            foreach ($results as $index => $result) {
                if ($grouped) {
                    $category = $chat->paletteCategory($result->haystack);
                    if ($category !== null && $category !== $lastCategory) {
                        $lines[] = Style::new()->foreground($theme->systemLabel)->faint()->render($category);
                        $lastCategory = $category;
                    }
                }

                $rowStyle = $index === $selected
                    ? Style::new()->foreground($theme->userLabel)->bold()
                    : Style::new()->foreground($theme->systemLabel);
                $reopen = self::sgrOpen($rowStyle);
                $body = $highlighter->highlight(
                    $result,
                    static fn(string $run): string => $matchStyle->render($run) . $reopen,
                );

                $lines[] = $rowStyle->render(($index === $selected ? '▸ ' : '  ') . $body);
            }
        }

        $title = match ($palette->mode) {
            'providers' => ' switch model ',
            'themes' => ' switch theme ',
            default => ' command palette ',
        };

        return Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->width(50)
            ->render(implode("\n", $lines));
    }

    /**
     * The opening SGR sequence of a style, with no text and no trailing
     * reset. Needed because {@see Style::render()} terminates every run with
     * a full reset: a highlighted run nested inside a coloured palette row
     * would otherwise strip the row's own colour off everything after it.
     * Re-emitting this after each highlighted run restores the row style.
     */
    private static function sgrOpen(Style $style): string
    {
        $rendered = $style->render('');
        $reset = "\x1b[0m";

        return str_ends_with($rendered, $reset)
            ? substr($rendered, 0, -strlen($reset))
            : $rendered;
    }

    private static function renderInput(Chat $chat, Theme $theme): string
    {
        $cursor = $chat->inFlight ? '' : '█';
        // The in-progress input buffer is untrusted keystroke data (e.g. a
        // bracketed-paste dump can smuggle ESC/C0/DEL). Strip it before it hits
        // the terminal so a paste can't inject control sequences at draw time.
        $body = "> " . self::untrusted($chat->inputBuf) . $cursor;
        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render($body);
    }
}
