<?php

/**
 * candy-tetris/tests/SpawnVisibilityTest.php
 *
 * candy-tetris-093: every piece used to spend its first 2-3 seconds in an
 * invisible hole — the spawn box sat at the ceiling of the UNRENDERED
 * hidden buffer while the dropPiece ghost already painted at the bottom,
 * so the frame showed a silhouette of a piece that did not exist yet.
 * The spawn now sits at the visible top: the player sees a piece the tick
 * it arrives, the ghost can no longer precede it, and the spawn-blocked
 * death names the field the player can actually see.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;

final class SpawnVisibilityTest extends TestCase
{
    /**
     * The law holds for every kind, not just the one the deterministic
     * bag happens to draw: reflect the single spawn point the game itself
     * routes every arm through.
     */
    public function testEveryKindSpawnsWithAllCellsOnTheVisibleField(): void
    {
        $spawn = new ReflectionMethod(Game::class, 'spawn');
        $spawn->setAccessible(true);

        foreach (Tetromino::cases() as $kind) {
            $piece = $spawn->invoke(null, $kind);
            $ys = array_map(static fn(array $cell): int => $cell[1], $piece->cells());
            self::assertGreaterThanOrEqual(
                Board::HIDDEN_ROWS,
                min($ys),
                $kind->name . ' must not spawn a single cell above the visible field',
            );
            self::assertLessThanOrEqual(
                Board::HIDDEN_ROWS + 1,
                max($ys),
                $kind->name . ' must sit at the TOP of the visible field, not buried in it',
            );
            self::assertSame(
                0,
                $piece->rotation,
                $kind->name . ' spawns at the canonical rotation',
            );
        }
    }

    public function testAFreshFrameShowsThePieceAlongsideItsGhost(): void
    {
        $game = Game::start();
        $frame = $game->view();

        self::assertStringContainsString('█', $frame, 'the live piece is on screen at t=0');
        self::assertStringContainsString('▒', $frame, 'the ghost paints too — but it no longer paints ALONE');
        foreach ($game->piece->cells() as [$x, $y]) {
            self::assertGreaterThanOrEqual(Board::HIDDEN_ROWS, $y, 'spawn cells are rendered-region cells');
        }
    }

    public function testTheSpawnBlockedDeathMeansNoRoomOnTheVisibleField(): void
    {
        // A wall AT the spawn rows tops out; the old hidden-rows wall that
        // used to be fatal is now mere scenery — prove which one guards.
        $game = Game::start();

        $benign = $game->mutate(['board' => new Board(self::wallRows([0, 1, 2, 3]))]);
        [$spawnedOk, $cmd] = $benign->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, ' '));
        self::assertFalse($spawnedOk->over, 'a hidden-buffer ceiling alone is no longer a death line');
        self::assertNull($spawnedOk->overReason);
        self::assertSame(Board::HIDDEN_ROWS, $spawnedOk->piece->y, 'the replacement piece stands at the visible top');

        $deadly = $game->mutate(['board' => new Board(self::spawnZoneWall())]);
        [$spawnedDead,] = $deadly->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, ' '));
        self::assertTrue($spawnedDead->over, 'the visible-top spawn wall must top out');
        self::assertSame(Game::OVER_SPAWN_BLOCKED, $spawnedDead->overReason);
    }

    /**
     * A full floor + solid rows at the requested indices (locked content —
     * rows list<list<?Tetromino>>).
     *
     * @param list<int> $solidRowIndices
     *
     * @return list<list<?Tetromino>>
     */
    private static function wallRows(array $solidRowIndices): array
    {
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $row = [];
            for ($x = 0; $x < Board::COLS; $x++) {
                $row[] = in_array($y, $solidRowIndices, true) || $y === Board::ROWS - 1
                    ? Tetromino::O
                    : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Rows 4-5 blocked at cols 1-9 (col 0 open keeps both rows INCOMPLETE,
     * so the locking piece can never clear them away before the next
     * spawn meets the wall), over a solid floor.
     *
     * @return list<list<?Tetromino>>
     */
    private static function spawnZoneWall(): array
    {
        $rows = self::wallRows([0, 1, 2, 3]);
        foreach ([Board::HIDDEN_ROWS, Board::HIDDEN_ROWS + 1] as $y) {
            for ($x = 1; $x < Board::COLS; $x++) {
                $rows[$y][$x] = Tetromino::O;
            }
        }

        return $rows;
    }
}
