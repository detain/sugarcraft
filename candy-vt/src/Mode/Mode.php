<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Mode;

/**
 * DEC mode flags snapshot.
 *
 * Mirrors charmbracelet/x/vt Mode.
 */
final readonly class Mode
{
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
        );
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
            && $this->mouseExtended === $other->mouseExtended;
    }
}
