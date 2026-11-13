<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style as SugarStyle;

/**
 * Pure view function: takes a {@see Game} and returns the framebuffer
 * string. No I/O, no caching — every call rebuilds from scratch so
 * snapshot tests can assert against the output directly.
 *
 * Rendering uses a {@see Buffer} for the minefield: each cell is
 * zone-tagged via {@see Mark::zone()} using "cell:$row:$col" so
 * {@see Scanner::hit()} can resolve mouse coordinates to cell
 * positions on click/flag events.
 *
 * Mirrors maxpaulus43/go-sweep — Minesweeper renderer.
 */
final class Renderer
{
    // Adjacent count → colour (0xRRGGBB).
    private const ADJ_COLORS = [
        1 => 0x7dd3fc,  // sky blue
        2 => 0x6ee7b7,  // mint
        3 => 0xfde68a,  // lemon
        4 => 0xa78bfa,  // grape
        5 => 0xff8caa,  // pink
        6 => 0xfcd5b4,  // peach
        7 => 0xfb923c,  // orange
        8 => 0xff5f87,  // hot pink
    ];

    /**
     * The same ladder darkened for light terminals (see
     * {@see lightBackground()}): the pastels above ride ~1-3:1 against
     * white — invisible. Every entry here clears WCAG 4.5:1 on #ffffff
     * (LightPaletteTest recomputes the ratios from the rendered bytes), and
     * the hue order keeps Windows-classic memory: 1 blue, 2 green, 3 red,
     * 4 purple, 5 brown, 6 teal, 7 slate, 8 grey.
     */
    private const ADJ_COLORS_LIGHT = [
        1 => 0x1d4ed8,  // blue   6.3:1
        2 => 0x15803d,  // green  4.6:1
        3 => 0xb91c1c,  // red    6.0:1
        4 => 0x6b21a8,  // purple 9.5:1
        5 => 0x92400e,  // brown  7.3:1
        6 => 0x0f766e,  // teal   5.2:1
        7 => 0x1f2937,  // slate 12.6:1
        8 => 0x4b5563,  // grey   7.6:1
    ];

    /**
     * Geometry coupling between {@see frame()} and mouse hit-testing: the
     * rounded border costs 1 cell and the padding(0,1) layer 1 more, so the
     * first interior cell glyph sits at 0-based terminal coordinates
     * (2, 1). {@see Game::onMouse()} subtracts these constants to translate
     * 1-based MouseMsg positions into the Scanner's 1-based interior
     * coordinates — and {@see frame()} indents the status/footer by the
     * column offset so text lines up with the first interior column. Any
     * frame change (double border, wider padding) moves the click mapping
     * with it; it cannot silently drift.
     */
    public const INTERIOR_COL_OFFSET = 2;
    public const INTERIOR_ROW_OFFSET = 1;

    public static function render(Game $g): string
    {
        return self::frame($g, self::interior($g, mark: false));
    }

    /**
     * Test/host seam pinning whether the minefield may paint colour,
     * independent of the ambient environment (hermeticity): null consults
     * the fleet authority {@see ColorProfile}, true/false forces the answer.
     * The suite arms TRUE from tests/bootstrap.php so the goldens and every
     * colour-substring pin see the same bytes on any runner, while
     * {@see NoColorTest} flips the knob to prove the strip. Production code
     * never calls this; the binary routes capability through
     * {@see ColorProfile::detect()} at boot.
     */
    private static ?bool $boardColourOverride = null;

    public static function setBoardColourOverride(?bool $on): void
    {
        self::$boardColourOverride = $on;
    }

    private static function colourBoards(): bool
    {
        return self::$boardColourOverride ?? ColorProfile::detect()->supportsAnsi();
    }

    /**
     * Terminal-background probe for palette selection: COLORFGBG's BOTTOM
     * field declares the background colour index, and index 7 is the light
     * grey/white every classic terminal assigns it. Unset, malformed, or any
     * other answer means "dark" — which keeps every shipped byte sequence
     * identical on hosts that never declare a light background (zero golden
     * movement by default).
     */
    private static function lightBackground(): bool
    {
        $value = getenv('COLORFGBG');
        if ($value === false || !preg_match('/(?:^|;)(\d+)$/', $value, $m)) {
            return false;
        }
        return (int) $m[1] === 7;
    }

    /**
     * Choose between the dark-host ink and its light-host twin (a pale
     * pastel on a pale background is not a decoration, it is a blink).
     */
    private static function ink(string $darkHex, string $lightHex): string
    {
        return self::lightBackground() ? $lightHex : $darkHex;
    }

    /**
     * Build the minefield ANSI string.
     *
     * When $mark is true each glyph is wrapped in a {@see Mark::zone()}
     * sentinel for {@see Scanner} hit-testing. Those sentinels embed a
     * plain-ASCII id ("cell:r:c") that {@see Scanner} skips but that the
     * Buffer/Width layer would otherwise count as visible width — which
     * leaks the id text on screen and inflates the border box. So the
     * displayed board is always built unmarked; only the throwaway copy
     * fed to the Scanner carries zone sentinels.
     */
    private static function interior(Game $g, bool $mark): string
    {
        $b = $g->board;
        $boardBuf = Buffer::new($b->width, $b->height);

        // The board's colours ride CellBuffer styles, which know nothing
        // about capability — gate them here on the fleet authority's answer
        // (or the test seam). NO_COLOR/CLICOLOR=0/TERM=dumb strip the fg/bg
        // (no-color.org asks for colour OFF, decorations may stay); a capable
        // terminal renders the full 24-bit chain byte-identically to the
        // pre-fix frames.
        $canColor = self::colourBoards();

        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $boardBuf = $boardBuf->withCellAt($x, $y, self::cellBufferCell($b, $x, $y, $g->cursorX, $g->cursorY, $mark, $canColor));
            }
        }

        return $boardBuf->toAnsi();
    }

    /**
     * Wrap the minefield interior in the rounded border + status + help
     * footer that make up the full view. While the terminal cannot host
     * the board whole, nothing of the board is painted at all — the frame
     * collapses to a plain, wrapped notice screen (terminal fit).
     */
    private static function frame(Game $g, string $interior): string
    {
        if ($g->tooSmall()) {
            return self::tooSmallNotice($g);
        }

        $framed = SugarStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);

        [$statusPlain, $statusStyle] = self::status($g);
        // Indent matches INTERIOR_COL_OFFSET — border cell + horizontal
        // padding cell — so status and footer line up under the first
        // interior column. Each footer line carries it, not just the first.
        $indent = str_repeat(' ', self::INTERIOR_COL_OFFSET);
        // Every bound key AND mouse action names itself here — left-click
        // reveal included — the footer is the app's only
        // discoverability surface (there is no separate help screen). Values
        // ship translated in all 16 locales, and every locale's lines fit
        // an 80-column terminal — FooterWidthGuardTest enforces that budget
        // across the whole set so a translation can never silently overflow
        // it again (the French lines sat at 78-79 before this indent).
        // The rules line leads the footer so a first-timer reads the
        // win condition before the key legend — TeachToPlayTest pins that
        // this copy reaches every frame via this call site.
        $helpPlain = [
            Lang::t('ui.help_rules'),
            Lang::t('ui.help_moves'),
            Lang::t('ui.help_board'),
            Lang::t('ui.help_session'),
        ];
        $helpStyle = SugarStyle::new()->foreground(Color::hex(self::ink('#7d6e98', '#5b4a7f')));
        // Toast: save/load/restart outcomes (and the boot resume hint)
        // ride their own line ABOVE the status, so a load that lands on a
        // lost board still shows the boom/cleared banner underneath it.
        $toastPlain = $g->notice === null ? null : '» ' . $g->notice;
        $toastStyle = SugarStyle::new()->foreground(Color::hex(self::ink('#fde68a', '#854d0e')));

        // The column budget is what the live terminal actually offers beside
        // the board interior. When every footer line fits it (always, on an
        // 80-column terminal for every shipped locale), the frame renders
        // byte-identically to the pre-fit contract; on a narrower one the
        // lines word-wrap inside the budget instead of reflowing the
        // terminal under the player.
        $budget = max(20, $g->frameCols - self::INTERIOR_COL_OFFSET);
        $fits = static fn(array $lines): bool => (bool) array_reduce(
            $lines,
            static fn(bool $ok, string $l): bool => $ok && Width::of($l) <= $budget,
            true,
        );
        if ($fits($helpPlain) && Width::of($statusPlain) <= $budget && ($toastPlain === null || Width::of($toastPlain) <= $budget)) {
            $toast = $toastPlain === null
                ? ''
                : $indent . $toastStyle->render($toastPlain) . "\n";
            $help = implode("\n" . $indent, $helpPlain);

            return $framed . "\n" . $toast . $indent . $statusStyle->render($statusPlain) . "\n" . $indent .
                   $helpStyle->render($help) . "\n";
        }

        $renderWrapped = static function (?string $plain, SugarStyle $style) use ($budget, $indent): string {
            if ($plain === null) {
                return '';
            }
            return $indent . implode("\n" . $indent, array_map(
                static fn(string $l): string => $style->render($l),
                self::wrapPlain($plain, $budget),
            ));
        };

        $helpLines = [];
        foreach ($helpPlain as $line) {
            foreach (self::wrapPlain($line, $budget) as $piece) {
                $helpLines[] = $piece;
            }
        }

        $out = $framed . "\n";
        $toastBlock = $renderWrapped($toastPlain, $toastStyle);
        if ($toastBlock !== '') {
            $out .= $toastBlock . "\n";
        }

        return $out . $renderWrapped($statusPlain, $statusStyle) . "\n"
            . implode("\n" . $indent, array_map(
                static fn(string $l): string => $helpStyle->render($l),
                $helpLines,
            )) . "\n";
    }

    /**
     * Plain-text notice replacing the whole frame while the terminal is
     * below the board's row budget — zero board painting, zero spoilers,
     * wrapped so it stays readable on the very terminal that cannot host
     * the game. Growing the window re-arms a WindowSizeMsg and the real
     * frame returns at once.
     */
    private static function tooSmallNotice(Game $g): string
    {
        $text = Lang::t('ui.fit_notice', [
            'need' => $g->requiredRows(),
            'have' => $g->frameRows,
        ]);
        return implode("\n", self::wrapPlain($text, max(20, $g->frameCols))) . "\n";
    }

    /**
     * Greedy word wrap of plain text honouring existing newlines.
     *
     * @return list<string>
     */
    private static function wrapPlain(string $text, int $width): array
    {
        $out = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/', $paragraph) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (Width::of($candidate) > $width && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Build a Buffer cell for the minefield at (col, row).
     *
     * When $mark is true the glyph is zone-tagged with
     * Mark::zone("cell:$row:$col", $glyph) so Scanner::hit() can map
     * mouse coordinates back to a cell.
     *
     * $canColor=false (NO_COLOR / CLICOLOR=0 / TERM=dumb) drops the
     * fg/bg to the buffer default while keeping the ATTR_* decorations —
     * bold/reverse are not colour.
     */
    private static function cellBufferCell(Board $b, int $col, int $row, int $cx, int $cy, bool $mark = false, bool $canColor = true): \SugarCraft\CellBuffer\Cell
    {
        $cell = $b->cell($col, $row);
        if ($cell === null) {
            return \SugarCraft\CellBuffer\Cell::new(' ');
        }

        [$glyph, $fg, $bg, $attrs] = self::cellStyleData($cell, $b, $col, $row, $cx, $cy);
        if (!$canColor) {
            $fg = null;
            $bg = null;
        }

        $content = $mark ? Mark::zone("cell:{$row}:{$col}", $glyph) : $glyph;
        $style = Style::new($fg, $bg, $attrs);

        return \SugarCraft\CellBuffer\Cell::new($content, $style);
    }

    /**
     * @return array{0:string, 1:?int, 2:?int, 3:int} glyph, fg, bg, attrs
     */
    private static function cellStyleData(Cell $cell, Board $b, int $col, int $row, int $cx, int $cy): array
    {
        // Post-mortem legibility (M21): once the board is LOST, the overlay
        // must grade the player's own work. A flag sitting on a safe cell was
        // wrong — it wears '✗' instead of 'F' so a correct flag (still gold F)
        // and a wrong one are never the same glyph. Mines the player missed
        // keep the '*' reveal but light up in the mine red instead of the dim
        // hidden-dot grey, which on a lost board is information the overlay
        // owes them. Mid-play nothing changes: flags stay 'F' and hidden
        // cells stay dots — no spoilers before the boom.
        $wrongFlag  = $b->exploded && $cell->flagged && !$cell->mine;
        $missedMine = $b->exploded && $cell->mine && !$cell->flagged && !$cell->revealed;

        $glyph = match (true) {
            $wrongFlag    => '✗',
            $cell->flagged => 'F',
            !$cell->revealed && ($b->exploded || $b->isWon()) && $cell->mine => '*',
            !$cell->revealed => '·',
            $cell->mine     => '*',
            $cell->adjacent === 0 => ' ',
            default => (string) $cell->adjacent,
        };

        $fg = null;
        $bg = null;
        $attrs = 0;

        // Ink selection rides the terminal-background probe: on a declared
        // light background every pale dark-host hue below has a darkened
        // twin (ADJ_COLORS_LIGHT / the hex arms) that clears 4.5:1 on white.
        $light = self::lightBackground();

        if ($cell->revealed && $cell->mine) {
            $fg = $light ? 0xbe123c : 0xff5f87;
            $attrs = Style::ATTR_BOLD;
        } elseif ($wrongFlag) {
            $fg = $light ? 0x5b4a7f : 0x7d6e98;
        } elseif ($cell->flagged) {
            $fg = $light ? 0xb45309 : 0xfde68a;
            $attrs = Style::ATTR_BOLD;
        } elseif ($missedMine) {
            $fg = $light ? 0xbe123c : 0xff5f87;
        } elseif ($cell->revealed && $cell->adjacent > 0) {
            $fg = ($light ? self::ADJ_COLORS_LIGHT : self::ADJ_COLORS)[$cell->adjacent] ?? ($light ? 0x1f2937 : 0xffffff);
        } elseif (!$cell->revealed) {
            $fg = 0x5a4a78;
        }

        // Cursor highlight: reverse foreground/background.
        if ($col === $cx && $row === $cy) {
            $attrs |= Style::ATTR_REVERSE;
        }

        return [$glyph, $fg, $bg, $attrs];
    }

    /**
     * Scan a throwaway marked copy of the minefield into a ready-to-hit-test
     * Scanner. Single derivation point for every consumer ({@see
     * renderWithScanner()}, {@see resolveClick()}) so the zone layout the
     * hit-tests run on is always the one the renderer actually emitted.
     */
    private static function scanner(Game $g): Scanner
    {
        return Scanner::new()->scan(self::interior($g, mark: true));
    }

    /**
     * Render the minefield view and return a ready-to-use Scanner so
     * callers can hit-test clicks.
     *
     * The displayed string is unmarked (see {@see interior()} for why);
     * zone sentinels live only on the throwaway copy fed to the Scanner.
     * Both share the same cell layout, so the Scanner's interior-relative
     * coordinates line up with what {@see resolveClick()} resolves.
     *
     * @return array{0:string, 1:Scanner}
     */
    public static function renderWithScanner(Game $g): array
    {
        return [self::frame($g, self::interior($g, mark: false)), self::scanner($g)];
    }

    /**
     * Resolve a mouse click at (col, row) to a cell coordinate.
     *
     * Hit-testing runs over {@see scanner()}'s zone map ("cell:$row:$col"
     * ids). Returns null if the click was outside any cell zone, and — the
     * defensive half — also when a zone is hit whose id does not match the
     * cell pattern: a zone id is a string, and a mismatch must resolve to
     * nothing rather than a guessed coordinate.
     *
     * $scanner lets a caller that already holds the scanned board (tests
     * pinning the id-mismatch guard, integrators reusing one scan across
     * several clicks) pass it in; null scans a fresh copy.
     *
     * @return array{0:int,1:int}|null col, row of the hit cell
     */
    public static function resolveClick(Game $g, int $col, int $row, ?Scanner $scanner = null): ?array
    {
        $zone = ($scanner ?? self::scanner($g))->hit($col, $row);

        if ($zone === null) {
            return null;
        }

        // Zone id format: "cell:$row:$col".
        if (preg_match('/^cell:(\d+):(\d+)$/', $zone->id, $m)) {
            return [(int) $m[2], (int) $m[1]];  // col, row
        }

        return null;
    }

    /**
     * The verdict/status line as a (plain text, style) pair — the frame
     * applies width fitting and styling, so this never renders itself.
     *
     * @return array{0:string, 1:SugarStyle}
     */
    private static function status(Game $g): array
    {
        $b = $g->board;
        if ($b->exploded || $b->isWon()) {
            // The verdict line carries the frozen final time — the
            // clock stops at the transition frame, so elapsedSeconds is the
            // result — and a preset win that beats the stored best wears a
            // NEW BEST badge. A board with no frozen time (a hand-built or
            // loaded-finished fixture predating the stamp) keeps the bare
            // banner byte-identically.
            $suffix = '';
            if ($g->elapsedSeconds !== null) {
                $suffix .= ' · ' . self::formatTime($g->elapsedSeconds);
            }
            if ($b->isWon() && $g->newBest) {
                $suffix .= ' · ' . Lang::t('ui.new_best');
            }
            return [
                Lang::t($b->exploded ? 'ui.boom' : 'ui.cleared') . $suffix,
                SugarStyle::new()->foreground(Color::hex($b->exploded ? self::ink('#ff5f87', '#be123c') : self::ink('#6ee7b7', '#047857')))->bold(),
            ];
        }
        $remaining = max(0, $b->mineCount - $b->flagCount());
        $elapsed = $g->elapsed();
        $time = $elapsed !== null ? self::formatTime((int) $elapsed) : '0:00';
        return [
            Lang::t('ui.status', [
                'mines'     => $b->mineCount,
                'flags'     => $b->flagCount(),
                'remaining' => $remaining,
                'time'      => $time,
            ]),
            SugarStyle::new()->foreground(Color::hex(self::ink('#a78bfa', '#6d28d9'))),
        ];
    }

    private static function formatTime(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $mins, $secs);
    }
}
