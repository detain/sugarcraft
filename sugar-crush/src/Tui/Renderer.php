<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
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
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Components\MenuBar;

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
    ): string {
        $layout = new SplitLayout(
            $topOrLeft,
            $bottomOrRight,
            $direction,
            $topOrLeftNumerator,
            $totalDenominator,
        );

        $size = self::getTerminalSize();

        return $layout->render($size['cols'], $size['rows']);
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
     * @param string         $topOrLeft    Content of the first pane.
     * @param string         $bottomOrRight Content of the second pane.
     * @param SplitDirection $direction   Split orientation.
     * @param int            $cols         Available columns (defaults to terminal width).
     * @param int            $rows         Available rows (defaults to terminal height).
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
            );
        }

        // No multiplexer - use in-process renderer directly
        return self::renderWithSplit($topOrLeft, $bottomOrRight, $direction);
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

        $leftPane = self::leftSidebar($a, $cols, $paneRows);
        $rightPane = self::rightSidebar($a, $cols, $paneRows);

        // The chat pane gets the columns actually LEFT OVER, measured from the
        // rendered sidebars. The old `$cols - 80` guess assumed two 40-column
        // sidebars; the real ones are a quarter of the terminal each and the
        // right one is usually absent, so on a 120-column terminal it handed
        // the pane 40 columns out of an available 86 and then truncated
        // everything wider than 40 to fit.
        $paneCols = max(24, $cols - self::blockWidth($leftPane) - self::blockWidth($rightPane));

        [$chatPane, $images] = ChatPane::renderView($a, $paneCols, $paneRows);

        // A sidebar taller than the pane budget would push the bottom chrome
        // off-screen, so the joined band is held to the budget as well.
        $middle = self::clipHead(
            Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane),
            $paneRows,
        );

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
                - (self::lineCount($joined) - self::lineCount($frame)),
        );

        return new View($frame, images: $images);
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
        if ($a->error !== null && $a->error !== '') {
            return Style::new()->foreground(Color::hex('#f7768e'))->bold()
                ->render(' error: ' . $a->error);
        }

        if ($a->status !== null && $a->status !== '') {
            return Style::new()->foreground(Color::hex('#9ece6a'))->render(' ' . $a->status);
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

    private static function rightSidebar(App $a, int $cols, int $rows): string
    {
        $width = (int) floor($cols / 4);
        $width = max(20, $width);

        if ($a->pane === Pane::Skills) {
            return SkillsPane::render($a, $width, $rows);
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
     * colours (`#9ece6a` / `#e0af68`) and the error (`#f7768e` bold) vs. status
     * (`#9ece6a`) precedence are unchanged from the previous hand-rolled string.
     *
     * Behaviour note: the primitive skips an empty segment, so when there is
     * neither an error nor a status the previous hand-rolled template's trailing
     * dangling `' | '` separator (an empty final slot) is no longer emitted — the
     * bar simply ends at `[Tab] Switch Pane`. All populated cases stay byte-exact.
     */
    private static function statusBar(App $a): string
    {
        $segments = [
            Segment::of($a->provider->name(), Style::new()->foreground(Color::hex('#9ece6a'))),
            Segment::of($a->model, Style::new()->foreground(Color::hex('#e0af68'))),
            Segment::of('[Tab] Switch Pane'),
        ];

        if ($a->error) {
            $segments[] = Segment::of(
                'error: ' . $a->error,
                Style::new()->foreground(Color::hex('#f7768e'))->bold(),
            );
        } elseif ($a->status) {
            $segments[] = Segment::of(
                $a->status,
                Style::new()->foreground(Color::hex('#9ece6a')),
            );
        }

        return BarStatusBar::new()
            ->separator(' | ')
            ->caps(' ', '')
            ->left(...$segments)
            ->render();
    }
}
