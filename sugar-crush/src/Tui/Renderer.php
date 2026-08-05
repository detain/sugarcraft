<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Sprinkles\Bar\Segment;
use SugarCraft\Sprinkles\Bar\StatusBar as BarStatusBar;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\Components\ChatPane;
use SugarCraft\Crush\Tui\Components\InputPane;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Components\AgentsPane;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Components\MenuBar;

/**
 * Stateless renderer for the sugar-crush TUI.
 * Composes multiple panes into a full terminal interface.
 * Pure function - given the same App it always produces the same bytes.
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

    public static function render(App $a): string
    {
        $size = self::getTerminalSize();
        $cols = $size['cols'];
        $rows = $size['rows'];

        // Build panes based on focused pane
        $menuBar = MenuBar::render($a);
        $chatPane = ChatPane::render($a, $cols, $rows);
        $inputPane = InputPane::render($a, $cols);
        $statusBar = self::statusBar($a);

        // Side panes
        $leftPane = self::leftSidebar($a, $cols, $rows);
        $rightPane = self::rightSidebar($a, $cols, $rows);

        // Compose: top bar + left + chat + right + input + status
        $top = $menuBar;
        $middle = Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane);
        $bottom = $inputPane . "\n" . $statusBar;

        return $top . "\n" . $middle . "\n" . $bottom;
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
