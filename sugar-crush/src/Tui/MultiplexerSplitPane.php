<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Detects and manages multiplexer-backed split pane rendering.
 *
 * When the TMUX environment variable is set or iTerm2 is detected,
 * this service indicates that a real terminal multiplexer is available
 * to handle split panes natively. In such cases, the application can
 * delegate pane management to the multiplexer instead of using the
 * in-process split renderer.
 *
 * Detection is performed at construction time and cached for the
 * lifetime of the instance (since environment variables don't change
 * within a running process).
 *
 * @see MultiplexerType
 * @see SplitLayout for the in-process implementation.
 */
final class MultiplexerSplitPane
{
    /**
     * @var MultiplexerType The detected multiplexer type (cached).
     */
    private readonly MultiplexerType $detectedType;

    /**
     * Create a new multiplexer detector.
     *
     * Detection is performed immediately on instantiation using the
     * current process environment (TMUX variable, TERM_PROGRAM, etc.).
     *
     * @param string|null $tmuxEnv Override TMUX env value for testing (null = use getenv).
     * @param string|null $termProgramEnv Override TERM_PROGRAM env value for testing (null = use getenv).
     */
    public function __construct(
        private readonly ?string $tmuxEnv = null,
        private readonly ?string $termProgramEnv = null,
    ) {
        $this->detectedType = $this->detect();
    }

    /**
     * Returns true if a terminal multiplexer (TMUX or iTerm2) is active.
     *
     * When active, the application should prefer multiplexer-native
     * split pane rendering over the in-process SplitLayout renderer.
     *
     * @return bool True if multiplexer is active, false for in-process rendering.
     */
    public function isActive(): bool
    {
        return $this->detectedType->isActive();
    }

    /**
     * Returns the type of multiplexer detected in the current environment.
     *
     * @return MultiplexerType The detected multiplexer type.
     */
    public function getMultiplexerType(): MultiplexerType
    {
        return $this->detectedType;
    }

    /**
     * Render a split layout, delegating to the multiplexer when active.
     *
     * Currently this falls back to the in-process SplitLayout renderer
     * even when multiplexer mode is detected. A future implementation
     * may shell out to tmux/iTerm2 to create native split panes.
     *
     * @param string         $topOrLeft    Content of the first pane.
     * @param string         $bottomOrRight Content of the second pane.
     * @param SplitDirection $direction    Split orientation.
     * @param int            $cols         Available columns.
     * @param int            $rows         Available rows.
     * @return string Rendered split layout.
     */
    public function renderWithMultiplexer(
        string $topOrLeft,
        string $bottomOrRight,
        SplitDirection $direction,
        int $cols,
        int $rows,
    ): string {
        // When multiplexer is active but we can't spawn real tmux panes,
        // fall back to in-process rendering. The multiplexer detection
        // is still useful for future native integration.
        $layout = new SplitLayout(
            $topOrLeft,
            $bottomOrRight,
            $direction,
        );

        return $layout->render($cols, $rows);
    }

    /**
     * Detect which multiplexer (if any) is active in the environment.
     *
     * Detection order:
     * 1. TMUX environment variable (non-empty) → tmux
     * 2. TERM_PROGRAM=iTerm.app → iTerm2
     * 3. Otherwise → None
     *
     * The TMUX variable is checked first because tmux is the more
     * feature-complete multiplexer and takes precedence.
     */
    private function detect(): MultiplexerType
    {
        // Check TMUX first - it's the most common multiplexer
        $tmux = $this->getEnv('TMUX');
        if ($tmux !== '' && $tmux !== false) {
            return MultiplexerType::Tmux;
        }

        // Check for iTerm2 running on macOS
        $termProgram = $this->getEnv('TERM_PROGRAM');
        if ($termProgram === 'iTerm.app') {
            return MultiplexerType::ITerm2;
        }

        return MultiplexerType::None;
    }

    /**
     * Get an environment variable value, handling both getenv() and $_ENV.
     *
     * Uses getenv() for broader compatibility with different SAPIs.
     *
     * @param string $name Environment variable name.
     * @return string|false The value, or false if not set.
     */
    private function getEnv(string $name): string|false
    {
        // Use the provided override for testing, or fall back to getenv
        if ($this->tmuxEnv !== null && $name === 'TMUX') {
            return $this->tmuxEnv === '' ? false : $this->tmuxEnv;
        }

        if ($this->termProgramEnv !== null && $name === 'TERM_PROGRAM') {
            return $this->termProgramEnv === '' ? false : $this->termProgramEnv;
        }

        $value = getenv($name);

        // getenv returns false on not found, or the string value
        return $value;
    }
}
