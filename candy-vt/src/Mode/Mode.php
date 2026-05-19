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
        public bool $autoWrap = false,
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
        );
    }

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
            && $this->autoWrap === $other->autoWrap;
    }
}
