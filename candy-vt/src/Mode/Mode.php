<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Mode;

/**
 * DEC mode flags snapshot.
 *
 * Mirrors charmbracelet/x/vt Mode.
 *
 * Alt screen variants (tracked via altScreenVariant):
 * - ALT_NONE        = not in alt screen
 * - ALT_NO_SAVE     = DECSET 47, 1047 — swap buffers, no cursor/SGR save
 * - ALT_CURSOR_ONLY = DECSET 1048 — save cursor only, no content restore
 * - ALT_FULL        = DECSET 1049 — save all (buffer + cursor + SGR), full restore
 */
final readonly class Mode
{
    public const int ALT_NONE = 0;
    public const int ALT_NO_SAVE = 1;
    public const int ALT_CURSOR_ONLY = 2;
    public const int ALT_FULL = 3;

    public function __construct(
        public bool $altScreen = false,
        public bool $cursorVisible = true,
        public bool $bracketedPaste = false,
        public bool $mouseSgr = false,
        public bool $mouseAny = false,
        public bool $mouseHighlights = false,
        public bool $mouseCellMotion = false,
        public bool $syncUpdate = false,
        public bool $mouseExtended = false,
        public int $altScreenVariant = self::ALT_NONE,
        /**
         * DECAWM — auto-wrap mode (CSI ?7 h/l).
         *
         * When true, printing a character at the rightmost column advances
         * the cursor to column 0 of the next row (triggering a scroll if
         * the cursor is at the bottom of the DECSTBM scroll region).
         * When false, characters at the rightmost column are discarded.
         */
        public bool $autoWrap = false,
        /**
         * DECOM — origin mode (CSI ?6 h/l).
         *
         * When true, cursor addressing is relative to the scroll region
         * (top-left of the DECSTBM region). When false, cursor addressing
         * is relative to the absolute screen origin (0, 0).
         */
        public bool $originMode = false,
        /**
         * DECSCUSR — cursor shape (set via CSI Ps SP q).
         *
         * Stores the configured cursor shape as an integer (0-6).
         * Actual shape is applied to Cursor::$shape via withShape().
         *
         * @see \SugarCraft\Vt\CursorShape
         */
        public int $cursorShape = 0,
        /**
         * Focus event reporting (CSI ?1004 h/l).
         *
         * When true, the terminal records focus-in (CSI I) and
         * focus-out (CSI O) events to the focusEvents list.
         */
        public bool $reportFocusEvents = false,
    ) {}

    public function withAltScreen(bool $v): self
    {
        return new self(
            altScreen: $v,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $v ? self::ALT_FULL : self::ALT_NONE,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Set the alt screen variant directly (modes 47, 1047, 1048, 1049).
     *
     * @param int $variant One of ALT_NONE, ALT_NO_SAVE, ALT_CURSOR_ONLY, ALT_FULL
     */
    public function withAltScreenVariant(int $variant): self
    {
        return new self(
            altScreen: $variant !== self::ALT_NONE,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $variant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Returns true if any alt screen variant is active (variant != ALT_NONE).
     */
    public function isAltScreen(): bool
    {
        return $this->altScreenVariant !== self::ALT_NONE;
    }

    public function withCursorVisible(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $v,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withMouseSgr(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $v,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withMouseHighlights(bool $v = true): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $v,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withMouseAny(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $v,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withMouseCellMotion(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $v,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withMouseExtended(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $v,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withBracketedPaste(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $v,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    public function withSyncUpdate(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $v,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Set DECAWM auto-wrap mode.
     *
     * @param bool $v True to enable auto-wrap (CSI ?7 h), false to disable (CSI ?7 l)
     */
    public function withAutoWrap(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $v,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Set DECOM origin mode.
     *
     * @param bool $v True to enable origin mode (CSI ?6 h), false to disable (CSI ?6 l)
     */
    public function withOriginMode(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $v,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Set DECSCUSR cursor shape.
     *
     * @param int $v Cursor shape value 0-6 (see CursorShape enum)
     */
    public function withCursorShape(int $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $v,
            reportFocusEvents: $this->reportFocusEvents,
        );
    }

    /**
     * Set focus event reporting mode.
     *
     * @param bool $v True to enable focus reporting (CSI ?1004 h), false to disable (CSI ?1004 l)
     */
    public function withReportFocusEvents(bool $v): self
    {
        return new self(
            altScreen: $this->altScreen,
            cursorVisible: $this->cursorVisible,
            bracketedPaste: $this->bracketedPaste,
            mouseSgr: $this->mouseSgr,
            mouseAny: $this->mouseAny,
            mouseHighlights: $this->mouseHighlights,
            mouseCellMotion: $this->mouseCellMotion,
            syncUpdate: $this->syncUpdate,
            mouseExtended: $this->mouseExtended,
            altScreenVariant: $this->altScreenVariant,
            autoWrap: $this->autoWrap,
            originMode: $this->originMode,
            cursorShape: $this->cursorShape,
            reportFocusEvents: $v,
        );
    }

    public function equals(self $other): bool
    {
        return $this->altScreen === $other->altScreen
            && $this->cursorVisible === $other->cursorVisible
            && $this->bracketedPaste === $other->bracketedPaste
            && $this->mouseSgr === $other->mouseSgr
            && $this->mouseAny === $other->mouseAny
            && $this->mouseHighlights === $other->mouseHighlights
            && $this->mouseCellMotion === $other->mouseCellMotion
            && $this->syncUpdate === $other->syncUpdate
            && $this->mouseExtended === $other->mouseExtended
            && $this->altScreenVariant === $other->altScreenVariant
            && $this->autoWrap === $other->autoWrap
            && $this->originMode === $other->originMode
            && $this->cursorShape === $other->cursorShape
            && $this->reportFocusEvents === $other->reportFocusEvents;
    }
}
