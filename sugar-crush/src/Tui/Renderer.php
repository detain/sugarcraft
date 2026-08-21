<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View;
use SugarCraft\Sprinkles\Bar\Segment;
use SugarCraft\Sprinkles\Bar\StatusBar as BarStatusBar;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Components\ChatPane;
use SugarCraft\Crush\Tui\Components\InputPane;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;
use SugarCraft\Crush\Tui\Components\AgentSplitColumn;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\SettingsPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Chat;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;

/**
 * Stateless renderer for the sugar-crush TUI shell.
 * Composes multiple panes into a full terminal interface.
 * Pure function - given the same App it always produces the same bytes.
 *
 * This is the body of {@see App::view()} (crush_feat.md §5 E7, merge branch).
 * It renders the SHELL only — menu bar, sidebars, input line, status bar —
 * and the chat pane it lays out delegates to the live
 * {@see \SugarCraft\Crush\Renderer} against the App's hosted `Chat`, so
 * transcript content has exactly one implementation. `bin/sugarcrush` now
 * boots this shell: {@see \SugarCraft\Crush\Cli\Bootstrap::app()} is the
 * production constructor of an App with a hosted chat.
 */
final class Renderer
{
    /** The one-cell divider {@see SplitLayout} draws between the two panes. */
    private const SPLIT_DIVIDER_COLS = 1;

    /**
     * Narrowest terminal that gets a split content band at all.
     *
     * 80 is not a round number picked for looks, it is the smallest width at
     * which BOTH panes clear their own floors:
     * `intdiv(80, 3) = 26` for the agent column (over
     * {@see SPLIT_MIN_AGENT_COLS}) leaves `80 - 26 - 1 = 53` for the shell
     * band (over {@see SPLIT_MIN_BAND_COLS}). Below it the split is simply not
     * offered and the frame is the single-band frame it has always been.
     */
    private const SPLIT_MIN_TOTAL_COLS = 80;

    /**
     * Narrowest agent column worth drawing, INCLUDING
     * {@see AgentSplitColumn::PANE_CHROME} — so the tile inside it still gets
     * 20 cells of text.
     */
    private const SPLIT_MIN_AGENT_COLS = 24;

    /**
     * Widest the agent column grows to, however wide the terminal. A peek tile
     * is a header plus four lines; past this it is whitespace bought with the
     * transcript's columns.
     */
    private const SPLIT_MAX_AGENT_COLS = 60;

    /** The agent column's share of the terminal: one part in this many. */
    private const SPLIT_AGENT_DIVISOR = 3;

    /**
     * Narrowest shell band that still holds a sidebar plus a usable chat
     * column: {@see leftSidebar()}'s own floor of 20 plus the 24 the chat pane
     * is floored at below. A band under this is why {@see agentSplitWidth()}
     * can decline a split on a terminal that cleared
     * {@see SPLIT_MIN_TOTAL_COLS}.
     */
    private const SPLIT_MIN_BAND_COLS = 44;

    /**
     * Render two panes in a split layout.
     *
     * This is the in-process split pane renderer: composes multiple pane
     * contents into a tmux-like layout without external dependencies.
     * Panes expand proportionally on resize based on the SplitLayout's
     * stored ratio.
     *
     * @param string        $topOrLeft    Content of the first pane.
     * @param string        $bottomOrRight Content of the second pane.
     * @param SplitDirection $direction   Split orientation (horizontal/vertical).
     * @param int           $topOrLeftNumerator   Proportion of first pane (default 1).
     * @param int           $totalDenominator    Total proportion units (default 2).
     * @param int           $cols  Authoritative width; 0 falls back to {@see getTerminalSize()}.
     * @param int           $rows  Authoritative height; 0 falls back to {@see getTerminalSize()}.
     * @return string Rendered split layout with divider.
     *
     * @see SplitLayout for proportional sizing and resize behavior.
     */
    public static function renderWithSplit(
        string $topOrLeft,
        string $bottomOrRight,
        SplitDirection $direction,
        int $topOrLeftNumerator = 1,
        int $totalDenominator = 2,
        int $cols = 0,
        int $rows = 0,
    ): string {
        $layout = new SplitLayout(
            $topOrLeft,
            $bottomOrRight,
            $direction,
            $topOrLeftNumerator,
            $totalDenominator,
        );

        // The caller's size WINS when it has one. `getTerminalSize()` is a
        // one-shot probe cached in a never-invalidated static, so making it the
        // only source lets this split render at a stale geometry after a
        // SIGWINCH while the frame around it renders at the `WindowSizeMsg`
        // one — invariant 2 on {@see renderView()}, which is why the size is
        // threaded here at all.
        if ($cols <= 0 || $rows <= 0) {
            $size = self::getTerminalSize();
            $cols = $cols > 0 ? $cols : $size['cols'];
            $rows = $rows > 0 ? $rows : $size['rows'];
        }

        return $layout->render($cols, $rows);
    }

    /**
     * Render two panes in a split layout, routing to multiplexer or in-process.
     *
     * This method detects whether a terminal multiplexer (TMUX or iTerm2)
     * is active in the current environment and routes accordingly:
     *
     * - When multiplexer is active: delegates to MultiplexerSplitPane for
     *   potential native multiplexer rendering (currently falls back to
     *   in-process when native integration is unavailable).
     *
     * - When no multiplexer: uses the in-process SplitLayout renderer
     *   directly (same as renderWithSplit).
     *
     * This is the preferred entry point for split pane rendering as it
     * automatically adapts to the execution environment.
     *
     * THE DIVERGENCE THIS FIXED WAS SIZE, NOT PROPORTIONS. Before Phase 8
     * item 4 this method's whole signature was
     * `(string, string, SplitDirection, int $cols = 0, int $rows = 0)` — there
     * were no proportion parameters to drop, and both branches rendered 1/2
     * because that is {@see SplitLayout}'s constructor default. What the
     * no-multiplexer branch DID drop was the two parameters that existed: it
     * called a {@see renderWithSplit()} that took no size at all, so it
     * re-probed {@see getTerminalSize()} while the multiplexer branch used the
     * caller's `$cols`/`$rows`. The identical call therefore rendered at one
     * geometry inside tmux and another outside it. Both branches now take all
     * four, and the proportions are new here rather than restored.
     *
     * That was invisible while nothing called this; it is the first thing
     * {@see renderView()}'s compositor hit.
     *
     * @param string         $topOrLeft    Content of the first pane.
     * @param string         $bottomOrRight Content of the second pane.
     * @param SplitDirection $direction   Split orientation.
     * @param int            $cols         Available columns (0 = probe the terminal).
     * @param int            $rows         Available rows (0 = probe the terminal).
     * @param int            $topOrLeftNumerator Proportion of the first pane.
     * @param int            $totalDenominator   Total proportion units.
     * @return string Rendered split layout with divider.
     *
     * @see MultiplexerSplitPane::isActive()
     * @see SplitLayout for the in-process implementation.
     */
    public static function renderForCurrentEnvironment(
        string $topOrLeft,
        string $bottomOrRight,
        SplitDirection $direction,
        int $cols = 0,
        int $rows = 0,
        int $topOrLeftNumerator = 1,
        int $totalDenominator = 2,
    ): string {
        $multiplexer = new MultiplexerSplitPane();

        if ($multiplexer->isActive()) {
            if ($cols <= 0 || $rows <= 0) {
                $size = self::getTerminalSize();
                $cols = $cols > 0 ? $cols : $size['cols'];
                $rows = $rows > 0 ? $rows : $size['rows'];
            }

            return $multiplexer->renderWithMultiplexer(
                $topOrLeft,
                $bottomOrRight,
                $direction,
                $cols,
                $rows,
                $topOrLeftNumerator,
                $totalDenominator,
            );
        }

        // No multiplexer - use in-process renderer directly
        return self::renderWithSplit(
            $topOrLeft,
            $bottomOrRight,
            $direction,
            $topOrLeftNumerator,
            $totalDenominator,
            $cols,
            $rows,
        );
    }

    private static ?array $terminalSize = null;

    public static function setSize(int $cols, int $rows): void
    {
        if ($cols > 0 && $rows > 0) {
            self::$terminalSize = ['rows' => $rows, 'cols' => $cols];
        }
    }

    public static function getTerminalSize(): array
    {
        if (self::$terminalSize !== null) {
            return self::$terminalSize;
        }

        try {
            $size = (new Tty(STDOUT))->size();
            if ($size['cols'] > 0 && $size['rows'] > 0) {
                self::$terminalSize = ['rows' => $size['rows'], 'cols' => $size['cols']];
                return self::$terminalSize;
            }
        } catch (\Throwable) {
            // Terminal size detection failed; fall back to defaults.
        }

        self::$terminalSize = ['rows' => 60, 'cols' => 200];
        return self::$terminalSize;
    }

    public static function resetSizeCache(): void
    {
        self::$terminalSize = null;
    }

    /**
     * Zone registry for the shell CHROME — today the menu bar's titles and
     * the open menu's dropdown rows (crush_feat.md §8, whose E-list wired
     * session tabs, panes, tool rows and the palette but never the bar).
     *
     * Deliberately NOT the hosted chat's registry
     * ({@see \SugarCraft\Crush\Renderer::scanner()}). The two live in
     * different coordinate spaces: chat zones are recorded against the chat's
     * own sub-frame and {@see Chat::zoneAt()} re-bases a pointer report by
     * {@see \SugarCraft\Crush\Renderer::zoneOrigin()} before hit-testing,
     * while the menu bar is chrome ABOVE that origin — its zones are
     * frame-absolute, which is the space a terminal mouse report already
     * arrives in. Sharing one registry would mean one of the two sets was
     * always off by the pane inset.
     *
     * Shared across renders for the same reason the chat's is: a mouse event
     * arrives BETWEEN frames, so the only boxes a click can be tested
     * against are the ones the frame currently on screen recorded.
     */
    private static ?Scanner $chromeScanner = null;

    /** @see $chromeScanner */
    public static function chromeScanner(): Scanner
    {
        return self::$chromeScanner ??= Scanner::new();
    }

    /**
     * The chrome zone under a reported pointer cell, or null when there is
     * none.
     *
     * `$col`/`$row` are terminal-absolute and are used as-is: the chrome is
     * drawn by this class directly into the frame it composes, so the frame
     * IS the terminal. Contrast {@see Chat::zoneAt()}, which must subtract
     * the pane origin first.
     *
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS` is honoured through the same
     * {@see Chat::mouseClicksEnabled()} switch every other hit test reads, so
     * the escape hatch stays a single decision rather than one per surface.
     */
    public static function chromeZoneAt(int $col, int $row): ?Zone
    {
        if (!Chat::mouseClicksEnabled()) {
            return null;
        }

        return self::chromeScanner()->hit($col, $row);
    }

    /**
     * Record the chrome's click zones for the frame just composed.
     *
     * Scans a SCRATCH copy of the bar (and, when it is painted, the dropdown
     * panel padded to the column it is spliced at) rather than the frame
     * itself: the frame must reach the terminal marker-free, and the marked
     * copy exists only to be measured. Both copies come from the same layout
     * arithmetic ({@see MenuBar::compose()}), so a column measured here is
     * the column the title is painted at.
     *
     * The registry is CLEARED rather than populated when the frame lost rows
     * off the top — `clipTail` keeps the bottom, so a dropped row means the
     * bar is not on screen at all and a stale box would make dead cells
     * clickable — and when clicks are disabled, where a registry nothing may
     * read is pure waste.
     *
     * @param int $dropped   Rows `clipTail` removed from the top of the frame.
     * @param int $frameRows Height of the composed frame; panel rows past it
     *                       were never painted (see {@see overlayDropdown()},
     *                       which stops at the same edge).
     */
    private static function scanChrome(
        App $a,
        int $cols,
        string $menuBar,
        int $dropped,
        int $frameRows,
        bool $withDropdown,
    ): void {
        if ($dropped > 0 || !Chat::mouseClicksEnabled()) {
            self::chromeScanner()->clear();

            return;
        }

        $lines = explode("\n", MenuBar::renderMarked($a, $cols));

        if ($withDropdown) {
            $col = MenuBar::activeMenuColumn();
            $top = self::lineCount($menuBar);
            foreach (MenuBar::renderDropdownMarked($a->theme()) as $i => $panelLine) {
                if ($top + $i >= $frameRows) {
                    break;
                }
                $lines[$top + $i] = str_repeat(' ', $col) . $panelLine;
            }
        }

        try {
            self::chromeScanner()->scan(implode("\n", $lines), $cols);
        } catch (\Throwable) {
            // Same trade as the chat's root scan: a malformed marker costs an
            // unclickable bar, never a crash out of view().
            self::chromeScanner()->clear();
        }
    }

    /**
     * The shell frame's text bytes only.
     *
     * @param ?int $cols Authoritative terminal width (from `WindowSizeMsg`);
     *                   null falls back to {@see getTerminalSize()}.
     * @param ?int $rows @see $cols
     */
    public static function render(App $a, ?int $cols = null, ?int $rows = null): string
    {
        return self::renderView($a, $cols, $rows)->body;
    }

    /**
     * The shell frame plus the hosted chat's pixel-graphics layer.
     *
     * Three invariants this method owns, the first two re-learned the hard way
     * on the live path (PR #1403) and just as binding here:
     *
     * 1. The frame is CLIPPED to `$rows`, never merely padded. candy-core's
     *    `Renderer` repaints with an ABSOLUTE `cursorTo($row, 1)` and has no
     *    concept of scrolling, so every line past the terminal's last row is
     *    silently clamped onto it and distinct logical rows collide on one
     *    physical line. Everything below is therefore laid out against a row
     *    BUDGET (chrome first, whatever is left to the panes), and the tail
     *    clip at the end is the backstop for a terminal too short to hold even
     *    the chrome — it keeps the bottom, which is where the input line and
     *    status bar live.
     * 2. `$cols`/`$rows` come from the caller — {@see App::view()} passes the
     *    size the App recorded from `WindowSizeMsg`, the one size candy-core's
     *    `Program` actually dispatches and re-dispatches on SIGWINCH.
     *    {@see getTerminalSize()} is only the no-WindowSizeMsg-yet fallback: it
     *    is a one-shot probe cached in a never-invalidated static, so making it
     *    the primary path lets the shell chrome and the hosted chat render at
     *    two disagreeing geometries after a live resize.
     * 3. Every line is CLIPPED to `$cols` too. candy-core's renderer does no
     *    wrapping, so an over-wide line is wrapped by the TERMINAL, every later
     *    row shifts down one, and the absolute `cursorTo()` repaint
     *    desynchronises exactly as an over-tall frame does. The panes are sized
     *    exactly, but the chrome was not: {@see MenuBar::render()} drew a
     *    ~124-cell menu + tab strip whatever the terminal, so every terminal
     *    narrower than that — 80 and 100 columns included — overflowed on the
     *    frame's very first line. The bar now takes `$cols` and sheds menu
     *    names to fit; {@see clipWidth()} is the backstop for anything else
     *    that mis-sizes itself.
     * 4. The hosted chat's mouse zones are re-based onto the terminal before
     *    this returns. `Chat` scans its OWN frame for click zones, and here
     *    that frame is a sub-frame inside the chat pane's box; mouse reports
     *    stay terminal-absolute, so the offset has to be handed back or every
     *    hosted click hit-tests against the wrong cell. It is computed from
     *    the same measurements the layout above already made, after the row
     *    clip, because that clip can slide the whole frame up.
     */
    public static function renderView(App $a, ?int $cols = null, ?int $rows = null): View
    {
        if ($cols === null || $rows === null || $cols <= 0 || $rows <= 0) {
            $size = self::getTerminalSize();
            $cols = ($cols !== null && $cols > 0) ? $cols : $size['cols'];
            $rows = ($rows !== null && $rows > 0) ? $rows : $size['rows'];
        }

        $menuBar = MenuBar::render($a, $cols);

        // A hosted Chat is a FULL content model: it draws its own input box and
        // its own status bar (context usage, spinner, key hints), both of which
        // carry the Wave 1/2 features the shell's placeholder InputPane and
        // provider/model bar do not. Rendering the shell's copies alongside them
        // would put two input boxes and two status bars in one frame, so the
        // shell stands down and only keeps a one-line notice for the shell-level
        // error/status text the content model has no way to show.
        $hosted = $a->chat !== null;
        $notice = $hosted ? self::hostedNotice($a) : '';
        $bottom = $hosted ? '' : InputPane::render($a, $cols) . "\n" . self::statusBar($a);

        $chrome = self::lineCount($menuBar)
            + ($notice === '' ? 0 : self::lineCount($notice))
            + ($bottom === '' ? 0 : self::lineCount($bottom));
        $paneRows = max(3, $rows - $chrome);

        // Pane::Agents is a FULL-pane view (crush_feat.md §5 E5): the whole
        // content band becomes the dashboard, no sidebars and no chat column.
        // A dashboard squeezed into a quarter-width sidebar cannot show the
        // status/operation/elapsed/usage columns it exists to show.
        if ($a->pane === Pane::Agents) {
            return self::renderAgentDashboard($a, $cols, $rows, $menuBar, $notice, $bottom, $paneRows);
        }

        // The split-pane compositor's activation decision, taken BEFORE the
        // band is laid out because it changes the band's width. See
        // {@see agentSplitWidth()} for the policy; 0 means "no split", and
        // every measurement below then reduces to what it was before.
        $liveAgents = self::liveAgentOutputs($a);
        $agentCols = self::agentSplitWidth($liveAgents, $cols);
        $bandCols = $agentCols > 0 ? $cols - $agentCols - self::SPLIT_DIVIDER_COLS : $cols;

        $leftPane = self::leftSidebar($a, $bandCols, $paneRows);
        $rightPane = self::rightSidebar($a, $bandCols, $paneRows);

        // The chat pane gets the columns actually LEFT OVER, measured from the
        // rendered sidebars. The old `$cols - 80` guess assumed two 40-column
        // sidebars; the real ones are a quarter of the terminal each and the
        // right one is usually absent, so on a 120-column terminal it handed
        // the pane 40 columns out of an available 86 and then truncated
        // everything wider than 40 to fit.
        $paneCols = max(24, $bandCols - self::blockWidth($leftPane) - self::blockWidth($rightPane));

        [$chatPane, $images] = ChatPane::renderView($a, $paneCols, $paneRows);

        // A sidebar taller than the pane budget would push the bottom chrome
        // off-screen, so the joined band is held to the budget as well.
        $middle = self::clipHead(
            Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane),
            $paneRows,
        );

        if ($agentCols > 0) {
            $middle = self::composeAgentSplit($a, $liveAgents, $middle, $bandCols, $agentCols, $cols, $paneRows);
        }

        $parts = [$menuBar];
        if ($notice !== '') {
            $parts[] = $notice;
        }
        $parts[] = $middle;
        if ($bottom !== '') {
            $parts[] = $bottom;
        }

        $joined = implode("\n", $parts);
        $frame = self::clipWidth(self::clipTail($joined, $rows), $cols);

        // The open menu's dropdown floats OVER the composed frame rather than
        // taking part in the layout above, so opening a menu cannot reflow the
        // panes underneath it. Applied after the clips so it is never the thing
        // that gets trimmed away.
        $frame = self::overlayDropdown($frame, MenuBar::renderDropdown($a->theme()), MenuBar::activeMenuColumn(), self::lineCount($menuBar));

        $dropped = self::lineCount($joined) - self::lineCount($frame);

        // The bar's own click zones, recorded against the frame that was just
        // composed — the menu bar is chrome, so this is the terminal's own
        // coordinate space and NOT the pane-local space declared below.
        self::scanChrome($a, $cols, $menuBar, $dropped, self::lineCount($frame), true);

        // The composite is final, so the hosted chat's zone registry — recorded
        // against the chat's own body, one nesting level down — can be told
        // where that body ended up on the terminal. Rows lost to the tail clip
        // are subtracted because clipTail drops from the TOP, sliding
        // everything below it up by that many rows.
        LiveRenderer::setZoneOrigin(
            self::blockWidth($leftPane) + ChatPane::BODY_COL_INSET,
            self::lineCount($menuBar)
                + ($notice === '' ? 0 : self::lineCount($notice))
                + ChatPane::BODY_ROW_INSET
                - $dropped,
        );

        return new View($frame, images: $images);
    }

    /**
     * The {@see Pane::Agents} frame: shell chrome around the full-width
     * {@see AgentDashboardPane}.
     *
     * Shares {@see renderView()}'s clipping discipline exactly — the frame is
     * held to `$rows` and every line to `$cols`, for the reasons enumerated on
     * that method.
     *
     * The hosted chat's click zones are DROPPED here rather than re-based.
     * `Chat` records zones by scanning its own frame, and this frame does not
     * contain that frame: leaving the registry populated would leave the
     * previous chat frame's boxes hit-testable underneath a dashboard that
     * never drew them, so a click on an agent row would fire whatever chat
     * action last occupied that cell. No zones is the honest state — the
     * dashboard is keyboard-driven (Alt+1..9, Space, Enter, q).
     */
    private static function renderAgentDashboard(
        App $a,
        int $cols,
        int $rows,
        string $menuBar,
        string $notice,
        string $bottom,
        int $paneRows,
    ): View {
        $dashboard = self::clipHead(
            AgentDashboardPane::render($a, $cols, $paneRows),
            $paneRows,
        );

        $parts = [$menuBar];
        if ($notice !== '') {
            $parts[] = $notice;
        }
        $parts[] = $dashboard;
        if ($bottom !== '') {
            $parts[] = $bottom;
        }

        $joined = implode("\n", $parts);
        $frame = self::clipWidth(self::clipTail($joined, $rows), $cols);

        LiveRenderer::clearZones();

        // The dashboard drops the hosted chat's zones (above) but keeps the
        // bar's: this frame DOES paint the menu titles, so a click on one has
        // to work here too. No dropdown — this path never overlays one.
        self::scanChrome(
            $a,
            $cols,
            $menuBar,
            self::lineCount($joined) - self::lineCount($frame),
            self::lineCount($frame),
            false,
        );

        return new View($frame);
    }

    /**
     * The shell's error/status text as a single line, or '' when there is
     * neither.
     *
     * Only rendered while a Chat is hosted, where the shell's own status bar is
     * stood down: dropping the bar must not silently swallow an engine-level
     * error, but a permanently-present second bar is exactly the duplication
     * standing it down was for.
     */
    private static function hostedNotice(App $a): string
    {
        $theme = $a->theme();

        if ($a->error !== null && $a->error !== '') {
            return Style::new()->foreground($theme->shellError)->bold()
                ->render(' error: ' . $a->error);
        }

        if ($a->status !== null && $a->status !== '') {
            return Style::new()->foreground($theme->shellSuccess)->render(' ' . $a->status);
        }

        return '';
    }

    /** Visible width of the widest line in a rendered block ('' is 0 wide). */
    private static function blockWidth(string $block): int
    {
        if ($block === '') {
            return 0;
        }

        $widest = 0;
        foreach (explode("\n", $block) as $line) {
            $widest = max($widest, Width::string($line));
        }

        return $widest;
    }

    private static function lineCount(string $block): int
    {
        return substr_count($block, "\n") + 1;
    }

    /** Keep at most $rows lines from the TOP of $block. */
    private static function clipHead(string $block, int $rows): string
    {
        $lines = explode("\n", $block);

        return count($lines) <= $rows ? $block : implode("\n", array_slice($lines, 0, $rows));
    }

    /**
     * Keep at most $cols visible cells of every line in $block.
     *
     * The row clip's twin (see invariant 3 on {@see renderView()}). Truncation
     * is ANSI-aware — escape sequences carry no width and a trailing SGR reset
     * survives the cut — so clipping a styled line cannot leak its colour onto
     * the rest of the screen.
     */
    private static function clipWidth(string $block, int $cols): string
    {
        $lines = explode("\n", $block);
        foreach ($lines as $i => $line) {
            if (Width::string($line) > $cols) {
                $lines[$i] = Width::truncateAnsi($line, $cols);
            }
        }

        return implode("\n", $lines);
    }

    /** Keep at most $rows lines from the BOTTOM of $block. */
    private static function clipTail(string $block, int $rows): string
    {
        $lines = explode("\n", $block);

        return count($lines) <= $rows ? $block : implode("\n", array_slice($lines, -$rows));
    }

    /**
     * Every agent producing text right now, keyed by name — registered or not:
     * {@see \SugarCraft\Crush\Agents\AgentManager::liveOutputs()} derives
     * from the sub-agent map, which is where a workflow's ad-hoc agents live.
     *
     * @return array<string, string>
     */
    private static function liveAgentOutputs(App $a): array
    {
        return $a->chat?->agentManager()?->liveOutputs() ?? [];
    }

    /**
     * The split-pane compositor's ACTIVATION POLICY and its width, in one
     * place. `0` means no split.
     *
     * ## The policy: data, not configuration
     *
     * The split appears exactly when at least one agent has a NON-TERMINAL
     * sub-agent that has produced text, and the terminal is wide enough to
     * carry two panes. There is no toggle and no config key, and that is what
     * {@see \SugarCraft\Crush\Agents\AgentManager::liveOutputs()} was shaped
     * for: it OMITS the silent ones — its docblock says in as many words that
     * omitting them "is what keeps it from rendering a row of empty tiles — the
     * exact reason that compositor was deferred rather than wired" — and, since
     * this item's follow-up, the finished ones too, applying the same
     * `!isComplete() && !isStopped()` predicate `active()` has always applied.
     * A map that is empty whenever there is nothing to show IS the activation
     * signal; a key defaulting to `false` would only ask the user to re-state
     * it, and one defaulting to `true` would be this policy with an extra
     * file.
     *
     * Without that liveness half the split would have been one-way: nothing
     * clears `SubAgent::$output`, so the first workflow of a session would have
     * opened a column that stayed open until the process exited.
     *
     * ## What this policy can and cannot do TODAY, measured
     *
     * The map is populated on a real launch. `/workflow run` reaches
     * {@see \SugarCraft\Crush\Workflows\WorkflowEngine::executeParallelStage()},
     * which hands its `SubAgent`s to `AgentManager::executeAll()`
     * (`Workflows/WorkflowEngine.php:1296`); that files each one under the
     * manager's sub-agent map (`Agents/AgentManager.php:681`), and
     * `liveOutputs()` reads that map. (An earlier revision added "and settles
     * streamed text onto its `output`" here. It settles the FINAL text, on
     * completion — see "NOTHING TO PAINT" below, which is the whole reason
     * that distinction cost this pane a release.) It
     * did NOT before Phase 8 item 4's follow-up: `liveOutputs()` used to
     * iterate the REGISTERED map, and a workflow's agents are ad-hoc, named
     * `$task->name ?? $task->agentType` (`WorkflowEngine.php:1254`) and never
     * registered — with neither shipped workflow naming a parallel task after
     * a roster entry, that map could never contain one.
     *
     * ## The frame, and the text to put in it
     *
     * Both used to be missing, and they were two separate defects rather than
     * one. Recording both, because the first was documented here as the whole
     * story and it was not.
     *
     * NO FRAME. `Chat::workflowRun()` called `$this->workflowEngine->run()`
     * SYNCHRONOUSLY from inside `update()`, and candy-core's `Program`
     * repaints from a periodic timer on the ReactPHP loop
     * (`Program.php:387`), so the tick could not fire until the whole workflow
     * was over. It now runs in a `\Fiber` that a timer on that same loop
     * steps, suspending at `AgentWorkerPool::idle()` — the one point where the
     * PARENT is idle while workers run. (The blocking `stream_select()` at
     * `ProcessExecutor.php:81`/`:235` is in the CHILD, and never was the
     * obstacle it was described as here.)
     *
     * NOTHING TO PAINT. Even with a frame, this map was empty for the whole
     * of a run: on the pool path `SubAgent::$output` had exactly one writer,
     * `AgentManager::drain()`, which settles the final text at the same moment
     * it sets the status terminal. So a pool-executed sub-agent was always
     * either running-and-silent or finished, and the liveness filter — the
     * thing that correctly keeps this pane from showing stale tiles — could
     * never see one. `AgentWorkerPool::pumpProgress()` is the fix: forked
     * workers publish their `Streaming` chunks to a file in the pool's IPC
     * directory and the parent mirrors it onto the SubAgent on every poll.
     *
     * ⚠️ This paragraph previously cited `Chat.php:6212` dispatched at
     * `:5480`, and "KNOWN GAP issue #79". All of it was stale: the call sites
     * had drifted to `:6478`/`:6390`, and detain/sugarcraft #79 is a MERGED
     * pull request about CandyMetrics telemetry. No open issue tracked this.
     *
     * The OTHER route that would populate this map, a Task/Agent tool
     * delegating from a model turn, still does not exist (crush_code.md #45).
     * Beyond the live path, the split is exercised by
     * `AgentSplitCompositorTest` and by an embedder driving the manager
     * directly; the live one is exercised by `WorkflowLivePaneTest`.
     *
     * ## Width, and why it can still decline
     *
     * The agent column takes one part in {@see SPLIT_AGENT_DIVISOR}, clamped
     * to [{@see SPLIT_MIN_AGENT_COLS}, {@see SPLIT_MAX_AGENT_COLS}].
     *
     * The band check after the clamp is REDUNDANT WITH
     * {@see SPLIT_MIN_TOTAL_COLS} as the constants stand, and saying otherwise
     * would be inventing a case: for every `$cols >= 80` the band works out to
     * at least 53 cells — the minimum of `cols - min(60, max(24, intdiv(cols,
     * 3))) - 1`, attained at `cols = 80` and brute-forced to 100000 — well over
     * the 44 floor, so nothing reaching that line has ever failed it. It is kept as the invariant's guard rather
     * than as live logic — the clamp's LOWER bound can hand back a column
     * WIDER than the proportion asked for, so lowering
     * {@see SPLIT_MIN_TOTAL_COLS} or raising {@see SPLIT_MIN_AGENT_COLS} is
     * exactly the edit that starts starving the band, and this is what makes
     * that a declined split instead of a chat column squeezed under its own
     * floor. `AgentSplitCompositorTest` sweeps the widths that pin the
     * relationship. Declining is always safe: the caller falls back to the
     * full-width band.
     *
     * @param array<string, string> $liveAgents as {@see liveAgentOutputs()} returns it
     */
    private static function agentSplitWidth(array $liveAgents, int $cols): int
    {
        if ($liveAgents === [] || $cols < self::SPLIT_MIN_TOTAL_COLS) {
            return 0;
        }

        $width = min(
            self::SPLIT_MAX_AGENT_COLS,
            max(self::SPLIT_MIN_AGENT_COLS, intdiv($cols, self::SPLIT_AGENT_DIVISOR)),
        );

        if ($cols - $width - self::SPLIT_DIVIDER_COLS < self::SPLIT_MIN_BAND_COLS) {
            return 0;
        }

        return $width;
    }

    /**
     * Join the shell band and the live-agent column into one content band.
     *
     * Three things this method is responsible for, all of them width
     * arithmetic that the render invariants make load-bearing:
     *
     * 1. The band is pre-clipped to `$bandCols` with the ANSI-AWARE
     *    {@see clipWidth()}. {@see SplitLayout} has a truncator of its own, but
     *    its slow path walks raw codepoints and its own docblock records that
     *    it can cut a line mid-SGR; the band is styled, so a cut there would
     *    leak a colour across the divider into the agent column. Handing it
     *    content that already fits means that truncator never fires on it.
     * 2. The proportions are handed over as numerator = `$bandCols`,
     *    denominator = `$cols`, which makes `SplitLayout::splitPosition()`
     *    return `round($bandCols / $cols * $cols)` = exactly `$bandCols`. The
     *    ratio machinery is used for what it is, rather than approximated with
     *    a 1/3 that rounds to a different cell on most widths.
     * 3. `$bandCols + divider + $agentCols === $cols` is the invariant the
     *    whole method rests on; it is established by the caller's
     *    `$bandCols = $cols - $agentCols - SPLIT_DIVIDER_COLS` and is what
     *    keeps every composed row exactly `$cols` wide instead of one border
     *    over. `renderView()`'s own `clipWidth()` on the finished frame is the
     *    backstop, not the mechanism.
     *
     * The entry point is {@see renderForCurrentEnvironment()} rather than
     * {@see renderWithSplit()} because it is the one that asks
     * {@see MultiplexerSplitPane} first — under tmux or iTerm2 the same call
     * is the seam a future native-pane implementation takes over.
     *
     * @param array<string, string> $liveAgents as {@see liveAgentOutputs()} returns it
     */
    private static function composeAgentSplit(
        App $a,
        array $liveAgents,
        string $band,
        int $bandCols,
        int $agentCols,
        int $cols,
        int $rows,
    ): string {
        $column = AgentSplitColumn::render(
            $liveAgents,
            $a->chat?->agentManager(),
            $a->theme(),
            $agentCols,
            $rows,
        );

        // Unreachable by construction — agentSplitWidth() returned non-zero, so
        // the map is non-empty and both budgets are positive, which is every
        // way AgentSplitColumn::render() can answer ''. Kept because the
        // alternative to a narrower-than-usual band is a frame whose rows are
        // `$agentCols + 1` cells short of the terminal with nothing drawn in
        // them, and a degraded band is the cheaper of the two.
        if ($column === '') {
            return $band;
        }

        return self::renderForCurrentEnvironment(
            self::clipWidth($band, $bandCols),
            $column,
            SplitDirection::Vertical,
            $cols,
            $rows,
            $bandCols,
            $cols,
        );
    }

    private static function leftSidebar(App $a, int $cols, int $rows): string
    {
        $width = (int) floor($cols / 4);
        $width = max(20, $width);

        if ($a->pane === Pane::Files) {
            return FilesPane::render($a, $width, $rows);
        }

        if ($a->pane === Pane::Tools) {
            return ToolsPane::render($a, $width, $rows);
        }

        return FilesPane::render($a, $width, $rows);
    }

    /**
     * Note on {@see AgentsPane}: its `Pane::Agents` arm below is no longer
     * taken, because {@see renderView()} now diverts that pane to the
     * full-width {@see AgentDashboardPane} before any sidebar is built. It is
     * kept, not removed — it is the sidebar-sized agents widget, and the arm
     * is the seam a future side-by-side layout re-enters through.
     *
     * {@see \SugarCraft\Crush\Renderer}'s class docblock records the same
     * dormant-seam status from the transcript renderer's side; if one of the
     * two is ever changed, the other has to move with it.
     */
    private static function rightSidebar(App $a, int $cols, int $rows): string
    {
        $width = (int) floor($cols / 4);
        $width = max(20, $width);

        if ($a->pane === Pane::Skills) {
            return SkillsPane::render($a, $width, $rows);
        }

        // Pane::Settings had no arm here at all, which is what made selecting
        // it (Ctrl+, or, before it was dropped, Tab) draw an empty band.
        if ($a->pane === Pane::Settings) {
            return SettingsPane::render($a, $width, $rows);
        }

        if ($a->pane === Pane::Agents) {
            return AgentsPane::render($a, $width, $rows);
        }

        return '';
    }

    /**
     * The live bottom status bar.
     *
     * Delegates segment joining, the `' | '` separator and the leading-space
     * edge cap to the shared {@see BarStatusBar} (candy-sprinkles) primitive —
     * the same primitive sugar-dash and candy-hermit's status bars sit on top
     * of — so there is a single status-bar implementation. This class only
     * supplies the crush theme (per-segment colours) and the segment set,
     * including the literal `[Tab] Switch Pane` hint. The provider / model
     * ROLES (`shellSuccess` / `shellWarning`) and the error (`shellError` bold)
     * vs. status (`shellSuccess`) precedence are unchanged from the previous
     * hand-rolled string; the literals those roles replaced were #9ece6a /
     * #e0af68 / #f7768e, i.e. tokyoNight's success/warning/error, hardcoded
     * whatever palette the user picked.
     *
     * Behaviour note: the primitive skips an empty segment, so when there is
     * neither an error nor a status the previous hand-rolled template's trailing
     * dangling `' | '` separator (an empty final slot) is no longer emitted — the
     * bar simply ends at `[Tab] Switch Pane`. All populated cases stay byte-exact.
     */
    private static function statusBar(App $a): string
    {
        $theme = $a->theme();
        $segments = [
            Segment::of($a->provider->name(), Style::new()->foreground($theme->shellSuccess)),
            Segment::of($a->model, Style::new()->foreground($theme->shellWarning)),
            Segment::of('[Tab] Switch Pane'),
        ];

        if ($a->error) {
            $segments[] = Segment::of(
                'error: ' . $a->error,
                Style::new()->foreground($theme->shellError)->bold(),
            );
        } elseif ($a->status) {
            $segments[] = Segment::of(
                $a->status,
                Style::new()->foreground($theme->shellSuccess),
            );
        }

        return BarStatusBar::new()
            ->separator(' | ')
            ->caps(' ', '')
            ->left(...$segments)
            ->render();
    }

    /**
     * Paint the menu dropdown onto an already-composed frame.
     *
     * A floating overlay, in the same spirit as the palette's Veil: it
     * replaces cells rather than inserting rows, so the panes below keep the
     * geometry the layout gave them and the zone origin computed after this
     * stays correct.
     *
     * @param list<string> $panel
     */
    private static function overlayDropdown(string $frame, array $panel, int $col, int $topRow): string
    {
        if ($panel === []) {
            return $frame;
        }

        $lines = explode("\n", $frame);
        foreach ($panel as $i => $panelLine) {
            $row = $topRow + $i;
            if (!isset($lines[$row])) {
                break;
            }
            $lines[$row] = self::spliceInto($lines[$row], $panelLine, $col);
        }

        return implode("\n", $lines);
    }

    /**
     * Replace the run of cells starting at $col in $line with $patch,
     * preserving whatever the line had on either side of it.
     */
    private static function spliceInto(string $line, string $patch, int $col): string
    {
        $plainWidth = Width::string($patch);
        $head = Width::truncateAnsi($line, $col);
        $headPad = str_repeat(' ', max(0, $col - Width::string($head)));
        $tailStart = $col + $plainWidth;
        $lineWidth = Width::string($line);
        $tail = $lineWidth > $tailStart
            ? Width::truncateAnsi($line, $lineWidth) // keep styling reset simple
            : '';

        // Drop the overlapped run from the tail by re-truncating from the left.
        if ($tail !== '') {
            $tail = mb_substr(preg_replace('/\e\[[0-9;]*m/', '', $tail) ?? '', $tailStart);
        }

        return $head . $headPad . $patch . $tail;
    }
}
