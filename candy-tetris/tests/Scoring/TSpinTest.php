<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Scoring;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Scoring\TSpin;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class TSpinTest extends TestCase
{
    public function testNonTPieceReturnsNoSpin(): void
    {
        $board = new Board();
        $piece = new Piece(Tetromino::I, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, false);
        $this->assertFalse($tspin->active);
        $this->assertFalse($tspin->mini);
    }

    public function testLastInputNotRotationMeansNoSpin(): void
    {
        $board = new Board();
        // T at rotation 0, last input was not a rotation
        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, false);
        $this->assertFalse($tspin->active);
    }

    public function testTPieceRotatedButCornersNotFilled(): void
    {
        $board = new Board();
        // last input was a rotation, but no corners are filled
        $piece = new Piece(Tetromino::T, 1, 5, 15);
        $tspin = TSpin::detect($board, $piece, true);
        $this->assertFalse($tspin->active);
    }

    public function testAllFourCornersFilledIsFullSpin(): void
    {
        // T at (5,15) rotation 2: centre (6,16); corners (5,15) (7,15)
        // (5,17) (7,17). Fill all four — both nub-side corners among them
        // — → full T-Spin.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[15][5] = Tetromino::I;  // TL corner
        $rows[15][7] = Tetromino::I;  // TR corner
        $rows[17][5] = Tetromino::I;  // BL corner
        $rows[17][7] = Tetromino::I;  // BR corner

        $board = new Board($rows);
        $piece = new Piece(Tetromino::T, 2, 5, 15);
        $tspin = TSpin::detect($board, $piece, true);
        $this->assertTrue($tspin->active);
        $this->assertFalse($tspin->mini, '4 corners = full T-Spin, not mini');
    }

    public function testTPieceWithTwoFrontCornersIsTSpinMini(): void
    {
        // T rotation 0 at (5,15): centre (6,16) → corners at rows 15 and 17.
        // Front corners for rotation 0 = TL (5,15) and TR (7,15).
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[15][5] = Tetromino::I;  // TL corner
        $rows[15][7] = Tetromino::I;  // TR corner
        // BL and BR are NOT filled → exactly the front pair = mini

        $board = new Board($rows);
        // T at rotation 0, last input was the rotation
        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, true);
        $this->assertTrue($tspin->active);
        $this->assertTrue($tspin->mini, 'Only front corners filled (rotation 0) = T-Spin Mini');
    }

    public function testOutOfBoundsCornerCountsAsFilled(): void
    {
        // T at rotation 0 with its centre diagonals one row from the floor:
        // piece (5,22) → centre (6,23); corners (5,22) (7,22) (5,24) (7,24).
        // Rows 24+ are off-board (Board::ROWS = 24) → floor counts filled,
        // so BL/BR are filled; both painted front corners plus the two
        // floor corners reach four = full.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[22][5] = Tetromino::I;  // TL corner (in bounds, painted)
        $rows[22][7] = Tetromino::I;  // TR corner (in bounds, painted)
        $board = new Board($rows);

        $piece = new Piece(Tetromino::T, 0, 5, 22);
        $tspin = TSpin::detect($board, $piece, true);
        $this->assertTrue($tspin->active, 'OOB floor corners count as filled → 4 filled = T-Spin');
        $this->assertFalse($tspin->mini, 'nub pair painted + floor backs = full, two corners may come from the floor');
    }

    public function testFloorBacksPlusOneFrontIsKickOutMini(): void
    {
        // Same floor-hugging rot 0, but only the TL front corner is painted:
        // the two floor backs join it for three filled corners with a
        // single nub-side touch — the kick-out shape of candy-tetris-022,
        // which must score Mini (100), never the full 400.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[22][5] = Tetromino::I;  // TL corner only
        $board = new Board($rows);

        $piece = new Piece(Tetromino::T, 0, 5, 22);
        $tspin = TSpin::detect($board, $piece, true);
        $this->assertTrue($tspin->active, 'one front corner engaged still counts as a spin');
        $this->assertTrue($tspin->mini, '3 corners with one front = kick-out Mini (candy-tetris-022)');
    }

    public function testPointsConstants(): void
    {
        $this->assertSame(100, TSpin::T_SPIN_MINI_POINTS);
        $this->assertSame(400, TSpin::T_SPIN_POINTS);
    }

    /**
     * Guideline corners are the four diagonals around the T's CENTRE cell
     * (box (1,1)), not an expansion of the occupied bounding box. Cells far
     * outside the centre diagonals must never arm a spin — the pre-fix
     * bbox rule read the row-14 pair as rotation 0's front corners and
     * returned a Mini here.
     */
    public function testCornersOutsideTheCentreDiagonalsAreNotASpin(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        // Pre-fix bbox corners for T rot 0 at (5,15): TL=(4,14), TR=(8,14)
        $rows[14][4] = Tetromino::I;
        $rows[14][8] = Tetromino::I;

        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertFalse($tspin->active, 'row-14 cells are not corners of the centre at (6,16)');
    }

    /**
     * Three of four centre diagonals filled WITH BOTH nub-side corners =
     * full T-Spin, and the check must follow the centre in EVERY rotation.
     * The pre-fix bbox rule found zero corners for this rot-1 pocket (it
     * watched (5,13), (8,13), (5,17), (8,17)) and refused the spin outright.
     */
    public function testThreeCornersWithCompleteNubPairIsFullSpin(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        // T rot 1 at (5,14): centre (6,15); front corners TR (7,14) and BR (7,16)
        $rows[14][5] = Tetromino::I;  // TL (back)
        $rows[14][7] = Tetromino::I;  // TR (front)
        $rows[16][7] = Tetromino::I;  // BR (front)

        $piece = new Piece(Tetromino::T, 1, 5, 14);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertTrue($tspin->active);
        $this->assertFalse($tspin->mini, '3 corners with the nub pair complete = full T-Spin');
    }

    /**
     * The raw repro's overpay shape (candy-tetris-022): the classic
     * kick-out pocket — both BACK corners plus exactly ONE front corner
     * engaged. The corner COUNT is three, but the nub pair is not
     * complete, so the guideline scores it Mini; paying the full 400 (and
     * arming B2B) here is the farm this gate closes.
     */
    public function testThreeCornersWithOneFrontIsKickOutMini(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        // T rot 1 at (5,14): centre (6,15); TL, BL (backs) + BR (one front)
        $rows[14][5] = Tetromino::I;  // TL (back)
        $rows[16][5] = Tetromino::I;  // BL (back)
        $rows[16][7] = Tetromino::I;  // BR (front) — TR stays open

        $piece = new Piece(Tetromino::T, 1, 5, 14);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertTrue($tspin->active, 'one nub-side touch keeps it a spin');
        $this->assertTrue($tspin->mini, '3 corners with a single front = kick-out Mini, never full');
    }

    /**
     * A lone corner — even a front one — never engaged a slot: two
     * corners is the entry price for any spin.
     */
    public function testSingleCornerTouchIsNotASpin(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[15][5] = Tetromino::I;  // TL only (front for rot 0)

        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertFalse($tspin->active, 'one filled corner is not a spin');
    }

    /**
     * The nub-pair gate must read the FRONT pair from the rotation state,
     * never from raw corner names: for every rotation, two backs score
     * nothing, one front + one back scores Mini, two fronts still Mini
     * (only two corners), one front + both backs is the kick-out Mini,
     * and two fronts + one back is the full spin.
     */
    public function testNubPairGateAcrossAllRotations(): void
    {
        $fronts = [0 => ['TL', 'TR'], 1 => ['TR', 'BR'], 2 => ['BL', 'BR'], 3 => ['TL', 'BL']];
        $all = ['TL' => [5, 15], 'TR' => [7, 15], 'BL' => [5, 17], 'BR' => [7, 17]];

        foreach ($fronts as $rotation => $front) {
            $back = array_values(array_diff(array_keys($all), $front));

            // Two backs → not a spin.
            $this->assertFalse(self::spinAt($rotation, $back, $all)->active, "rot $rotation: two backs never engage");
            // One front + one back → Mini.
            $mini = self::spinAt($rotation, [$front[0], $back[0]], $all);
            $this->assertTrue($mini->active, "rot $rotation: one front + one back engages");
            $this->assertTrue($mini->mini, "rot $rotation: two corners with a front touch = Mini");
            // Both fronts, still exactly two corners → Mini.
            $pair = self::spinAt($rotation, $front, $all);
            $this->assertTrue($pair->active);
            $this->assertTrue($pair->mini, "rot $rotation: nub pair alone is only two corners = Mini");
            // One front + both backs → kick-out Mini (three corners, count alone must NOT pay full).
            $kick = self::spinAt($rotation, [$front[0], $back[0], $back[1]], $all);
            $this->assertTrue($kick->active);
            $this->assertTrue($kick->mini, "rot $rotation: 3 corners with one front = kick-out Mini");
            // Both fronts + one back → full.
            $full = self::spinAt($rotation, [$front[0], $front[1], $back[0]], $all);
            $this->assertTrue($full->active);
            $this->assertFalse($full->mini, "rot $rotation: 3 corners with the nub pair complete = full");
        }
    }

    /**
     * @param list<string> $names
     * @param array<string, array{int, int}> $all
     */
    private static function spinAt(int $rotation, array $names, array $all): TSpin
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        foreach ($names as $name) {
            [$x, $y] = $all[$name];
            $rows[$y][$x] = Tetromino::I;
        }

        return TSpin::detect(new Board($rows), new Piece(Tetromino::T, $rotation, 5, 15), true);
    }

    /**
     * Mini in a SIDE rotation: rot 3 (nub left) has front corners TL/BL.
     * Exactly those two filled = Mini. The pre-fix isFrontCornerPair
     * returned null for rotations 1 and 3, so a side-facing mini was
     * structurally impossible.
     */
    public function testTwoFrontCornersInLeftFacingRotationIsMini(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        // T rot 3 at (5,14): centre (6,15); front corners (5,14), (5,16)
        $rows[14][5] = Tetromino::I;  // TL (front for nub-left)
        $rows[16][5] = Tetromino::I;  // BL (front for nub-left)

        $piece = new Piece(Tetromino::T, 3, 5, 14);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertTrue($tspin->active);
        $this->assertTrue($tspin->mini, 'rot 3 front pair = Mini — sides can mini too');
    }

    /**
     * Exactly the two BACK corners filled (rot 0: bottom pair) is not a
     * spin at all under the guideline reading of the three-corner rule.
     * The pre-fix code had no back-pair rejection and returned a full
     * (non-mini) spin here.
     */
    public function testOnlyBackCornersFilledIsNotASpin(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        // T rot 0 at (5,15): centre (6,16); back corners (5,17), (7,17)
        $rows[17][5] = Tetromino::I;  // BL
        $rows[17][7] = Tetromino::I;  // BR

        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect(new Board($rows), $piece, true);
        $this->assertFalse($tspin->active, '2 back corners only = no spin');
    }

    public function testTSpinDetectionHandlesAllRotationsWithoutCrash(): void
    {
        // Verify T-Spin detection doesn't crash for all rotation values
        $board = new Board();
        $piece = new Piece(Tetromino::T, 0, 5, 15);

        for ($rot = 0; $rot <= 3; $rot++) {
            foreach ([false, true] as $lastWasRotation) {
                $pieceAtRot = new Piece(Tetromino::T, $rot, 5, 15);
                $tspin = TSpin::detect($board, $pieceAtRot, $lastWasRotation);
                // Just verify it returns a valid TSpin object
                $this->assertIsBool($tspin->active);
                $this->assertIsBool($tspin->mini);
            }
        }
    }
}
