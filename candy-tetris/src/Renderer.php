<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

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
 *   │  playfield  │  │ next       │
 *   │   (board)   │  │ score      │
 *   │             │  │ level      │
 *   │             │  │ help       │
 *   └─────────────┘  └────────────┘
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
     * (measured: 31 rows x 40 cols). A terminal at least this wide and
     * tall gets the complete experience with nothing reflowed.
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

        $playfield = self::renderBoard($game);
        $sidebar  = self::renderSidebar($game, $game->frameRows < self::NATURAL_ROWS);
        $body = Layout::joinHorizontal(0.0, $playfield, '  ', $sidebar);

        if ($game->over === true) {
            return self::paintBanner($body, self::overLines($game));
        }
        if ($game->paused === true) {
            return self::paintBanner($body, [Lang::t('paused')]);
        }
        return $body;
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
     * all (MA-12): zero SGR bytes, nothing to misparse.
     */
    private static function refusal(): string
    {
        return "candy-tetris needs a colour-capable interactive terminal.\n"
            . "Run it from a TTY (TERM=xterm-256color or better).\n";
    }

    /**
     * Notice shown below the frame floor; wrapped so it stays readable on
     * the very terminal that cannot host the game.
     */
    private static function tooSmallNotice(int $cols, int $rows): string
    {
        $notice = "candy-tetris needs a " . self::MIN_COLS . "x" . self::MIN_ROWS
            . " terminal; this one is {$cols}x{$rows}.\n"
            . "Resize the window and the game resumes right here.\n";
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

    private static function renderBoard(Game $game): string
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
                    $style = self::blockStyle($cellKind);
                } elseif (isset($pieceCells[$key]) === true) {
                    $rune = '█';
                    $style = self::blockStyle($piece->kind);
                } elseif (isset($ghostCells[$key]) === true) {
                    $rune = '▒';
                    $style = self::ghostStyle($piece->kind);
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
     * In compact posture (terminal shorter than the natural frame but at
     * or above the floor — C-2 reflow) the help card is dropped first: it
     * is the only card the board itself cannot substitute for the loss of
     * gameplay information; score, next and hold are never dropped. The
     * frame's height becomes max(board 22, remaining sidebar) and still
     * fits a MIN_ROWS terminal.
     */
    private static function renderSidebar(Game $game, bool $compact = false): string
    {
        $next = $game->bag->peek(3);
        $previews = [];
        foreach ($next as $kind) {
            $previews[] = self::renderMini($kind);
        }
        $score = sprintf(
            Lang::t('score_card'),
            $game->score->points,
            $game->score->lines,
            $game->score->level,
        );
        $help = Lang::t('help_card');

        $nextStr = Lang::t('next_label') . "\n" . implode("\n\n", $previews);

        $hold = '';
        if ($game->hold !== null) {
            $hold = Lang::t('hold_label') . "\n" . self::renderMini($game->hold);
            if ($game->canHold === false) {
                $hold = SugarStyle::new()->dim(true)->render($hold);
            }
        } else {
            $hold = Lang::t('hold_label') . "\n" . self::renderMiniPlaceholder();
        }

        $card = static fn(string $body): string => SugarStyle::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(20)
            ->render($body);

        $cards = [$card($hold), $card($nextStr), $card($score)];
        if ($compact === false) {
            $cards[] = $card($help);
        }

        return Layout::joinVertical(0.0, ...$cards);
    }

    private static function renderMini(Tetromino $kind): string
    {
        $cells = self::cellMap($kind->cells(0));

        // 4×2 preview grid, two Buffer columns per tetromino cell so the
        // mini blocks keep the playfield's width. Rendered through a Buffer
        // (same path as renderBoard) instead of hand-rolled SGR strings.
        $buf = Buffer::new(8, 2);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if (isset($cells["$x,$y"]) === false) {
                    continue;
                }
                $cell = Cell::new(' ', self::blockStyle($kind));
                $buf = $buf
                    ->withCellAt($x * 2, $y, $cell)
                    ->withCellAt($x * 2 + 1, $y, $cell);
            }
        }

        return $buf->toAnsi();
    }

    private static function renderMiniPlaceholder(): string
    {
        return "     \n     ";
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
