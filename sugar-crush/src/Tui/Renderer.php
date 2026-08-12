<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Tty;

/**
 * Terminal-size cache and split-pane layout helpers for sugar-crush.
 *
 * This class used to ALSO host a second, App-keyed full-screen renderer
 * (`render(App)` composing `Tui\Components\{MenuBar,ChatPane,InputPane,
 * FilesPane,ToolsPane,SkillsPane,AgentsPane}` plus its own status bar).
 * That layer was provably unreachable from `bin/sugarcrush` — the live path
 * is `bin/sugarcrush` → `Cli\Bootstrap::chat()` → `Chat` → `Program` →
 * `Chat::view()` → `SugarCraft\Crush\Renderer` — and it was retired per
 * crush_feat.md §5E item 7: two parallel UI systems meant every future
 * session/agent feature risked being built against the wrong one (the
 * `AgentsPane`/`ToolsPane` bodies were already frozen stubs, and `Chat` had
 * already re-implemented the App shell's Ctrl+A shortcut on the live path).
 *
 * What remains is deliberately the part that is genuinely live or has a
 * concrete near-term consumer:
 *
 * - {@see self::setSize()}/{@see self::getTerminalSize()}/{@see self::resetSizeCache()}
 *   are the process-wide terminal-size cache the LIVE `Chat` reads from when
 *   no `WindowSizeMsg` has arrived yet.
 * - {@see self::renderWithSplit()}/{@see self::renderForCurrentEnvironment()}
 *   are the multiplexer-aware split-pane entry points crush_feat.md §5E item 9
 *   builds on to delegate real panes to tmux.
 *
 * Everything here is a pure function of its arguments plus the size cache.
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
}
