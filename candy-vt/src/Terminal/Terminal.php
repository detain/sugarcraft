<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Terminal;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Parser;
use SugarCraft\Vt\Screen\Screen;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Public terminal facade.
 *
 * Holds a {@see Parser} and a {@see ScreenHandler} that owns the
 * Buffer, Cursor, Sgr pen, and Mode. `feed()` drives bytes through the
 * parser; accessors return the handler's current state.
 */
final class Terminal
{
    private Parser $parser;
    private ScreenHandler $handler;

    public function __construct(
        int $cols,
        int $rows,
        ?Buffer $buffer = null,
        ?Cursor $cursor = null,
        ?Mode $mode = null,
    ) {
        $this->handler = new ScreenHandler(
            buffer: $buffer ?? new Buffer($cols, $rows),
            cursor: $cursor,
            sgr: Sgr::empty(),
            mode: $mode,
        );
        $this->parser = new Parser($this->handler);
    }

    public static function create(int $cols = 80, int $rows = 24): self
    {
        return new self($cols, $rows);
    }

    public function feed(string $bytes): void
    {
        $this->parser->feed($bytes);
    }

    public function screen(): Screen
    {
        return Screen::fromBuffer($this->handler->buffer);
    }

    public function cursor(): Cursor
    {
        return $this->handler->cursor;
    }

    public function mode(): Mode
    {
        return $this->handler->mode;
    }

    public function windowTitle(): ?string
    {
        return $this->handler->windowTitle;
    }

    public function resize(int $cols, int $rows): void
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException('cols and rows must be >= 1');
        }
        $this->handler->buffer = $this->handler->buffer->resize($cols, $rows);
    }

    public function __clone(): void
    {
        $this->handler = clone $this->handler;
        $this->parser = new Parser($this->handler);
    }

    /** @internal */
    public function withBuffer(Buffer $buf): self
    {
        $clone = clone $this;
        $clone->handler->buffer = $buf;
        return $clone;
    }

    /** @internal */
    public function withCursor(Cursor $cursor): self
    {
        $clone = clone $this;
        $clone->handler->cursor = $cursor;
        return $clone;
    }

    /** @internal */
    public function withMode(Mode $mode): self
    {
        $clone = clone $this;
        $clone->handler->mode = $mode;
        return $clone;
    }

    /** @internal */
    public function withWindowTitle(?string $title): self
    {
        $clone = clone $this;
        $clone->handler->windowTitle = $title;
        return $clone;
    }
}
