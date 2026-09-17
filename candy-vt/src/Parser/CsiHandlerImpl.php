<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Core\Util\Width;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Rendition;
use SugarCraft\Vt\Theme;

/**
 * CSI handler for the vcr renderer path.
 *
 * Mutates Buffer + Cursor directly in response to CSI dispatches.
 * Implements candy-ansi's {@see \SugarCraft\Ansi\Parser\CsiHandler} — the
 * shared parser dispatches completed sequences here via HandlerAdapter.
 *
 * Mirrors charmbracelet/x/vt CSI dispatcher (simplified for renderer use).
 */
final class CsiHandlerImpl implements CsiHandler
{
    private int $scrollTop = 0;
    private int $scrollBottom;

    private int $fg;
    private int $bg;
    private int $attrs = 0;

    /**
     * Truecolour (24-bit) slots of the pen, set only by SGR `38;2`/`48;2`.
     *
     * Mutually exclusive with the palette slots: any SGR that selects a
     * 16-/256-colour or default foreground clears {@see $fgTruecolor} (and the
     * background twins), so at most one representation is live per channel.
     * This closes the renderer's long-standing "no RGB slot" representation
     * limit — the emulator keeps the exact colour in its {@see Sgr}; the
     * renderer keeps it here (see {@see \SugarCraft\Vt\Cell::fgRgb()}).
     */
    private ?int $fgTruecolor = null;
    private ?int $bgTruecolor = null;

    /** Saved cursor for SCO SC/RC (CSI s / CSI u), with the pen. */
    private ?Cursor $savedCursor = null;
    private ?int $savedFg = null;
    private ?int $savedBg = null;
    private ?int $savedAttrs = null;
    private ?int $savedFgTruecolor = null;
    private ?int $savedBgTruecolor = null;

    /** Last printed graphic grapheme, replayed by REP (CSI b). */
    private string $lastPrintable = '';

    /**
     * DECAWM (`CSI ? 7 h/l`) — auto-wrap on at power-on, mirroring the
     * emulator's {@see \SugarCraft\Vt\Mode\Mode::$autoWrap} default (PR #1417
     * fixed the emulator to the xterm/VT500 default of ON; the renderer
     * previously had no DECAWM concept and always wrapped).
     */
    private bool $autoWrap = true;

    /**
     * Phantom-cell / deferred-wrap flag, mirroring the emulator's
     * {@see \SugarCraft\Vt\Handler\ScreenHandler::$wrapPending} and xterm's
     * `wrapnext`: a graphic that lands in the last column parks the cursor
     * there WITHOUT advancing; the next graphic consumes the pending line
     * break first. The flag is set by GEOMETRY alone so a mid-stream DECAWM
     * toggle behaves like xterm — `?7l` printing overwrites the last cell
     * (the flag re-arms), `?7h` resumes the deferred wrap.
     */
    private bool $wrapPending = false;

    /**
     * Colon-continuation flags PUSHED by the parser chain for the CSI sequence
     * currently being dispatched. {@see \SugarCraft\Vt\Parser\RendererHandler}
     * is the parser's sink in the supported wiring and forwards
     * {@see \SugarCraft\Ansi\Parser\SubparamsAwareHandler::setSubparams()} here,
     * so SGR can tell `CSI 4 : 3 m` (curly underline) from `CSI 4 ; 3 m`
     * (underline + italic). Null — nothing ever pushed, e.g. direct
     * construction or the raw candy-ansi HandlerAdapter — keeps SGR `4`
     * treating every parameter as an independent SGR, the renderer's
     * long-standing flat-list behaviour.
     *
     * @var list<bool>|null
     */
    private ?array $subparams = null;

    public function __construct(
        private Buffer $grid,
        private Cursor $cursor,
        private Theme $theme,
    ) {
        $this->fg = $theme->defaultFg;
        $this->bg = $theme->defaultBg;
        $this->scrollBottom = $grid->rows - 1;
    }

    public function grid(): Buffer
    {
        return $this->grid;
    }

    public function cursor(): Cursor
    {
        return $this->cursor;
    }

    /** Deferred-wrap (phantom-cell) state — mirrors the emulator's `Terminal\Terminal::isWrapPending()`. */
    public function wrapPending(): bool
    {
        return $this->wrapPending;
    }

    /**
     * Receive the ECMA-48 sub-parameter continuation flags for the parameter
     * list about to be dispatched. {@see RendererHandler} — the renderer path's
     * push-reachable {@see \SugarCraft\Ansi\Parser\SubparamsAwareHandler} sink —
     * forwards the parser's push here so SGR can tell `CSI 4 : 3 m` (curly
     * underline) from `CSI 4 ; 3 m` (underline + italic). Never pushed (direct
     * construction, or the raw candy-ansi HandlerAdapter) leaves {@see $subparams}
     * null and SGR keeps the flat-list behaviour.
     *
     * @param list<bool> $subparams
     */
    public function setSubparams(array $subparams): void
    {
        $this->subparams = $subparams;
    }

    public function printable(string $grapheme): void
    {
        if ($this->cursor->row < 0 || $this->cursor->row >= $this->grid->rows) {
            return;
        }

        $row = $this->cursor->row;
        $col = $this->cursor->col;

        $width = Width::string($grapheme);

        // Combining mark (width 0): attach to the host cell. Normally that is
        // the one before the cursor — but while a phantom cell is armed the
        // last graphic sits UNDER the cursor (parked at the margin), so the
        // mark belongs there (emulator: ScreenHandler::attachCombiningChar
        // keys the same way off wrapPending).
        if ($width <= 0) {
            $host = $this->wrapPending ? $col : $col - 1;
            if ($host >= 0) {
                $prev = $this->grid->cell($row, $host);
                $updated = new Cell(
                    char: $prev->char . $grapheme,
                    fg: $this->fg,
                    bg: $this->bg,
                    attrs: $this->attrs,
                    fgTruecolor: $this->fgTruecolor,
                    bgTruecolor: $this->bgTruecolor,
                    rendition: $prev->rendition,
                    // The host's OSC 8 link survives the rebuild — a combining
                    // mark must not silently un-link the glyph it decorates
                    // (the mark itself is appended to `char` above; the
                    // emulator appends in place and likewise keeps the link).
                    hyperlink: $prev->hyperlink,
                );
                $this->grid->put($row, $host, $updated);
            }
            return;
        }

        // Remember the last graphic grapheme so REP (CSI b) can replay it.
        $this->lastPrintable = $grapheme;

        // DECAWM wrap-through: a graphic landed in the last column and
        // deferred its advance; THIS graphic consumes it — move to
        // (row+1, 0), scrolling at the region bottom, before writing.
        // Mirrors {@see \SugarCraft\Vt\Handler\ScreenHandler::printChar()}.
        if ($this->wrapPending && $this->autoWrap) {
            $this->lineFeedWrap();
            $row = $this->cursor->row;
            $col = $this->cursor->col;
        }

        // If the character doesn't fit at all on this row: with DECAWM on,
        // take the deferred break and clamp if still too wide; with it off
        // the glyph is dropped and the cursor parks on the last column —
        // the phantom flag untouched (xterm cursor_off recomputes it only
        // when a cell is actually painted).
        if ($col + $width > $this->grid->cols) {
            if ($this->autoWrap) {
                $this->lineFeedWrap();
                $row = $this->cursor->row;
                $col = $this->cursor->col;
                if ($col + $width > $this->grid->cols) {
                    $this->cursor = $this->cursor->at($row, $this->grid->cols - 1);
                    $this->wrapPending = false;
                    return;
                }
            } else {
                $this->cursor = $this->cursor->at($row, $this->grid->cols - 1);
                return;
            }
        }

        // A graphic printed onto a line already carrying a DEC "hash" line
        // rendition inherits it: the stamp lives on the cells (so it scrolls
        // with the content), and re-writing one must not silently drop it.
        $rendition = $this->grid->cell($row, $col)->rendition;

        // DECDWL (`ESC # 6`) draws this line double-width: while the rendition
        // is DoubleWidth a single-cell glyph claims one extra column (base +
        // continuation). Only when the pair still fits; at the right margin the
        // glyph stays single-cell so the phantom-wrap geometry is untouched.
        $extra = $rendition === Rendition::DoubleWidth && $col + $width + 1 <= $this->grid->cols ? 1 : 0;

        // Write the character cell.
        $cell = new Cell(
            char: $grapheme,
            fg: $this->fg,
            bg: $this->bg,
            attrs: $this->attrs,
            fgTruecolor: $this->fgTruecolor,
            bgTruecolor: $this->bgTruecolor,
            rendition: $rendition,
        );
        $this->grid->put($row, $col, $cell);

        // Write continuation cells for wide characters (e.g. CJK, emoji) and
        // the DECDWL double-width bump. The natural-width tail blanks to the
        // shared empty cell (as the renderer always has); the extra DECDWL
        // column carries the line rendition so it stays observably wide.
        for ($i = 1; $i < $width; $i++) {
            $this->grid->put($row, $col + $i, Cell::empty());
        }
        for ($i = 0; $i < $extra; $i++) {
            $this->grid->put($row, $col + $width + $i, Cell::continuation($cell));
        }

        // Park on the last column with the phantom flag set instead of
        // advancing a full line; the advance happens on the next graphic
        // print (xterm `cursor_off` semantics).
        $nextCol = $col + $width + $extra;
        $this->cursor = $this->cursor->at($row, min($this->grid->cols - 1, $nextCol));
        $this->wrapPending = $nextCol >= $this->grid->cols;
    }

    public function cuu(int $count = 1): void
    {
        // Cursor motion disarms the deferred wrap (xterm reset_wrapnext);
        // clamp to the buffer, not the scroll region — matching the
        // emulator's CursorHandler with DECOM off (not modelled here).
        $this->wrapPending = false;
        $newRow = max(0, $this->cursor->row - $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cud(int $count = 1): void
    {
        $this->wrapPending = false;
        $newRow = min($this->grid->rows - 1, $this->cursor->row + $count);
        $this->cursor = $this->cursor->at($newRow, $this->cursor->col);
    }

    public function cuf(int $count = 1): void
    {
        $this->wrapPending = false;
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cub(int $count = 1): void
    {
        // Also the BS route (HandlerAdapter maps 0x08 here) — the emulator's
        // backspace moves out of the phantom cell the same way.
        $this->wrapPending = false;
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cup(int $row, int $col): void
    {
        $row = $row < 1 ? 1 : $row;
        $col = $col < 1 ? 1 : $col;

        // Absolute screen coordinates clamped to the buffer — mirroring the
        // emulator's CursorHandler::cup (DECOM off). The renderer's former
        // scroll-region clamping was a catalogued divergence: `CSI 1;1H`
        // under `CSI 2;4r` belongs on line 1, not yanked into the region.
        $absRow = max(0, min($this->grid->rows - 1, $row - 1));
        $absCol = max(0, min($this->grid->cols - 1, $col - 1));

        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($absRow, $absCol);
    }

    public function hvp(int $row, int $col): void
    {
        $this->cup($row, $col);
    }

    public function sgr(array $params): void
    {
        if (empty($params)) {
            $params = [0];
        }

        $subs = $this->subparams;
        $i = 0;
        $paramCount = count($params);
        while ($i < $paramCount) {
            $p = (int) $params[$i];
            if ($p === -1) {
                $p = 0;
            }

            // The parser's contract is `list<int>` (candy-ansi `Parser::$params`,
            // -1 default slots included), so the former per-iteration
            // intval-normalisation ran on provably-integer input — dead
            // allocation (E736 6.1).
            [$this->fg, $this->bg, $this->attrs, $i] = $this->applySgrParam(
                $p,
                $params,
                $i,
                $subs,
            );

            $this->trackTruecolorPen($p);
        }
    }

    /**
     * Keep the pen's truecolour slots consistent with the palette channel just
     * written. A 16-/256-colour or default selection supersedes an RGB slot on
     * the same channel (they are mutually exclusive); SGR 0 clears both. The
     * 38/48 arms own their channel's truecolour inside
     * {@see sgrExtended()} and are deliberately skipped here.
     */
    private function trackTruecolorPen(int $p): void
    {
        if ($p === 38 || $p === 48) {
            return;
        }

        if ($p === 0 || $p === 39 || ($p >= 30 && $p <= 37) || ($p >= 90 && $p <= 97)) {
            $this->fgTruecolor = null;
        }

        if ($p === 0 || $p === 49 || ($p >= 40 && $p <= 47) || ($p >= 100 && $p <= 107)) {
            $this->bgTruecolor = null;
        }
    }

    /**
     * Clamp a parser slot to a byte, mapping the default sentinel (-1) to 0 —
     * the identical read the emulator's {@see \SugarCraft\Vt\Handler\SgrHandler}
     * uses both when packing a truecolour triplet and when resolving a 256-color
     * `38;5;n` index, so both engines store the same value from the same bytes
     * (an out-of-range index such as `38;5;300` clamps to 255 on each).
     */
    private function resolveByte(int $value): int
    {
        if ($value === -1) {
            return 0;
        }

        return max(0, min(255, $value));
    }

    private function packRgb(int $r, int $g, int $b): int
    {
        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * @param list<int> $params
     * @param list<bool>|null $subs Parser colon-continuation flags (null = no parser attached).
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applySgrParam(int $p, array $params, int $i, ?array $subs): array
    {
        return match (true) {
            $p === 0 => [$this->theme->defaultFg, $this->theme->defaultBg, 0, $i + 1],
            $p === 1 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_BOLD, $i + 1],
            $p === 3 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_ITALIC, $i + 1],
            // `CSI 4 : 3 m` is ONE parameter (curly underline); `CSI 4 ; 3 m`
            // is TWO independent SGRs (underline + italic). The candy-ansi
            // parser flattens both to [4, 3]; only the continuation flags
            // distinguish them. Without a wired parser the renderer keeps its
            // historical flat reading. Styles beyond single have no bit in
            // this cell model — they all light ATTR_UNDERLINE, matching the
            // emulator's normalised `underline || style !== None`.
            $p === 4 => $this->sgrUnderline($params, $i, $subs),
            $p === 7 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_INVERSE, $i + 1],
            $p === 9 => [$this->fg, $this->bg, $this->attrs | Cell::ATTR_STRIKETHROUGH, $i + 1],
            $p === 22 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_BOLD & ~0x20000, $i + 1],
            $p === 23 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_ITALIC, $i + 1],
            $p === 24 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_UNDERLINE, $i + 1],
            $p === 27 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_INVERSE, $i + 1],
            // 25/28 reset blink/hidden — this cell model has no such bits and
            // the pen never lights them, so they are parity no-ops. 21
            // (double underline in xterm; a no-op in the emulator too —
            // charm folds it nowhere) falls through identically.
            $p === 29 => [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_STRIKETHROUGH, $i + 1],

            $p >= 30 && $p <= 37 => [$p - 30, $this->bg, $this->attrs, $i + 1],
            $p >= 40 && $p <= 47 => [$this->fg, $p - 40, $this->attrs, $i + 1],
            $p === 39 => [$this->theme->defaultFg, $this->bg, $this->attrs, $i + 1],
            $p === 49 => [$this->fg, $this->theme->defaultBg, $this->attrs, $i + 1],

            // The BRIGHT halves, palette slots 8-15. Missing until now, and
            // silently: an unhandled parameter falls through to `default`, which
            // keeps the PREVIOUS colour, so `\e[31mX\e[90mB` put both cells at
            // fg 1 and every assertion about a bright colour in this emulator
            // was measuring the colour before it. That reaches further than the
            // cell grid — candy-vcr's TapeToGif renders through this handler, so
            // a GIF painted the wrong colour too. Sibling
            // {@see \SugarCraft\Vt\Handler\SgrHandler} has always had these two
            // arms; the two SGR tables had simply diverged.
            $p >= 90 && $p <= 97 => [$p - 90 + 8, $this->bg, $this->attrs, $i + 1],
            $p >= 100 && $p <= 107 => [$this->fg, $p - 100 + 8, $this->attrs, $i + 1],

            $p === 38 => $this->sgrExtended($params, $i, $subs, fg: true),
            $p === 48 => $this->sgrExtended($params, $i, $subs, fg: false),
            // Underline colour — neither this pen nor the emulator's Sgr
            // model stores it; both CONSUME the extended triplet so its
            // components cannot masquerade as independent SGRs (the old
            // default-arm let `58;5;33` repaint the fg green on both paths).
            $p === 58 => $this->sgrExtendedDiscard($params, $i, $subs),
            $p === 59 => [$this->fg, $this->bg, $this->attrs, $i + 1],

            default => [$this->fg, $this->bg, $this->attrs, $i + 1],
        };
    }

    /**
     * SGR 4 with optional colon sub-parameter — see the table arm.
     *
     * @param list<int> $params
     * @param list<bool>|null $subs
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrUnderline(array $params, int $i, ?array $subs): array
    {
        $colon = $subs !== null && ($subs[$i] ?? false);
        if ($colon) {
            // Mirror of SgrHandler::underlineStyle branch table: consume the
            // sub-parameter; every defined and unknown style lights the one
            // underline bit this cell model has; `4:0` clears it.
            $sub = $params[$i + 1] ?? -1;
            if ($sub === 0) {
                return [$this->fg, $this->bg, $this->attrs & ~Cell::ATTR_UNDERLINE, $i + 2];
            }
            if ($sub === -1) {
                // `CSI 4 : m` — empty sub-parameter; the emulator reads it as
                // plain single underline and leaves the default slot to the
                // next step (which resets it as SGR 0). Stay byte-identical.
                return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 1];
            }
            return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 2];
        }
        // Semicolon or flat form: plain underline; the next parameter is an
        // independent SGR (pre-fix behaviour preserved exactly when no
        // parser is attached).
        return [$this->fg, $this->bg, $this->attrs | Cell::ATTR_UNDERLINE, $i + 1];
    }

    /**
     * Handle 38;5;n (256-color) / 38;2;r;g;b (truecolor) and their 48;
     * background twins.
     *
     * The `;5;n` forms write a resolved palette index into the pen's fg/bg
     * slot and CLEAR that channel's truecolour (a palette colour supersedes an
     * RGB one). The `;2;r;g;b` truecolour form packs the triplet into
     * {@see $fgTruecolor}/{@see $bgTruecolor} — the renderer-side RGB slot this
     * model previously lacked — so the exact colour survives to the cell and
     * matches what the emulator stores in its {@see Sgr} (VtParityTest now
     * asserts agreement rather than the old value-only divergence).
     *
     * Before the consumption arms existed, `38;2;1;2;3` fell through as five
     * independent SGRs (2=…, 3=italic) and corrupted the pen; that misparse is
     * gone. The triplet's byte slots are consumed either way.
     *
     * Also takes the ECMA-48 colon forms — `38:5:N`, `38:2:R:G:B`,
     * `38:2::R:G:B`, `38:2:CS:R:G:B` — whose whole specification rides in ONE
     * parameter group (the parser flattens the colons; the continuation flags
     * mark them). The group's slots are consumed to its end either way so a
     * trailing component can never replay as an independent SGR; kind 2's
     * optional colour-space sub-parameter (5 slots = no CS, 6 = CS first,
     * exactly as xterm's `have > 4` offset at charproc.c:2142-2146 and tmux's
     * `n == 5 ? 2 : 3` at input.c:2362-2365) is skipped before reading the
     * triplet — the same group layout the emulator's
     * {@see \SugarCraft\Vt\Handler\SgrHandler::extendedColon()} decodes.
     *
     * @param list<int> $params
     * @param list<bool>|null $subs
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrExtended(array $params, int $i, ?array $subs, bool $fg): array
    {
        if ($subs !== null && ($subs[$i] ?? false) === true) {
            $end = $this->colonGroupEnd($i, $params, $subs);
            $next = $end + 1;
            $group = array_slice($params, $i, $end - $i + 1);
            $kind = $params[$i + 1] ?? -1;
            if ($kind === 5) {
                // The index must live INSIDE the group: `38:5;3` has no slot
                // of its own after the kind, and the following 3 is a
                // separate SGR — mirroring the emulator's group-scoped read.
                $index = $this->resolveByte($end >= $i + 2 ? $params[$i + 2] : 0);
                $this->clearTruecolor($fg);

                return $fg
                    ? [$index, $this->bg, $this->attrs, $next]
                    : [$this->fg, $index, $this->attrs, $next];
            }
            if ($kind === 2 && count($group) >= 5) {
                $o = count($group) === 5 ? 2 : 3;
                $this->setTruecolor($fg, $this->resolveByte($group[$o] ?? -1), $this->resolveByte($group[$o + 1] ?? -1), $this->resolveByte($group[$o + 2] ?? -1));
            }
            // Kind 2 stored its RGB above; any other kind just consumed the
            // group without disturbing the palette channel.
            return [$this->fg, $this->bg, $this->attrs, $next];
        }
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            $index = $this->resolveByte($params[$i + 2] ?? 0);
            $this->clearTruecolor($fg);
            if ($fg) {
                return [$index, $this->bg, $this->attrs, $i + 3];
            } else {
                return [$this->fg, $index, $this->attrs, $i + 3];
            }
        }
        if ($kind === 2) {
            // Truecolor triplet: pack r;g;b into the channel's RGB slot; the
            // palette slot stays where it is (the RGB takes precedence at read
            // time via Cell::fgRgb()/bgRgb()).
            $this->setTruecolor($fg, $this->resolveByte($params[$i + 2] ?? -1), $this->resolveByte($params[$i + 3] ?? -1), $this->resolveByte($params[$i + 4] ?? -1));

            return [$this->fg, $this->bg, $this->attrs, $i + 5];
        }
        return [$this->fg, $this->bg, $this->attrs, $i + 1];
    }

    /** Store a packed truecolour value on the requested channel. */
    private function setTruecolor(bool $fg, int $r, int $g, int $b): void
    {
        if ($fg) {
            $this->fgTruecolor = $this->packRgb($r, $g, $b);
        } else {
            $this->bgTruecolor = $this->packRgb($r, $g, $b);
        }
    }

    /** Drop the requested channel's truecolour (a palette/default colour wins). */
    private function clearTruecolor(bool $fg): void
    {
        if ($fg) {
            $this->fgTruecolor = null;
        } else {
            $this->bgTruecolor = null;
        }
    }

    /**
     * Last flat slot index of the colon group starting at $i — identical
     * reading of {@see \SugarCraft\Ansi\Parser\Parser::subparams()} as the
     * emulator's {@see \SugarCraft\Vt\Handler\SgrHandler}.
     *
     * @param list<int> $params
     * @param list<bool> $subs
     */
    private function colonGroupEnd(int $i, array $params, array $subs): int
    {
        $end = $i;
        $n = count($params);
        while ($end + 1 < $n && ($subs[$end] ?? false) === true) {
            $end++;
        }
        return $end;
    }

    /**
     * Handle 58 (underline colour): parse-and-discard the extended form so
     * its components never run as independent SGRs — colon groups included
     * (consume to the group end, mirroring {@see sgrExtended()}).
     * 59 resets it — also a no-op here, as the pen stores nothing to reset.
     *
     * @param list<int> $params
     * @param list<bool>|null $subs
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function sgrExtendedDiscard(array $params, int $i, ?array $subs): array
    {
        if ($subs !== null && ($subs[$i] ?? false) === true) {
            return [$this->fg, $this->bg, $this->attrs, $this->colonGroupEnd($i, $params, $subs) + 1];
        }
        $kind = $params[$i + 1] ?? -1;
        if ($kind === 5) {
            return [$this->fg, $this->bg, $this->attrs, $i + 3];
        }
        if ($kind === 2) {
            return [$this->fg, $this->bg, $this->attrs, $i + 5];
        }
        return [$this->fg, $this->bg, $this->attrs, $i + 1];
    }

    public function gridRows(): int
    {
        return $this->grid->rows;
    }

    public function gridCols(): int
    {
        return $this->grid->cols;
    }

    public function ed(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($r = $row; $r < $this->grid->rows; $r++) {
                for ($c = ($r === $row ? $col : 0); $c < $this->grid->cols; $c++) {
                    $this->grid->put($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 1) {
            for ($r = 0; $r <= $row; $r++) {
                $endCol = $r === $row ? $col + 1 : $this->grid->cols;
                for ($c = 0; $c < $endCol; $c++) {
                    $this->grid->put($r, $c, Cell::empty());
                }
            }
        } elseif ($mode === 2) {
            $this->grid = $this->grid->clear();
        }
    }

    public function el(int $mode = 0): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;

        if ($mode === 0) {
            for ($c = $col; $c < $this->grid->cols; $c++) {
                $this->grid->put($row, $c, Cell::empty());
            }
        } elseif ($mode === 1) {
            for ($c = 0; $c <= $col; $c++) {
                $this->grid->put($row, $c, Cell::empty());
            }
        } elseif ($mode === 2) {
            for ($c = 0; $c < $this->grid->cols; $c++) {
                $this->grid->put($row, $c, Cell::empty());
            }
        }
    }

    public function decset(int $mode, int $prefix = 0): void
    {
        // DEC private modes only — the emulator forwards `CSI h` without the
        // '?' prefix nowhere (see ScreenHandler case 'h'), and the renderer
        // must ignore the bare form identically.
        if ($prefix !== 0x3F /* '?' */) {
            return;
        }
        // DECTCEM: `CSI ? 25 h` SHOWS the cursor (set = enabled = visible).
        // The old arms had this inverted — a catalogued divergence (the grid
        // never noticed; only the cursor state did).
        match ($mode) {
            7 => $this->autoWrap = true,   // DECAWM on: deferred wrap resumes
            25 => $this->cursor = $this->cursor->shown(),
            default => null,               // Mouse/other modes: no grid effect.
        };
    }

    public function decrst(int $mode, int $prefix = 0): void
    {
        if ($prefix !== 0x3F /* '?' */) {
            return;
        }
        // DECAWM off: printing at the right margin overwrites the last cell
        // instead of wrapping — the phantom flag re-arms by geometry but is
        // never consumed while the mode stays off (xterm behaviour).
        match ($mode) {
            7 => $this->autoWrap = false,
            25 => $this->cursor = $this->cursor->hidden(),
            default => null,
        };
    }

    public function decstbm(int $top, int $bottom): void
    {
        if ($top < 1) {
            $top = 1;
        }
        if ($bottom > $this->grid->rows) {
            $bottom = $this->grid->rows;
        }
        if ($top > $bottom) {
            return;
        }

        $this->scrollTop = $top - 1;
        $this->scrollBottom = $bottom - 1;

        // VT500 §DECSTBM homes the cursor on success (emulator
        // ScreenHandler::setScrollRegion parity, w4-vt): page home (0,0) —
        // the renderer models no DECOM, so there is no origin-adjusted
        // variant — and the phantom cell is dropped with the move. An
        // invalid (top > bottom) region took the early return and stays put.
        $this->cursor = $this->cursor->at(0, 0);
        $this->wrapPending = false;
    }

    public function tbc(int $mode = 0): void
    {
        // No-op — tab clear is out of scope for the renderer path; $mode
        // is part of the CsiHandler contract, reserved for a future impl.
    }

    public function cbt(int $count = 1): void
    {
        // Tab-family motion deliberately leaves the phantom flag armed —
        // the emulator's HT/CHT/CBT route does (xterm _wrapnext survives).
        $newCol = max(0, $this->cursor->col - $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    public function cht(int $count = 1): void
    {
        $newCol = min($this->grid->cols - 1, $this->cursor->col + $count);
        $this->cursor = $this->cursor->at($this->cursor->row, $newCol);
    }

    /**
     * CR — carriage return: move cursor to column 0 (row unchanged).
     * Column motion disarms the deferred wrap (xterm carriage_return).
     */
    public function cr(): void
    {
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($this->cursor->row, 0);
    }

    /**
     * LF — line feed: advance cursor down one row, scrolling the
     * scroll region if the cursor is at scrollBottom.
     * Handles VT (0x0B) and FF (0x0C) the same way.
     * Vertical motion consumes the phantom cell (emulator index()).
     */
    public function lf(): void
    {
        $this->wrapPending = false;
        if ($this->cursor->row >= $this->scrollBottom) {
            $this->scrollUp(1);
        } else {
            $this->cursor = $this->cursor->at($this->cursor->row + 1, $this->cursor->col);
        }
    }

    /**
     * SU — Scroll Up (CSI S). Scroll the scroll region up $count lines,
     * introducing blank lines at the bottom. Cursor position unchanged.
     */
    public function su(int $count = 1): void
    {
        $this->scrollUp(max(1, $count));
    }

    /**
     * SD — Scroll Down (CSI T). Scroll the scroll region down $count lines,
     * introducing blank lines at the top. Cursor position unchanged.
     */
    public function sd(int $count = 1): void
    {
        $this->scrollDown(max(1, $count));
    }

    /**
     * IL — Insert Line (CSI L). Insert $count blank lines at the cursor row,
     * shifting existing lines down within the scroll region. No-op when the
     * cursor sits outside the scroll region. On success the cursor homes to
     * column 0 on its row — matching the emulator's insertLines() (VT500 §IL
     * "cursor to home position"; charm keeps the phantom armed against the OLD
     * margin, so column-home disarms it — see ScreenHandler::insertLines).
     */
    public function il(int $count = 1): void
    {
        $row = $this->cursor->row;
        if ($row < $this->scrollTop || $row > $this->scrollBottom) {
            return;
        }
        $count = min(max(1, $count), $this->scrollBottom - $row + 1);
        $cols = $this->grid->cols;

        for ($r = $this->scrollBottom; $r >= $row + $count; $r--) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->put($r, $c, $this->grid->cell($r - $count, $c));
            }
        }
        for ($r = $row; $r < $row + $count; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->put($r, $c, Cell::empty());
            }
        }
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($row, 0);
    }

    /**
     * DL — Delete Line (CSI M). Delete $count lines at the cursor row,
     * shifting lines below up within the scroll region. No-op when the
     * cursor sits outside the scroll region. Cursor homes to column 0 like
     * {@see il()}. (The emulator additionally pushes deleted rows into its
     * scrollback when the region spans the screen; the renderer has no
     * scrollback, so its visible-grid edit is identical.)
     */
    public function dl(int $count = 1): void
    {
        $row = $this->cursor->row;
        if ($row < $this->scrollTop || $row > $this->scrollBottom) {
            return;
        }
        $count = min(max(1, $count), $this->scrollBottom - $row + 1);
        $cols = $this->grid->cols;

        for ($r = $row; $r <= $this->scrollBottom - $count; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->put($r, $c, $this->grid->cell($r + $count, $c));
            }
        }
        for ($r = $this->scrollBottom - $count + 1; $r <= $this->scrollBottom; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $this->grid->put($r, $c, Cell::empty());
            }
        }
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($row, 0);
    }

    /**
     * ICH — Insert Character (CSI @). Insert $count blank cells at the cursor,
     * shifting the rest of the line right; cells pushed past the right edge
     * are dropped. Cursor position unchanged.
     */
    public function ich(int $count = 1): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;
        $cols = $this->grid->cols;
        $count = min(max(1, $count), $cols - $col);
        if ($count <= 0) {
            return;
        }

        for ($c = $cols - 1; $c >= $col + $count; $c--) {
            $this->grid->put($row, $c, $this->grid->cell($row, $c - $count));
        }
        for ($c = $col; $c < $col + $count; $c++) {
            $this->grid->put($row, $c, Cell::empty());
        }
    }

    /**
     * DCH — Delete Character (CSI P). Delete $count cells at the cursor,
     * shifting the rest of the line left and blanking the vacated right edge.
     * Cursor position unchanged.
     */
    public function dch(int $count = 1): void
    {
        $row = $this->cursor->row;
        $col = $this->cursor->col;
        $cols = $this->grid->cols;
        $count = min(max(1, $count), $cols - $col);
        if ($count <= 0) {
            return;
        }

        for ($c = $col; $c < $cols - $count; $c++) {
            $this->grid->put($row, $c, $this->grid->cell($row, $c + $count));
        }
        for ($c = $cols - $count; $c < $cols; $c++) {
            $this->grid->put($row, $c, Cell::empty());
        }
    }

    /**
     * REP — Repeat (CSI b). Replay the last printed graphic grapheme $count
     * times. No-op when nothing printable has been emitted yet.
     */
    public function rep(int $count = 1): void
    {
        if ($this->lastPrintable === '') {
            return;
        }
        $grapheme = $this->lastPrintable;
        $count = max(1, $count);
        for ($i = 0; $i < $count; $i++) {
            $this->printable($grapheme);
        }
    }

    /**
     * SCOSC — SCO Save Cursor (CSI s). Remembers the cursor position AND
     * the active pen: the emulator's GENERAL DECSC slot restores position +
     * rendition + designations (w4-vt), and the renderer's expressive range
     * for that snapshot is the fg/bg/attrs triple.
     */
    public function scosc(): void
    {
        $this->savedCursor = $this->cursor;
        $this->savedFg = $this->fg;
        $this->savedBg = $this->bg;
        $this->savedAttrs = $this->attrs;
        $this->savedFgTruecolor = $this->fgTruecolor;
        $this->savedBgTruecolor = $this->bgTruecolor;
    }

    /**
     * SCORC — SCO Restore Cursor (CSI u). Restore the cursor (and pen) saved
     * by SCOSC; no-op when nothing was saved. A position change on the way
     * back disarms the phantom cell (emulator parity: ScreenHandler routes
     * CSI s/CSI u to saveCursor()/restoreCursor() directly, DECRC clearing
     * the wrap flag the same way graphic-position moves do). When the slot is
     * empty (no prior SCOSC) the cursor STAYS PUT here — the emulator's
     * `Cursor::restore()` does the same (`savedRow ?? row`); xterm homes to
     * 0,0 instead, a deliberate and parity-safe divergence on both engines.
     *
     * Visibility and shape stay live — the emulator's DECRC restores the
     * GENERAL slot's position/rendition/SCS/DECOM, not cursor blink state
     * (`Cursor\Cursor::restore()` carries savedRow/savedCol alone), so a
     * `CSI s` … `CSI ? 25 l` … `CSI u` keeps the cursor hidden.
     *
     * The truecolour slots restore unconditionally alongside the palette
     * triple: `null` is a meaningful value (channel is palette/default), so a
     * null-sentinel guard would wrongly skip clearing a stale RGB on restore.
     */
    public function scorc(): void
    {
        if ($this->savedCursor !== null) {
            $this->cursor = $this->cursor->at($this->savedCursor->row, $this->savedCursor->col);
            if ($this->savedFg !== null && $this->savedBg !== null && $this->savedAttrs !== null) {
                $this->fg = $this->savedFg;
                $this->bg = $this->savedBg;
                $this->attrs = $this->savedAttrs;
                $this->fgTruecolor = $this->savedFgTruecolor;
                $this->bgTruecolor = $this->savedBgTruecolor;
            }
        }
        $this->wrapPending = false;
    }

    /**
     * Consume a pending line break: column 0 of the next row, scrolling the
     * region when already at its bottom. Mirrors the emulator's
     * ScreenHandler::lineFeedNext() — the phantom flag is cleared here.
     */
    private function lineFeedWrap(): void
    {
        $this->wrapPending = false;
        $this->cursor = $this->cursor->at($this->cursor->row, 0);
        if ($this->cursor->row >= $this->scrollBottom) {
            $this->scrollUp(1);
            return;
        }
        $this->cursor = $this->cursor->at($this->cursor->row + 1, 0);
    }

    private function scrollUp(int $count): void
    {
        $height = $this->scrollBottom - $this->scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->scrollUpOne();
        }
    }

    private function scrollUpOne(): void
    {
        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;
        $cols = $this->grid->cols;

        for ($r = $top; $r < $bottom; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $next = $this->grid->cell($r + 1, $c);
                $this->grid->put($r, $c, $next);
            }
        }

        for ($c = 0; $c < $cols; $c++) {
            $this->grid->put($bottom, $c, Cell::empty());
        }
    }

    private function scrollDown(int $count): void
    {
        $height = $this->scrollBottom - $this->scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->scrollDownOne();
        }
    }

    private function scrollDownOne(): void
    {
        $top = $this->scrollTop;
        $bottom = $this->scrollBottom;
        $cols = $this->grid->cols;

        for ($r = $bottom; $r > $top; $r--) {
            for ($c = 0; $c < $cols; $c++) {
                $prev = $this->grid->cell($r - 1, $c);
                $this->grid->put($r, $c, $prev);
            }
        }

        for ($c = 0; $c < $cols; $c++) {
            $this->grid->put($top, $c, Cell::empty());
        }
    }

    // ---------------------------------------------------------------------
    // ESC dispatch (renderer path) — reached through
    // {@see \SugarCraft\Vt\Parser\RendererHandler}, which closes the gap that
    // candy-ansi's HandlerAdapter left open: its escDispatch() was an empty
    // no-op, so none of these two-byte sequences ever touched the renderer.
    // Each mirrors the emulator's {@see \SugarCraft\Vt\Handler\ScreenHandler}
    // equivalent within the renderer's expressive range (position + pen +
    // line rendition; no charsets/modes/scrollback/alt-screen).
    // ---------------------------------------------------------------------

    /**
     * ESC D — IND (index): the vertical twin of LF. Route to
     * {@see lf()}, which shares the emulator's `index()` behaviour (consume the
     * phantom cell, scroll the region at its bottom edge).
     */
    public function escIndex(): void
    {
        $this->lf();
    }

    /**
     * ESC E — NEL (next line): carriage return + index. Mirrors the emulator's
     * {@see \SugarCraft\Vt\Handler\ScreenHandler::nextLine()}.
     */
    public function escNextLine(): void
    {
        $this->cr();
        $this->lf();
    }

    /**
     * ESC M — RI (reverse index): up one line, scrolling the region down when
     * already at the top. Vertical-only motion: like the emulator, the phantom
     * cell is deliberately left armed here.
     */
    public function escReverseIndex(): void
    {
        if ($this->cursor->row <= $this->scrollTop) {
            $this->scrollDown(1);

            return;
        }
        $this->cursor = $this->cursor->at($this->cursor->row - 1, $this->cursor->col);
    }

    /**
     * ESC H — HTS (hard tab stop). The renderer models no tab-stop table
     * ({@see tbc()} and {@see cbt()} move by count, not stops), so this is a
     * documented no-op — there is no per-column stop state to set.
     */
    public function escSetTabStop(): void
    {
        // No tab-stop model on the renderer path.
    }

    /**
     * ESC 7 — DECSC (save cursor). Same GENERAL slot as SCO `CSI s`
     * ({@see scosc()}): position + pen triple + truecolour. The renderer
     * stores no charsets/DECOM, so its snapshot is the expressive-range subset
     * the emulator's save also collapses to under grid normalisation.
     */
    public function escSaveCursor(): void
    {
        $this->scosc();
    }

    /** ESC 8 — DECRC (restore cursor); twin of {@see escSaveCursor()}. */
    public function escRestoreCursor(): void
    {
        $this->scorc();
    }

    /**
     * ESC c — RIS (hard reset). Clears the grid, restores the cursor to its
     * power-on value object (home + DECTCEM visible + default shape — the
     * emulator's `hardReset()` builds a `new Cursor()`; homing with
     * {@see Cursor::at()} kept a `CSI ? 25 l` hide across the reset), drops the
     * pen (palette + truecolour + the DECSC slot) and the REP memory, restores
     * the full-page scroll region and re-enables DECAWM — the renderer's
     * expressive-range match for the emulator's
     * {@see \SugarCraft\Vt\Handler\ScreenHandler::hardReset()}.
     * Scrollback/alt-screen/charsets/tab-stops have no renderer counterpart.
     * Clearing {@see $lastPrintable} is renderer-local hygiene: the emulator
     * never dispatches REP at all, so post-RIS `CSI b` printing nothing is the
     * agreement xterm/VT510 full-reset semantics demand ("no graphic was the
     * last printable").
     */
    public function escResetToInitialState(): void
    {
        $this->grid = $this->grid->clear();
        $this->cursor = new Cursor();
        $this->wrapPending = false;
        $this->autoWrap = true;
        $this->scrollTop = 0;
        $this->scrollBottom = $this->grid->rows - 1;
        $this->fg = $this->theme->defaultFg;
        $this->bg = $this->theme->defaultBg;
        $this->attrs = 0;
        $this->fgTruecolor = null;
        $this->bgTruecolor = null;
        $this->lastPrintable = '';
        $this->savedCursor = null;
        $this->savedFg = null;
        $this->savedBg = null;
        $this->savedAttrs = null;
        $this->savedFgTruecolor = null;
        $this->savedBgTruecolor = null;
    }

    /**
     * ESC # {3,4,5,6} — the DEC "hash" line-rendition family.
     *
     * Applies to the CURSOR LINE: every cell of the current row is re-stamped
     * with the selected {@see Rendition}, so the flag round-trips through the
     * grid (and travels with the content under scroll). DECDWL additionally
     * makes glyphs on the line claim an extra column — honoured at print time
     * by {@see printable()} reading the stamped rendition back off the cell.
     *
     * Unknown finals (including `# 8` DECALN, which is emulator-only) fall to
     * the `default` arm and are ignored here on purpose — the renderer carries
     * no alignment-test state, so there is nothing for `# 8` to touch. Erase
     * paths (ED/EL/IL/DL) write blank cells and therefore clear a line's
     * rendition mid-op, identically on both engines (a documented, parity-safe
     * divergence from xterm, which keeps the line attribute).
     *
     * @param int $final byte after `ESC #`: 0x33 DECDHL top, 0x34 DECDHL
     *   bottom, 0x35 DECSWL (single, → {@see Rendition::None}), 0x36 DECDWL.
     */
    public function escLineRendition(int $final): void
    {
        $rendition = match ($final) {
            0x33 /* '3' */ => Rendition::DoubleTop,
            0x34 /* '4' */ => Rendition::DoubleBottom,
            0x35 /* '5' */ => Rendition::None,
            0x36 /* '6' */ => Rendition::DoubleWidth,
            default => null,
        };
        if ($rendition === null) {
            return;
        }

        $row = $this->cursor->row;
        if ($row < 0 || $row >= $this->grid->rows) {
            return;
        }
        $cols = $this->grid->cols;
        for ($c = 0; $c < $cols; $c++) {
            $this->grid->put($row, $c, $this->grid->cell($row, $c)->withRendition($rendition));
        }
    }
}
