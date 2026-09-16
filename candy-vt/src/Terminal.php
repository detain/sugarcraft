<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Parser\OscHandlerImpl;
use SugarCraft\Vt\Parser\RendererHandler;

/**
 * Public terminal surface for the vcr renderer path.
 *
 * Holds a Buffer, Cursor, and candy-ansi's Parser with CsiHandlerImpl +
 * OscHandlerImpl wired through candy-ansi's HandlerAdapter. Feed bytes
 * through `feed()`, capture frames via `snapshot()`.
 */
final class Terminal
{
    private Buffer $grid;
    private Cursor $cursor;
    private Parser $parser;
    private CsiHandlerImpl $csi;
    private OscHandlerImpl $osc;
    private Theme $theme;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
        Buffer $grid,
        Cursor $cursor,
        Parser $parser,
        CsiHandlerImpl $csi,
        OscHandlerImpl $osc,
        ?Theme $theme = null,
    ) {
        $this->grid = $grid;
        $this->cursor = $cursor;
        $this->parser = $parser;
        $this->csi = $csi;
        $this->osc = $osc;
        $this->theme = $theme ?? new Theme();
    }

    public static function new(int $cols = 80, int $rows = 24, ?Theme $theme = null): self
    {
        $theme ??= new Theme();
        $grid = new Buffer($cols, $rows);
        $cursor = new Cursor();

        $csi = new CsiHandlerImpl($grid, $cursor, $theme);
        $osc = new OscHandlerImpl();

        $handler = new RendererHandler($csi, new HandlerAdapter($csi, $osc));
        // 64 KiB string-buffer cap (candy-ansi default) bounds OSC/DCS payload
        // memory; reduced from the fork's 1 MiB per the W1.2 security item.
        // RendererHandler is the parser's sink and a SubparamsAwareHandler, so
        // the colon continuation flags SGR needs are pushed down to CsiHandlerImpl
        // on every dispatch — no late-bound back-reference to this parser exists.
        $parser = new Parser($handler, maxStringBuffer: 65536);

        return new self($cols, $rows, $grid, $cursor, $parser, $csi, $osc, $theme);
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    public function feed(string $bytes): self
    {
        $this->parser->feed($bytes);
        $this->syncState();
        return $this;
    }

    public function snapshot(float $time = 0.0): Snapshot
    {
        return new Snapshot($this->grid, $this->cursor, $time);
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    /**
     * Deferred-wrap (phantom-cell) state — mirrors the emulator's
     * {@see \SugarCraft\Vt\Terminal\Terminal::isWrapPending()}.
     */
    public function isWrapPending(): bool
    {
        return $this->csi->wrapPending();
    }

    public function grid(): Buffer
    {
        return $this->grid;
    }

    public function windowTitle(): string
    {
        return $this->osc->lastTitle();
    }

    private function syncState(): void
    {
        $this->grid = $this->csi->grid();
        $this->cursor = $this->csi->cursor();
    }
}
