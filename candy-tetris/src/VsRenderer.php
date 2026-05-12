<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * Split-screen renderer for VS Computer mode.
 *
 * Renders two boards side by side:
 *
 *   ┌─────────────┐    ┌─────────────┐
 *   │   PLAYER    │ VS │  COMPUTER   │
 *   │  playfield  │    │  playfield  │
 *   │   (board)   │    │   (board)   │
 *   │             │    │             │
 *   └─────────────┘    └─────────────┘
 *     score/lines        score/lines
 *
 * Player pieces are rendered normally.
 * Computer pieces are rendered with a different hue (more magenta).
 */
final class VsRenderer
{
    public static function render(VsGame $game): string
    {
        $playerBoard = self::renderBoard($game->player, false);
        $computerBoard = self::renderBoard($game->computer, true);

        $playerSidebar = self::renderSidebar($game->player, 'PLAYER', false);
        $computerSidebar = self::renderSidebar($game->computer, 'COMPUTER', true);

        $playerPanel = Layout::joinVertical(0.0, $playerBoard, $playerSidebar);
        $computerPanel = Layout::joinVertical(0.0, $computerBoard, $computerSidebar);

        $vsDivider = Style::new()
            ->bold(true)
            ->render("  VS  ");

        $body = Layout::joinHorizontal(0.0, $playerPanel, $vsDivider, $computerPanel);

        if ($game->over) {
            $winnerText = $game->winner === 'PLAYER' ? 'YOU WIN!' : 'COMPUTER WINS!';
            $banner = Style::new()
                ->border(Border::rounded())
                ->padding(1, 3)
                ->render("GAME OVER\n{$winnerText}\nfinal score: {$game->player->score->points}\npress q to quit");
            return $body . "\n\n" . $banner;
        }

        return $body;
    }

    /**
     * Render a single board for VS mode.
     *
     * @param Game $game The game to render
     * @param bool $isComputer Whether this is the computer's board (different hue)
     */
    private static function renderBoard(Game $game, bool $isComputer): string
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
                    $line .= $isComputer ? self::blockComputer($cellKind) : self::block($cellKind);
                    continue;
                }
                if (isset($pieceCells[$key])) {
                    $line .= $isComputer ? self::blockComputer($piece->kind) : self::block($piece->kind);
                    continue;
                }
                if (isset($ghostCells[$key])) {
                    $line .= $isComputer ? self::ghostComputer($piece->kind) : self::ghost($piece->kind);
                    continue;
                }
                $line .= '··';
            }
            $lines[] = $line;
        }

        $title = $isComputer ? 'COMPUTER' : 'PLAYER';
        $titleBlock = Style::new()
            ->bold(true)
            ->render($title);

        return Layout::joinVertical(0.0,
            $titleBlock,
            Style::new()
                ->border(Border::rounded())
                ->padding(0, 1)
                ->render(implode("\n", $lines))
        );
    }

    private static function renderSidebar(Game $game, string $label, bool $isComputer): string
    {
        $score = sprintf(
            "score:  %d\nlines:  %d\nlevel:  %d",
            $game->score->points,
            $game->score->lines,
            $game->score->level,
        );

        $card = static fn(string $body): string => Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(16)
            ->render($body);

        return $card($score);
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

    /**
     * Computer pieces use a more magenta hue (shifted color).
     */
    private static function blockComputer(Tetromino $kind): string
    {
        // Shift color towards magenta/pink for computer pieces
        $shiftedColor = ($kind->color() + 150) % 256;
        return "\x1b[48;5;{$shiftedColor}m  \x1b[0m";
    }

    private static function ghostComputer(Tetromino $kind): string
    {
        $shiftedColor = ($kind->color() + 150) % 256;
        return "\x1b[38;5;{$shiftedColor};2m▒▒\x1b[0m";
    }
}
