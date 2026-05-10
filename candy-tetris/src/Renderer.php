<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * Pure view function for {@see Game}. Returns the rendered frame
 * string ready to hand to the SugarCraft Renderer.
 *
 * Decomposes the screen into three side-by-side blocks:
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
 */
final class Renderer
{
    public static function render(Game $game): string
    {
        $playfield = self::renderBoard($game);
        $sidebar   = self::renderSidebar($game);
        $body = Layout::joinHorizontal(0.0, $playfield, '  ', $sidebar);

        if ($game->over) {
            $banner = Style::new()
                ->border(Border::rounded())
                ->padding(1, 3)
                ->render("GAME OVER\nfinal score: {$game->score->points}\npress q to quit");
            return $body . "\n\n" . $banner;
        }
        if ($game->paused) {
            return $body . "\n\n[ paused — press p to resume ]";
        }
        return $body;
    }

    private static function renderBoard(Game $game): string
    {
        $rows = $game->board->rows();
        $piece = $game->piece;
        $ghost = $game->board->dropPiece($piece);
        $pieceCells = self::cellMap($piece->cells());
        $ghostCells = self::cellMap($ghost->cells());

        $lines = [];
        for ($y = Board::HIDDEN_ROWS; $y < Board::ROWS; $y++) {
            $line = '';
            for ($x = 0; $x < Board::COLS; $x++) {
                $key = "$x,$y";
                $cellKind = $rows[$y][$x] ?? null;
                if ($cellKind !== null) {
                    $line .= self::block($cellKind);
                    continue;
                }
                if (isset($pieceCells[$key])) {
                    $line .= self::block($piece->kind);
                    continue;
                }
                if (isset($ghostCells[$key])) {
                    $line .= self::ghost($piece->kind);
                    continue;
                }
                $line .= '··';
            }
            $lines[] = $line;
        }

        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render(implode("\n", $lines));
    }

    private static function renderSidebar(Game $game): string
    {
        $next = $game->bag->peek(3);
        $previews = [];
        foreach ($next as $kind) {
            $previews[] = self::renderMini($kind);
        }
        $score = sprintf(
            "score:  %d\nlines:  %d\nlevel:  %d",
            $game->score->points,
            $game->score->lines,
            $game->score->level,
        );
        $help = "← →  move\n↑ x  rotate cw\nz    rotate ccw\n↓    soft drop\nspc  hard drop\nc    hold\np    pause\nq    quit";

        $next = "next:\n" . implode("\n\n", $previews);

        // Render hold piece if available
        $hold = '';
        if ($game->hold !== null) {
            $hold = "hold:\n" . self::renderMini($game->hold);
            if (!$game->canHold) {
                $hold = Style::new()->dim(true)->render($hold);
            }
        } else {
            $hold = "hold:\n" . self::renderMiniPlaceholder();
        }

        $card = static fn(string $body): string => Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(20)
            ->render($body);

        return Layout::joinVertical(0.0, $card($hold), $card($next), $card($score), $card($help));
    }

    private static function renderMini(Tetromino $kind): string
    {
        $cells = self::cellMap($kind->cells(0));
        $lines = [];
        for ($y = 0; $y < 2; $y++) {
            $line = '';
            for ($x = 0; $x < 4; $x++) {
                $line .= isset($cells["$x,$y"]) ? self::block($kind) : '  ';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
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

    private static function block(Tetromino $kind): string
    {
        return "\x1b[48;5;{$kind->color()}m  \x1b[0m";
    }

    private static function ghost(Tetromino $kind): string
    {
        return "\x1b[38;5;{$kind->color()};2m▒▒\x1b[0m";
    }
}
