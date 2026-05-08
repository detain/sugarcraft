<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Screen\Screen;

/**
 * Public terminal facade.
 *
 * Create via `Terminal::create()`. In PR1 feed() is a no-op stub.
 * PR2 wires in the real Parser + ScreenHandler.
 */
final class Terminal
{
    private Buffer $buffer;
    private Cursor $cursor;
    private Mode $mode;
    private ?string $windowTitle = null;

    public function __construct(
        int $cols,
        int $rows,
        ?Buffer $buffer = null,
        ?Cursor $cursor = null,
        ?Mode $mode = null,
    ) {
        $this->buffer = $buffer ?? new Buffer($cols, $rows);
        $this->cursor = $cursor ?? new Cursor();
        $this->mode = $mode ?? new Mode();
    }

    public static function create(int $cols = 80, int $rows = 24): self
    {
        return new self($cols, $rows);
    }

    /**
     * Feed raw ANSI bytes into the terminal.
     *
     * In PR1 this is a no-op stub. PR2 replaces it with a real
     * Parser + ScreenHandler pipeline.
     */
    public function feed(string $bytes): void
    {
        // no-op in PR1; replaced by Parser in PR2
    }

    public function screen(): Screen
    {
        return Screen::fromBuffer($this->buffer);
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function windowTitle(): ?string
    {
        return $this->windowTitle;
    }

    public function resize(int $cols, int $rows): void
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException('cols and rows must be >= 1');
        }
        $this->buffer = $this->buffer->resize($cols, $rows);
    }

    // --- Internal mutation helpers (used by handlers in later PRs) ---

    /** @internal */
    public function withBuffer(Buffer $buf): self
    {
        $clone = clone $this;
        $clone->buffer = $buf;
        return $clone;
    }

    /** @internal */
    public function withCursor(Cursor $cursor): self
    {
        $clone = clone $this;
        $clone->cursor = $cursor;
        return $clone;
    }

    /** @internal */
    public function withMode(Mode $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;
        return $clone;
    }

    /** @internal */
    public function withWindowTitle(?string $title): self
    {
        $clone = clone $this;
        $clone->windowTitle = $title;
        return $clone;
    }
}
