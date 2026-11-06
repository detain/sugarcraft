<?php

declare(strict_types=1);

namespace SugarCraft\Vterm;

use SugarCraft\AnsiParser\Parser\HandlerAdapter;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;
use SugarCraft\Vterm\Parser\OscHandlerImpl;
use SugarCraft\Vterm\Parser\RendererHandler;

/**
 * Public terminal surface for the vcr renderer path.
 *
 * Holds a Buffer, Cursor, and sugar-ansi-parser's Parser with CsiHandlerImpl +
 * OscHandlerImpl wired through sugar-ansi-parser's HandlerAdapter. Feed bytes
 * through `feed()` (mutates in place, returns `$this` for chaining), capture
 * frames via `snapshot()` — which deep-copies the grid so a captured frame
 * is frozen against all later feeds.
 */
final class Terminal
{
    /**
     * String-buffer ceiling for the parser's OSC/DCS accumulation, shared by
     * BOTH facade parsers (renderer here, emulator in Terminal\Terminal) so
     * the hostile-payload memory bound is one constant, not two literals.
     * Equal to sugar-ansi-parser's Parser default (64 KiB) today; pinned
     * explicitly so this security bound survives parser-default drift, and
     * pinned by ParamLimitTest's facade legs.
     */
    public const MAX_STRING_BUFFER = 65536;

    private Buffer $grid;
    private Cursor $cursor;
    private Parser $parser;
    private CsiHandlerImpl $csi;
    private OscHandlerImpl $osc;
    private Theme $theme;
    private int $revision = 0;

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
        // The OSC handler feeds the CSI pen: OSC 8 links must reach the cells
        // printable() writes (ESC-2), mirroring the emulator's single-handler
        // wiring where both live inside ScreenHandler.
        $osc = new OscHandlerImpl($csi);

        $handler = new RendererHandler($csi, new HandlerAdapter($csi, $osc));
        // 64 KiB string-buffer cap bounds OSC/DCS payload memory. The value
        // equals sugar-ansi-parser's Parser default (65536) today; it is
        // pinned explicitly as self::MAX_STRING_BUFFER so this security bound
        // survives any future change to the parser's default.
        // RendererHandler is the parser's sink and a SubparamsAwareHandler, so
        // the colon continuation flags SGR needs are pushed down to CsiHandlerImpl
        // on every dispatch — no late-bound back-reference to this parser exists.
        $parser = new Parser($handler, maxStringBuffer: self::MAX_STRING_BUFFER);

        return new self($cols, $rows, $grid, $cursor, $parser, $csi, $osc, $theme);
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * Consume bytes. MUTATES this terminal in place and returns $this for
     * chaining — it does not produce a new instance (frames are frozen by
     * {@see self::snapshot()}, which deep-copies the grid at capture).
     */
    public function feed(string $bytes): self
    {
        $this->parser->feed($bytes);
        $this->revision++;
        $this->syncState();
        return $this;
    }

    /**
     * Force any in-flight string sequence (OSC/DCS) to dispatch with its
     * current payload and reset the parser to ground.
     *
     * Mirrors {@see \SugarCraft\Vterm\Terminal\Terminal::flush()} — lets vcr
     * consumers feeding a partial stream capture the final window title (or
     * any other OSC side effect) before {@see self::snapshot()} instead of
     * waiting for a terminator byte that never arrives.
     */
    public function flush(): void
    {
        $this->parser->flush();
        $this->revision++;
        $this->syncState();
    }

    /**
     * Alt screen (DEC 1049) belongs to the full emulator path; the vcr
     * renderer has no screen-swap machinery. These parity stubs exist so
     * consumers written against both entry points fail loudly rather than
     * discovering the asymmetry via a silent method-not-found.
     *
     * @throws \LogicException always — use {@see \SugarCraft\Vterm\Terminal\Terminal}
     */
    public function enableAltScreen(): never
    {
        throw new \LogicException('Alt screen requires the full Terminal\Terminal path');
    }

    /**
     * Counterpart of {@see self::enableAltScreen()} — parity stub only.
     *
     * @throws \LogicException always — use {@see \SugarCraft\Vterm\Terminal\Terminal}
     */
    public function disableAltScreen(): never
    {
        throw new \LogicException('Alt screen requires the full Terminal\Terminal path');
    }

    /**
     * Freeze the current frame: the grid is deep-copied (clone) so the
     * Snapshot is unaffected by any later {@see self::feed()}, and the
     * terminal's feed revision rides along for cheap change detection.
     */
    public function snapshot(float $time = 0.0): Snapshot
    {
        return new Snapshot(clone $this->grid, $this->cursor, $time, $this->revision);
    }

    /**
     * Monotonic write counter: bumped once per {@see self::feed()} and
     * {@see self::flush()} call. Two snapshots with equal revision and
     * equal grid are provably the same frame without a deep compare.
     */
    public function revision(): int
    {
        return $this->revision;
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    /**
     * Deferred-wrap (phantom-cell) state — mirrors the emulator's
     * {@see \SugarCraft\Vterm\Terminal\Terminal::isWrapPending()}.
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
