<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Core\Util\Ansi;

/**
 * Full screen management for TUI applications.
 *
 * Features:
 * - Enter/leave alternate screen buffer
 * - Show/hide cursor
 * - Clear screen (full or partial)
 * - Save/restore cursor position
 * - Screen size detection
 *
 * Mirrors screen management from bubble-screen but adapted
 * to PHP with wither-style immutable setters.
 */
final class Screen implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    public const DEFAULT_WIDTH = 80;
    public const DEFAULT_HEIGHT = 24;

    public function __construct(
        private readonly \SugarCraft\Dashboard\Foundation\Item $content,
        private readonly bool $alternateScreen = true,
        private readonly bool $showCursor = false,
        private readonly bool $enableMouse = false,
    ) {}

    /**
     * Create a new screen with default settings.
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            alternateScreen: true,
            showCursor: false,
            enableMouse: false,
        );
    }

    /**
     * Set the allocated dimensions for this screen.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render one frame: clear, home, content.
     *
     * Alternate-screen entry, cursor hiding and mouse tracking are the
     * setup()/teardown() session bracket, never per-frame bytes — a render
     * that enters and leaves the alternate screen every frame strobes the
     * terminal on real hardware (finding M-30).
     */
    public function render(): string
    {
        $result = '';

        // Clear screen
        $result .= Ansi::eraseScreen();

        // Home cursor
        $result .= Ansi::cursorTo(1, 1);

        // Render content
        $content = $this->content;
        if ($content instanceof \SugarCraft\Dashboard\Foundation\Sizer) {
            $content = $content->setSize($this->getWidth(), $this->getHeight());
        }
        $result .= $content->render();

        return $result;
    }

    /**
     * Session preamble: enter the alternate screen, hide the cursor, enable
     * mouse tracking. Emit ONCE before the first render().
     */
    public function setup(): string
    {
        $sequence = '';

        if ($this->alternateScreen) {
            $sequence .= Ansi::altScreenEnter();
        }

        if (!$this->showCursor) {
            $sequence .= Ansi::cursorHide();
        }

        if ($this->enableMouse) {
            $sequence .= Ansi::mouseAllOn();
        }

        return $sequence;
    }

    /**
     * Session trailer matching setup(): restore the cursor and leave the
     * alternate screen. Pair with the same configuration the preamble was
     * built from.
     */
    public function teardown(): string
    {
        $sequence = '';

        if (!$this->showCursor) {
            $sequence .= Ansi::cursorShow();
        }

        if ($this->alternateScreen) {
            $sequence .= Ansi::altScreenLeave();
        }

        return $sequence;
    }

    /**
     * Get the width of the screen.
     */
    private function getWidth(): int
    {
        return $this->width ?? self::DEFAULT_WIDTH;
    }

    /**
     * Get the height of the screen.
     */
    private function getHeight(): int
    {
        return $this->height ?? self::DEFAULT_HEIGHT;
    }

    /**
     * Calculate the natural dimensions of this screen.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        return [$this->getWidth(), $this->getHeight()];
    }

    // ─── Static helpers ────────────────────────────────────────────

    /**
     * Clear the entire screen.
     */
    public static function clear(): string
    {
        return Ansi::eraseScreen() . Ansi::cursorTo(1, 1);
    }

    /**
     * Move cursor to a specific position.
     */
    public static function moveCursor(int $x, int $y): string
    {
        return Ansi::cursorTo($y + 1, $x + 1);
    }

    /**
     * Hide the cursor.
     */
    public static function hide(): string
    {
        return Ansi::cursorHide();
    }

    /**
     * Show the cursor.
     */
    public static function show(): string
    {
        return Ansi::cursorShow();
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Enable or disable alternate screen buffer.
     */
    public function withAlternateScreen(bool $enable): self
    {
        return new self(
            content: $this->content,
            alternateScreen: $enable,
            showCursor: $this->showCursor,
            enableMouse: $this->enableMouse,
        );
    }

    /**
     * Enable or disable cursor visibility.
     */
    public function withShowCursor(bool $show): self
    {
        return new self(
            content: $this->content,
            alternateScreen: $this->alternateScreen,
            showCursor: $show,
            enableMouse: $this->enableMouse,
        );
    }

    /**
     * Enable or disable mouse tracking.
     */
    public function withEnableMouse(bool $enable): self
    {
        return new self(
            content: $this->content,
            alternateScreen: $this->alternateScreen,
            showCursor: $this->showCursor,
            enableMouse: $enable,
        );
    }

    /**
     * Set new content.
     */
    public function withContent(\SugarCraft\Dashboard\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            alternateScreen: $this->alternateScreen,
            showCursor: $this->showCursor,
            enableMouse: $this->enableMouse,
        );
    }

    /**
     * Enable alternate screen buffer.
     */
    public function withAlternateScreenEnabled(): self
    {
        return $this->withAlternateScreen(true);
    }

    /**
     * Disable alternate screen buffer.
     */
    public function withAlternateScreenDisabled(): self
    {
        return $this->withAlternateScreen(false);
    }
}
