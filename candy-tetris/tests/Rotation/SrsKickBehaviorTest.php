<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Rotation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;

/**
 * Pins the SRS kicks as they land ON THE BOARD, not as table literals.
 *
 * The spec's y axis grows upward; this game's rows grow downward (see
 * {@see Tetromino}). The sign conversion happens exactly once, at
 * {@see Piece::rotationsWithKicks()}. These tests are written behavior-first:
 * each scenario is constructed so an unconverted (spec-signed) kick answers a
 * different question than a converted one — a T-spin that must kick UP into
 * ceiling space, and an R→2 rotation that must kick two rows UP off the
 * floor. Reverting the conversion (or moving it into the table itself)
 * reddens exactly these names, which is the discriminating power the old
 * re-echo providers never had.
 */
final class SrsKickBehaviorTest extends TestCase
{
    private function deterministicGame(): Game
    {
        return Game::start(new Bag(static fn(int $_max): int => 0));
    }

    private function key(string $which): KeyMsg
    {
        return match ($which) {
            'left' => new KeyMsg(KeyType::Left, ''),
            'right' => new KeyMsg(KeyType::Right, ''),
            'down' => new KeyMsg(KeyType::Down, ''),
            'up' => new KeyMsg(KeyType::Up, ''),
            default => new KeyMsg(KeyType::Char, $which),
        };
    }

    /**
     * @return list<array{int, int}> absolute (x, y) of each candidate
     */
    private function candidatePositions(Piece $piece, int $delta): array
    {
        $out = [];
        foreach ($piece->rotationsWithKicks($delta) as $candidate) {
            $out[] = [$candidate->x, $candidate->y];
        }

        return $out;
    }

    /**
     * JLSTZ 0→R, spec deltas [[0,0],[-1,0],[-1,+1],[0,-2],[-1,-2]].
     * Spec +1 is UP; on this board UP is y-1. The naive entry is emitted
     * once as the rotation itself and again as the spec's first [0,0] kick.
     */
    public function testJlstzUpwardsKicksDecreaseBoardY(): void
    {
        $t = new Piece(Tetromino::T, 0, 4, 2);

        self::assertSame(
            [
                [4, 2], // naive rotation
                [4, 2], // [0, 0]
                [3, 2], // [-1, 0]   (left)
                [3, 1], // [-1, +1]   (left + UP in spec → y decreases)
                [4, 4], // [0, -2]   (DOWN in spec → y increases)
                [3, 4], // [-1, -2]  (left + down)
            ],
            $this->candidatePositions($t, 1),
            'spec-positive dy must kick toward lower row indices (up the screen)',
        );
    }

    /** I-piece 0→R spec deltas [[0,0],[-2,0],[+1,0],[-2,-1],[+1,+2]]. */
    public function testIPieceKicksUseTheSameBoardConvention(): void
    {
        $i = new Piece(Tetromino::I, 0, 4, 2);

        self::assertSame(
            [
                [4, 2],
                [4, 2],
                [2, 2], // [-2, 0]
                [5, 2], // [+1, 0]
                [2, 3], // [-2, -1] (DOWN in spec → y increases)
                [5, 0], // [+1, +2] (UP twice in spec → y decreases)
            ],
            $this->candidatePositions($i, 1),
        );
    }

    /** Counter-clockwise pin: R→0 spec [[0,0],[+1,0],[+1,-1],[0,+2],[+1,+2]]. */
    public function testCounterClockwiseKicksFollowTheSameConvention(): void
    {
        $t = new Piece(Tetromino::T, 1, 4, 2);

        self::assertSame(
            [
                [4, 2],
                [4, 2],
                [5, 2], // [+1, 0]
                [5, 3], // [+1, -1] (down in spec → y increases)
                [4, 0], // [0, +2]   (up twice in spec → y decreases)
                [5, 0], // [+1, +2]
            ],
            $this->candidatePositions($t, -1),
        );
    }

    /**
     * Full-board scenario: a T at spawn rotation sits one row below a
     * ceiling-side stack; naive and the horizontal kicks are blocked, and the
     * only fit is the spec's [-1, +1] kick — one left, ONE UP. With the sign
     * unconverted the same leg pushes the piece DOWN into the floor blocks and
     * the rotation fails outright.
     */
    public function testTSpinUpIntoCeilingLandsAboveItsStart(): void
    {
        $rows = $this->deterministicGame()->board->rows();
        // (5,4) blocks the naive R rotation; (4,4) blocks the left kick;
        // (6,1) and (5,1) wall off the two spec-downward legs so the upward
        // kick is the ONLY viable candidate.
        $rows[4][5] = Tetromino::I;
        $rows[4][4] = Tetromino::I;
        $rows[1][6] = Tetromino::I;
        $rows[1][5] = Tetromino::I;
        $board = new Board($rows);

        $game = $this->deterministicGame()
            ->mutate(['board' => $board, 'piece' => new Piece(Tetromino::T, 0, 4, 2)]);

        [$after] = $game->update($this->key('up'));

        self::assertSame(1, $after->piece->rotation, 'the rotation must succeed via a kick');
        self::assertSame(3, $after->piece->x, 'the kick must shift one column left');
        self::assertSame(1, $after->piece->y, 'the spec +1 kick must land one row UP the board (y 2 → 1)');
    }

    /**
     * Full-board scenario for R→2: naive, the +1 horizontal, and the small
     * downward leg are all blocked, leaving only the spec [0, +2] (two rows
     * UP in spec terms). Unconverted, the piece would instead be kicked
     * upward-then-blocked and the next candidate (a downward drift) would
     * win, landing at the wrong row.
     */
    public function testRotationTwoKicksTwoRowsUpOffTheFloorArea(): void
    {
        $rows = $this->deterministicGame()->board->rows();
        // (4,4) blocks the naive rot-2 bar (5,3 is the piece's own current
        // cell and is never on the board); (7,4) blocks the [+1,0] kick;
        // (6,6) blocks the small board-downward leg.
        $rows[4][4] = Tetromino::I;
        $rows[4][7] = Tetromino::I;
        $rows[6][6] = Tetromino::I;
        $board = new Board($rows);

        $game = $this->deterministicGame()
            ->mutate(['board' => $board, 'piece' => new Piece(Tetromino::T, 1, 4, 3)]);

        [$after] = $game->update($this->key('up'));

        self::assertSame(2, $after->piece->rotation, 'clockwise R→2 must succeed via the big kick');
        self::assertSame(4, $after->piece->x, 'the [0, +2] kick shifts no column');
        self::assertSame(1, $after->piece->y, 'spec +2 must land two rows UP (y 3 → 1)');
    }
}
