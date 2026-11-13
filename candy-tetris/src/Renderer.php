<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use LogicException;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Style as SugarStyle;

/**
 * Pure view function for {@see Game}. Returns the rendered frame
 * string ready to hand to the SugarCraft Renderer.
 *
 * Decomposes the screen into two side-by-side blocks:
 *
 *   ┌─────────────┐  ┌────────────┐
 *   │  playfield  │  │ hold       │
 *   │   (board)   │  │ next       │
 *   │             │  │ score      │
 *   │             │  │ help       │
 *   └─────────────┘  └────────────┘
 *
 * The sidebar is a stack of cards in that order (see `renderSidebar()`):
 * level and lines ride inside the score card, and the help card is the
 * first casualty of the row budget — dropped for a short terminal (the
 * C-2 compact posture) or an expanded score card (candy-tetris-004).
 *
 * The board is painted from the visible 20 rows of {@see Board},
 * with the falling {@see Piece} composited over locked cells. A
 * "ghost" piece (faded preview of where a hard-drop would land)
 * is drawn underneath the live piece — the standard QoL
 * affordance that's been in every Tetris since the late '90s.
 *
 * Rendering uses a {@see Buffer} for the playfield interior:
 * each cell carries a per-tetromino style (background colour) for
 * crisp Buffer-backed ANSI output. The sidebar is built from
 * sugar-style cards stacked with Layout::joinVertical(), then
 * composited beside the framed playfield with Layout::joinHorizontal().
 *
 * Mirrors Broderick-Westrope/tetrigo board/sidebar layout intent —
 * playfield through sugar-cellbuffer, chrome and layout joins through
 * sugar-style (its Style/Layout/Border are the only chrome imports).
 */
final class Renderer
{
    /**
     * Natural frame: the full board + full sidebar as laid out below
     * (measured: 32 rows x 40 cols, matching the constants two lines
     * down and the README's "frame is 40x32"). A terminal at least
     * this wide and tall gets the complete experience with nothing
     * reflowed.
     */
    public const NATURAL_COLS = 40;
    public const NATURAL_ROWS = 32;

    /**
     * Hard floor: the framed board is 22 rows x 14 cols and the sidebar
     * cards are fixed-width (20 + borders + the 2-col gap = 26 cols);
     * 40x24 is the smallest frame in which every gameplay-relevant fact
     * (board, score, next, hold, and any overlay banner) is ON-frame and
     * unclipped. Below it the renderer shows a resize notice instead —
     * the C-2 defect was banners appending BELOW the fold and silently
     * falling off an 80x24 terminal.
     */
    public const MIN_COLS = 40;
    public const MIN_ROWS = 24;

    public static function render(Game $game): string
    {
        // Posture dispatch (C-2): the frame is decided by what the terminal
        // CAN show before anything else. NoTty gets a plain refusal — no
        // escape bytes for a terminal that cannot parse them; below the
        // floor gets a resize notice that itself fits; everything else
        // renders ON the fixed frame, banners painted INTO it (the old
        // append-below-fold posture pushed the game-over banner and the
        // paused line off an 80x24 terminal — invisible and unrecoverable).
        if ($game->colorProfile === ColorProfile::NoTty) {
            return self::refusal();
        }

        if ($game->tooSmall() === true) {
            return self::tooSmallNotice($game->frameCols, $game->frameRows);
        }

        // MA-12 colour posture: Ascii (NO_COLOR / CLICOLOR=0 / plain ANSI)
        // gets a zero-SGR frame where SHAPE carries every fact colour used
        // to carry alone — the old colored-space previews rendered as blank
        // nothing without colour. Null (profile not yet reported) renders
        // the full-colour path, byte-identical to the frozen pins.
        $plain = $game->colorProfile === ColorProfile::Ascii;

        $playfield = self::renderBoard($game, $plain);
        $sidebar  = self::renderSidebar($game, $plain);
        $body = Layout::joinHorizontal(0.0, $playfield, '  ', $sidebar);

        if ($game->introPending === true) {
            // MA-24: the on-ramp card overlays the fresh board it teaches.
            return self::paintBanner($body, self::introLines($game));
        }
        if ($game->over === true) {
            return self::paintBanner($body, self::overLines($game));
        }
        if ($game->paused === true) {
            return self::paintBanner($body, [Lang::t('paused')]);
        }
        return $body;
    }

    /**
     * The first-run on-ramp as plain text lines (MA-24): what to do, the
     * losing condition, how to start — with the LIVE begin tokens so a
     * remapped table remaps the card too — and the versus affordance the
     * CLI-only mode selection lacked.
     *
     * @return list<string>
     */
    private static function introLines(Game $game): array
    {
        return [
            Lang::t('intro.title'),
            Lang::t('intro.goal'),
            Lang::t('intro.press', ['keys' => $game->introBeginLabel()]),
            Lang::t('intro.vs'),
        ];
    }

    /**
     * The game-over banner as plain text lines (MA-10: the reason the run
     * ended rides the banner — the fatal piece spawns inside the hidden
     * rows, so the banner is the ONLY on-frame evidence of what happened).
     *
     * @return list<string>
     */
    private static function overLines(Game $game): array
    {
        $lines = [
            Lang::t('over.title'),
            Lang::t('over.final_score', ['points' => $game->score->points]),
        ];
        // MA-23: the record finally gives the final score something to be
        // read against. soloBest is null only when no game ever finished.
        if ($game->scores !== null && $game->scores->soloBest !== null) {
            $lines[] = Lang::t('over.best', ['points' => $game->scores->soloBest]);
        }
        // candy-tetris-096: the finished-games denominator rode the same
        // JSON but never reached the frame — the banner now says how many
        // runs this best came out of.
        if ($game->scores !== null && $game->scores->soloGames > 0) {
            $lines[] = Lang::t('over.games', ['games' => $game->scores->soloGames]);
        }
        if ($game->newBest === true) {
            $lines[] = Lang::t('over.new_best');
        }
        if ($game->overReason !== null) {
            $lines[] = Lang::t('over.reason', ['reason' => self::reasonText($game->overReason)]);
        }
        $lines[] = Lang::t('over.restart_hint');
        return $lines;
    }

    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            Game::OVER_SPAWN_BLOCKED => Lang::t('over.reason.spawn_blocked'),
            Game::OVER_TOP_OUT       => Lang::t('over.reason.top_out'),
            Game::OVER_CRUSHED       => Lang::t('over.reason.crushed'),
            default                  => $reason,
        };
    }

    /**
     * Replace the vertically-centred band of the board region with the
     * given plain-text lines, each centred across the full body width.
     * The frame's row and column counts NEVER change — an overlay is a
     * stripe over the playfield, not content appended past the fold.
     *
     * @param list<string> $lines
     */
    private static function paintBanner(string $body, array $lines): string
    {
        $rows = explode("\n", $body);
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, Width::of($row));
        }

        $top = intdiv(Board::VISIBLE_ROWS - count($lines), 2);
        foreach ($lines as $i => $line) {
            $row = $top + $i;
            if ($row < 0 || $row >= count($rows)) {
                continue;
            }
            $text = Width::of($line) > $width
                ? Width::truncateAnsi($line, $width)
                : $line;
            $left = max(0, intdiv($width - Width::of($text), 2));
            $rows[$row] = \str_repeat(' ', $left) . $text;
        }

        return implode("\n", $rows);
    }

    /**
     * Plain-text refusal for a terminal with no rendering capability at
     * all (MA-12): zero SGR bytes, nothing to misparse. The floor is the
     * TTY itself — colour is optional (NO_COLOR/CLICOLOR=0 play fine),
     * so the copy must not demand colour (candy-tetris-041); the same
     * line is kept verbatim in VsRenderer (candy-tetris-027 family).
     */
    private static function refusal(): string
    {
        return Lang::t('cli.refusal') . "\n";
    }

    /**
     * Notice shown below the frame floor; wrapped so it stays readable on
     * the very terminal that cannot host the game.
     */
    private static function tooSmallNotice(int $cols, int $rows): string
    {
        $notice = Lang::t('cli.too_small', [
            'size' => self::MIN_COLS . 'x' . self::MIN_ROWS,
            'actual' => $cols . 'x' . $rows,
        ]) . "\n";
        return self::wrapPlain($notice, max(20, $cols));
    }

    /**
     * Greedy word wrap of plain text honouring existing newlines.
     */
    private static function wrapPlain(string $text, int $width): string
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
        return implode("\n", $out) . "\n";
    }

    private static function renderBoard(Game $game, bool $plain = false): string
    {
        $rows = $game->board->rows();
        $piece = $game->piece;
        $ghost = $game->board->dropPiece($piece);
        $pieceCells = self::cellMap($piece->cells());
        $ghostCells = self::cellMap($ghost->cells());

        // Build a Buffer-backed interior, then frame it with the sugar-style border.
        $boardBuf = Buffer::new(Board::COLS, Board::VISIBLE_ROWS);

        for ($dy = 0; $dy < Board::VISIBLE_ROWS; $dy++) {
            $y = Board::HIDDEN_ROWS + $dy;
            for ($dx = 0; $dx < Board::COLS; $dx++) {
                $key = "$dx,$y";
                $cellKind = $rows[$y][$dx] ?? null;
                // '·' (matching the VS board's empty-cell vocabulary) keeps
                // the playfield readable on terminals without colour.
                $rune = '·';
                $style = null;

                if ($cellKind !== null) {
                    $rune = '█';
                    $style = $plain ? null : self::blockStyle($cellKind);
                } elseif (isset($pieceCells[$key]) === true) {
                    $rune = '█';
                    $style = $plain ? null : self::blockStyle($piece->kind);
                } elseif (isset($ghostCells[$key]) === true) {
                    $rune = '▒';
                    $style = $plain ? null : self::ghostStyle($piece->kind);
                }

                $boardBuf = $boardBuf->withCellAt($dx, $dy, Cell::new($rune, $style));
            }
        }

        // Frame the Buffer interior with the sugar-style border.
        $interior = $boardBuf->toAnsi();
        return SugarStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);
    }

    /**
     * Sidebar cards, top to bottom: hold, next, score, help.
     *
     * The column is BUDGETED (candy-tetris-004): it may never stand
     * taller than min(frameRows, NATURAL_ROWS) — a taller sidebar pushes
     * the frame's bottom border past the terminal, the same below-the-fold
     * defect C-2 cured for banners, reached now by content instead of by
     * window size. When the column would overflow, casualties drop in a
     * fixed order:
     *
     *  1. the help card — the C-2 compact-reflow casualty, now also the
     *     victim of an armed-badge or --seed expansion at full height;
     *  2. the SEED stamp — developer replay data, sacrificed only at the
     *     MIN_ROWS floor where even the stripped column runs out of room.
     *
     * Score, next, hold and the earned MA-13 badges are never dropped:
     * hold/next/score are the facts play is decided on, and a badge that
     * cannot paint is a moment the player earned and never saw.
     */
    private static function renderSidebar(Game $game, bool $plain = false): string
    {
        $budget = min($game->frameRows, self::NATURAL_ROWS);
        $next = $game->bag->peek(3);
        $previews = [];
        foreach ($next as $kind) {
            $previews[] = self::renderMini($kind, $plain);
        }
        $score = Lang::t('score_card', [
            'score' => (string) $game->score->points,
            'lines' => (string) $game->score->lines,
            'level' => (string) $game->score->level,
        ]);
        // MA-13: badge the earned moments INSIDE the score card — rows are
        // added only while something is armed, and the candy-tetris-004
        // budget below never sacrifices an armed row: when the expanded
        // card will not fit, the help card (and finally the seed stamp)
        // gives way instead, so a fresh game keeps the exact frame the
        // goldens and ViewportTest pin and an armed one stays on-screen.
        if ($game->lastAward !== null) {
            $score .= "\n" . $game->lastAward;
        }
        if ($game->combo >= 2) {
            $score .= "\n" . \sprintf(Lang::t('combo_line'), $game->combo);
        }
        if ($game->backToBack === true) {
            $score .= "\n" . Lang::t('b2b_line');
        }
        // candy-tetris-018: the reproducible-run stamp rides the score
        // card ONLY when --seed named the run — a plain launch captures
        // hidden entropy and stamps nothing, so every pre-feature frame
        // and golden stays byte-identical. 'SEED <n>' is developer-facing
        // replay data (a number to retype), kept raw English on purpose:
        // translating the token would make it unsearchable in help text.
        // Under the candy-tetris-004 budget this is the LAST casualty (it
        // is dropped only at the MIN_ROWS floor), so the stamp is built
        // apart from the card body to keep that rung addressable.
        $seedStamp = $game->reproducible === true ? "\nSEED " . $game->seed : '';
        // m27: the key column is DERIVED from the live table, so a remap
        // moves the card text with it — the card can no longer lie.
        $help = Support\Bindings::helpCard($game->bindings ?? Support\Bindings::table());

        $nextStr = Lang::t('next_label') . "\n" . implode("\n\n", $previews);

        $hold = '';
        if ($game->hold !== null) {
            if ($game->canHold === false) {
                // MA-12/m35: the exhausted state gets an ON-FRAME TEXT cue
                // riding the LABEL row — an extra body line would resize the
                // card mid-play (the very reflow m35 complains of), and the
                // dim SGR alone was a visible no-op where style does not
                // land. Colour posture keeps the dim over the whole card.
                $hold = Lang::t('hold_label') . ' ' . Lang::t('hold_used') . "\n" . self::renderMini($game->hold, $plain);
                if ($plain === false) {
                    $hold = SugarStyle::new()->dim(true)->render($hold);
                }
            } else {
                $hold = Lang::t('hold_label') . "\n" . self::renderMini($game->hold, $plain);
            }
        } else {
            $hold = Lang::t('hold_label') . "\n" . self::renderMiniPlaceholder();
        }

        $card = static fn(string $body): string => SugarStyle::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(20)
            ->render($body);

        $column = [$card($hold), $card($nextStr), $card($score . $seedStamp)];

        $full = Layout::joinVertical(0.0, ...[...$column, $card($help)]);
        if (self::height($full) <= $budget) {
            return $full;
        }

        $untaught = Layout::joinVertical(0.0, ...$column);
        if (self::height($untaught) <= $budget) {
            return $untaught;
        }

        // Reaching this rung means a floor-sized terminal fully armed AND
        // stamped: the seed stamp — developer data — is the casualty, so
        // not one earned badge goes unpainted.
        $stripped = Layout::joinVertical(0.0, $card($hold), $card($nextStr), $card($score));
        if (self::height($stripped) <= $budget) {
            return $stripped;
        }

        // Unreachable while tooSmall() refuses below MIN_ROWS and the
        // stripped column measures 24 rows even with all three badges
        // armed; kept loud per Fail Fast in case a future card grows.
        throw new LogicException('sidebar cannot fit a ' . $budget . '-row budget even fully stripped');
    }

    /** Row count of a rendered block — the sidebar's budget measure. */
    private static function height(string $block): int
    {
        return \count(\explode("\n", $block));
    }

    private static function renderMini(Tetromino $kind, bool $plain = false): string
    {
        $cells = self::cellMap($kind->cells(0));

        // 4×2 preview grid, two Buffer columns per tetromino cell so the
        // mini blocks keep the playfield's width. Rendered through a Buffer
        // (same path as renderBoard) instead of hand-rolled SGR strings.
        //
        // MA-12: the plain posture carries the shape in the GLYPH ('█')
        // with no style — the colour posture's styled SPACE is invisible
        // once colour is off, which is exactly how a no-colour preview used
        // to read as an empty card.
        $buf = Buffer::new(8, 2);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if (isset($cells["$x,$y"]) === false) {
                    continue;
                }
                $cell = $plain === true
                    ? Cell::new('█')
                    : Cell::new(' ', self::blockStyle($kind));
                $buf = $buf
                    ->withCellAt($x * 2, $y, $cell)
                    ->withCellAt($x * 2 + 1, $y, $cell);
            }
        }

        return $buf->toAnsi();
    }

    /**
     * m35: eight box-rule cells at the SAME 8-column width the occupied
     * previews carry — the old 5-space body was neither the preview's width
     * nor an 'empty' anyone could see, so the card changed shape on the
     * first hold. Deliberately not '·': the dot belongs to the playfield's
     * empty-cell vocabulary and the rune census pins it there.
     */
    private static function renderMiniPlaceholder(): string
    {
        return "────────\n────────";
    }

    /**
     * @param list<array{int,int}> $cells
     * @return array<string,true>
     */
    private static function cellMap(array $cells): array
    {
        $out = [];
        foreach ($cells as [$x, $y]) {
            $out["$x,$y"] = true;
        }
        return $out;
    }

    /**
     * Style for a filled block cell using the tetromino's colour as background.
     */
    private static function blockStyle(Tetromino $kind): Style
    {
        return Style::new(null, $kind->rgbColor());
    }

    /**
     * Style for a ghost (landing-preview) cell — faint/dim foreground.
     */
    private static function ghostStyle(Tetromino $kind): Style
    {
        return Style::new($kind->rgbColor(), null, Style::ATTR_FAINT);
    }
}
