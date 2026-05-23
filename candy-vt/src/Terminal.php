<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Parser\HandlerAdapter;
use SugarCraft\Vt\Parser\OscHandlerImpl;
use SugarCraft\Vt\Parser\Parser;

/**
 * Public terminal surface for the vcr renderer path.
 *
 * Holds a CellGrid, Cursor, and Parser with CsiHandlerImpl + OscHandlerImpl
 * wired. Feed bytes through `feed()`, capture frames via `snapshot()`.
 */
final class Terminal
{
    private CellGrid $grid;
    private Cursor $cursor;
    private Parser $parser;
    private CsiHandlerImpl $csi;
    private OscHandlerImpl $osc;
    private Theme $theme;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
        CellGrid $grid,
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
        $grid = new CellGrid($cols, $rows);
        $cursor = new Cursor();

        $csi = new CsiHandlerImpl($grid, $cursor, $theme);
        $osc = new OscHandlerImpl();

        $handler = new HandlerAdapter($csi, $osc);
        $parser = new Parser($handler);

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

    public function grid(): CellGrid
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
