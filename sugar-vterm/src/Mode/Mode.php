<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Mode;

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
 *
 * Mouse state is split along xterm's own axis: WHICH tracking is on lives in
 * the four orthogonal bits {@see $mouseHighlights} (DEC 1001), {@see $mouseAny}
 * (1000), {@see $mouseCellMotion} (1002) and {@see $mouseExtended} (1003);
 * HOW a report encodes its coordinates is the trio of per-mode flags
 * {@see $mouseUtf8} (1005), {@see $mouseSgr} (1006) and {@see $mouseUrxvt}
 * (1015) — vterm-12: xterm keeps each mode independently switched, so the
 * flags co-activate and the EFFECTIVE encoder is resolved at report time by
 * {@see self::effectiveMouseEncoding()} with xterm's SGR > urxvt > UTF-8 >
 * X10 priority. "Is any report flowing at all" is
 * {@see self::isMouseReporting()}, deliberately independent of the encoders.
 */
final readonly class Mode
{
    public const int ALT_NONE = 0;
    public const int ALT_NO_SAVE = 1;
    public const int ALT_CURSOR_ONLY = 2;
    public const int ALT_FULL = 3;

    public function __construct(
        public bool $cursorVisible = true,
        public bool $bracketedPaste = false,
        public bool $mouseUtf8 = false,
        public bool $mouseSgr = false,
        public bool $mouseUrxvt = false,
        public bool $mouseAny = false,
        public bool $mouseHighlights = false,
        public bool $mouseCellMotion = false,
        public bool $syncUpdate = false,
        public bool $mouseExtended = false,
        public int $altScreenVariant = self::ALT_NONE,
        /**
         * DECAWM — auto-wrap mode (CSI ?7 h/l).
         *
         * POWER-ON DEFAULT IS ON (xterm, VT100 and up): DEC's documented
         * initial mode state sets DECAWM; a terminal that begins with
         * auto-wrap off mis-renders every un-linewrapped program.
         *
         * When true, a graphic that lands in the last column sets the
         * cursor's wrapPending flag (deferred wrap / "wrapthrough"): the
         * cursor visibly stays in the last column until the NEXT graphic
         * arrives, which then triggers the advance to column 0 of the
         * following row (scrolling if at the bottom of the DECSTBM
         * region). When false, characters at the rightmost column
         * overwrite it without advancing.
         *
         * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECAWM)
         * @see xterm ctlseqs: "wraparound" deferred to the next printable
         */
        public bool $autoWrap = true,
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
         * @see \SugarCraft\Vterm\CursorShape
         */
        public int $cursorShape = 0,
        /**
         * Focus event reporting (CSI ?1004 h/l).
         *
         * When true, the terminal records focus-in (CSI I) and
         * focus-out (CSI O) events to the focusEvents list.
         */
        public bool $reportFocusEvents = false,
    ) {
    }

    /**
     * Rebuild the snapshot with single-field overrides (house mutate model,
     * see {@see \SugarCraft\Vterm\Cell::mutate()}).
     *
     * Every with*() below routes through this one ctor-arg list: a future
     * 15th field is added ONCE here and can no longer be forgotten in a
     * per-setter named-arg block. Mode fields are never nullable, so the
     * plain `??` fallback is total.
     *
     * @param array{cursorVisible?:bool, bracketedPaste?:bool, mouseUtf8?:bool, mouseSgr?:bool, mouseUrxvt?:bool, mouseAny?:bool, mouseHighlights?:bool, mouseCellMotion?:bool, syncUpdate?:bool, mouseExtended?:bool, altScreenVariant?:int, autoWrap?:bool, originMode?:bool, cursorShape?:int, reportFocusEvents?:bool} $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            cursorVisible: $changes['cursorVisible'] ?? $this->cursorVisible,
            bracketedPaste: $changes['bracketedPaste'] ?? $this->bracketedPaste,
            mouseUtf8: $changes['mouseUtf8'] ?? $this->mouseUtf8,
            mouseSgr: $changes['mouseSgr'] ?? $this->mouseSgr,
            mouseUrxvt: $changes['mouseUrxvt'] ?? $this->mouseUrxvt,
            mouseAny: $changes['mouseAny'] ?? $this->mouseAny,
            mouseHighlights: $changes['mouseHighlights'] ?? $this->mouseHighlights,
            mouseCellMotion: $changes['mouseCellMotion'] ?? $this->mouseCellMotion,
            syncUpdate: $changes['syncUpdate'] ?? $this->syncUpdate,
            mouseExtended: $changes['mouseExtended'] ?? $this->mouseExtended,
            altScreenVariant: $changes['altScreenVariant'] ?? $this->altScreenVariant,
            autoWrap: $changes['autoWrap'] ?? $this->autoWrap,
            originMode: $changes['originMode'] ?? $this->originMode,
            cursorShape: $changes['cursorShape'] ?? $this->cursorShape,
            reportFocusEvents: $changes['reportFocusEvents'] ?? $this->reportFocusEvents,
        );
    }

    public function withAltScreen(bool $v): self
    {
        return $this->mutate(['altScreenVariant' => $v ? self::ALT_FULL : self::ALT_NONE]);
    }

    /**
     * Set the alt screen variant directly (modes 47, 1047, 1048, 1049).
     *
     * @param int $variant One of ALT_NONE, ALT_NO_SAVE, ALT_CURSOR_ONLY, ALT_FULL
     */
    public function withAltScreenVariant(int $variant): self
    {
        return $this->mutate(['altScreenVariant' => $variant]);
    }

    /**
     * Returns true if any alt screen variant is active (variant != ALT_NONE).
     *
     * vterm-07: the alt-screen boolean is fully derived from the variant —
     * an inconsistent (altScreen=true, ALT_NONE) pair is no longer a state
     * the value object can represent.
     */
    public function isAltScreen(): bool
    {
        return $this->altScreenVariant !== self::ALT_NONE;
    }

    public function withCursorVisible(bool $v): self
    {
        return $this->mutate(['cursorVisible' => $v]);
    }

    /** DEC 1005 — UTF-8 coordinate form flag (independent, vterm-12). */
    public function withMouseUtf8(bool $v): self
    {
        return $this->mutate(['mouseUtf8' => $v]);
    }

    /** DEC 1006 — SGR coordinate form flag (independent, vterm-12). */
    public function withMouseSgr(bool $v): self
    {
        return $this->mutate(['mouseSgr' => $v]);
    }

    /** DEC 1015 — urxvt coordinate form flag (independent, vterm-12). */
    public function withMouseUrxvt(bool $v): self
    {
        return $this->mutate(['mouseUrxvt' => $v]);
    }

    /**
     * The encoder a report emitted NOW would use (DEC 1005/1006/1015).
     *
     * xterm ctlseqs: the modes only change how coordinates are spelled;
     * several may be on at once, and the effective form resolves by the
     * SGR > urxvt > UTF-8 > X10 priority. Clearing the top mode therefore
     * FALLS to the next-on flag (a `?1006l` with `?1015h` still on keeps
     * emitting urxvt reports), which the retired single-slot model got
     * wrong — it dropped to X10 whenever any encoder was reset.
     */
    public function effectiveMouseEncoding(): MouseEncoding
    {
        if ($this->mouseSgr) {
            return MouseEncoding::Sgr;
        }
        if ($this->mouseUrxvt) {
            return MouseEncoding::Urxvt;
        }
        if ($this->mouseUtf8) {
            return MouseEncoding::Utf8;
        }
        return MouseEncoding::Default;
    }

    public function withMouseHighlights(bool $v = true): self
    {
        return $this->mutate(['mouseHighlights' => $v]);
    }

    public function withMouseAny(bool $v): self
    {
        return $this->mutate(['mouseAny' => $v]);
    }

    public function withMouseCellMotion(bool $v): self
    {
        return $this->mutate(['mouseCellMotion' => $v]);
    }

    public function withMouseExtended(bool $v): self
    {
        return $this->mutate(['mouseExtended' => $v]);
    }

    /**
     * True while ANY mouse tracking mode is armed (DEC 1000/1001/1002/1003).
     *
     * Deliberately blind to {@see $mouseEncoding}: selecting an encoding with
     * no tracking mode on changes nothing a terminal would emit (xterm keeps
     * reporting off), so a consumer deciding "subscribe the mouse or not"
     * must consult THIS predicate, not the encoding slot.
     */
    public function isMouseReporting(): bool
    {
        return $this->mouseAny
            || $this->mouseHighlights
            || $this->mouseCellMotion
            || $this->mouseExtended;
    }

    public function withBracketedPaste(bool $v): self
    {
        return $this->mutate(['bracketedPaste' => $v]);
    }

    public function withSyncUpdate(bool $v): self
    {
        return $this->mutate(['syncUpdate' => $v]);
    }

    /**
     * Set DECAWM auto-wrap mode.
     *
     * @param bool $v True to enable auto-wrap (CSI ?7 h), false to disable (CSI ?7 l)
     */
    public function withAutoWrap(bool $v): self
    {
        return $this->mutate(['autoWrap' => $v]);
    }

    /**
     * Set DECOM origin mode.
     *
     * @param bool $v True to enable origin mode (CSI ?6 h), false to disable (CSI ?6 l)
     */
    public function withOriginMode(bool $v): self
    {
        return $this->mutate(['originMode' => $v]);
    }

    /**
     * Set DECSCUSR cursor shape.
     *
     * @param int $v Cursor shape value 0-6 (see CursorShape enum)
     */
    public function withCursorShape(int $v): self
    {
        return $this->mutate(['cursorShape' => $v]);
    }

    /**
     * Set focus event reporting mode.
     *
     * @param bool $v True to enable focus reporting (CSI ?1004 h), false to disable (CSI ?1004 l)
     */
    public function withReportFocusEvents(bool $v): self
    {
        return $this->mutate(['reportFocusEvents' => $v]);
    }

    public function equals(self $other): bool
    {
        return $this->cursorVisible === $other->cursorVisible
            && $this->bracketedPaste === $other->bracketedPaste
            && $this->mouseUtf8 === $other->mouseUtf8
            && $this->mouseSgr === $other->mouseSgr
            && $this->mouseUrxvt === $other->mouseUrxvt
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
