<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Style;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style as SprinklesStyle;

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
 *
 * Rendering uses a {@see Buffer} for the playfield interior:
 * each cell carries a per-tetromino style (background colour) for
 * crisp Buffer-backed ANSI output. The sidebar is rendered as a
 * sub-buffer and composited via {@see Buffer::withRegion()}.
 *
 * Mirrors charmbracelet/bubbletea — Tetris renderer.
 */
final class Renderer
{
    // ANSI 256-color → 0xRRGGBB for the 7 tetromino colours.
    private const COLOR_MAP = [
        51  => 0x00d4ff,  // I  — cyan
        226 => 0xffd400,  // O  — yellow
        129 => 0xbd7dff,  // T  — purple
        46  => 0x00ff5e,  // S  — green
        196 => 0xff0030,  // Z  — red
        21  => 0x0070ff,  // J  — blue
        208 => 0xff8c00,  // L  — orange
    ];

    public static function render(Game $game): string
    {
        $playfield = self::renderBoard($game);
        $sidebar  = self::renderSidebar($game);
        $body = Layout::joinHorizontal(0.0, $playfield, '  ', $sidebar);

        if ($game->over) {
            $banner = SprinklesStyle::new()
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

        // Build a Buffer-backed interior, then frame it with Sprinkles border.
        $boardBuf = Buffer::new(Board::COLS, Board::VISIBLE_ROWS);

        for ($dy = 0; $dy < Board::VISIBLE_ROWS; $dy++) {
            $y = Board::HIDDEN_ROWS + $dy;
            for ($dx = 0; $dx < Board::COLS; $dx++) {
                $key = "$dx,$y";
                $cellKind = $rows[$y][$dx] ?? null;
                $rune = ' ';
                $style = null;

                if ($cellKind !== null) {
                    $rune = '█';
                    $style = self::blockStyle($cellKind);
                } elseif (isset($pieceCells[$key])) {
                    $rune = '█';
                    $style = self::blockStyle($piece->kind);
                } elseif (isset($ghostCells[$key])) {
                    $rune = '▒';
                    $style = self::ghostStyle($piece->kind);
                }

                $boardBuf = $boardBuf->withCellAt($dx, $dy, Cell::new($rune, $style));
            }
        }

        // Frame the Buffer interior with Sprinkles border.
        $interior = $boardBuf->toAnsi();
        return SprinklesStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);
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

        $nextStr = "next:\n" . implode("\n\n", $previews);

        $hold = '';
        if ($game->hold !== null) {
            $hold = "hold:\n" . self::renderMini($game->hold);
            if (!$game->canHold) {
                $hold = SprinklesStyle::new()->dim(true)->render($hold);
            }
        } else {
            $hold = "hold:\n" . self::renderMiniPlaceholder();
        }

        $card = static fn(string $body): string => SprinklesStyle::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(20)
            ->render($body);

        return Layout::joinVertical(0.0, $card($hold), $card($nextStr), $card($score), $card($help));
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
        $rgb = self::COLOR_MAP[$kind->color()] ?? 0xffffff;
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return "\x1b[48;2;{$r};{$g};{$b}m  \x1b[0m";
    }

    private static function ghost(Tetromino $kind): string
    {
        $rgb = self::COLOR_MAP[$kind->color()] ?? 0x888888;
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return "\x1b[38;2;{$r};{$g};{$b}m\x1b[2m▒▒\x1b[0m";
    }

    /**
     * Style for a filled block cell using the tetromino's colour as background.
     */
    private static function blockStyle(Tetromino $kind): Style
    {
        $rgb = self::COLOR_MAP[$kind->color()] ?? 0xffffff;

        return Style::new(null, (int) $rgb);
    }

    /**
     * Style for a ghost (landing-preview) cell — faint/dim foreground.
     */
    private static function ghostStyle(Tetromino $kind): Style
    {
        $rgb = self::COLOR_MAP[$kind->color()] ?? 0x888888;

        return Style::new((int) $rgb, null, Style::ATTR_FAINT);
    }
}
