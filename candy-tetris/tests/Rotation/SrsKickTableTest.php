<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Rotation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Rotation\SrsKickTable;
use SugarCraft\Tetris\Tetromino;

/**
 * Transcription pin for {@see SrsKickTable}: the table must keep matching the
 * published SRS numbers (spec coordinates, y grows UP).
 *
 * Board-space meaning is NOT tested here — that is
 * {@see SrsKickBehaviorTest()}'s job. Splitting the two means an edit to the
 * constants reddens this file, and moving the spec→board sign conversion
 * reddens that one; neither can silently vouch for the other.
 */
final class SrsKickTableTest extends TestCase
{
    /**
     * The SRS JLSTZ offsets exactly as published on tetris.fandom.com/wiki/SRS
     * (each transition's five (dx, dy) pairs, spec axes).
     *
     * @var array<string, list<array{int, int}>>
     */
    private const SPEC_JLSTZ = [
        '0→R' => [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
        'R→2' => [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
        '2→L' => [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
        'L→0' => [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
        'R→0' => [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
        '0→L' => [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
        'L→2' => [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
        '2→R' => [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
    ];

    /**
     * The published SRS I-piece offsets (spec axes).
     *
     * @var array<string, list<array{int, int}>>
     */
    private const SPEC_I = [
        '0→R' => [[0, 0], [-2, 0], [1, 0], [-2, -1], [1, 2]],
        'R→2' => [[0, 0], [-1, 0], [2, 0], [-1, 2], [2, -1]],
        '2→L' => [[0, 0], [2, 0], [-1, 0], [2, 1], [-1, -2]],
        'L→0' => [[0, 0], [1, 0], [-2, 0], [1, -2], [-2, 1]],
        'R→0' => [[0, 0], [2, 0], [-1, 0], [2, 1], [-1, -2]],
        '0→L' => [[0, 0], [-1, 0], [2, 0], [-1, -2], [2, 1]],
        'L→2' => [[0, 0], [1, 0], [-2, 0], [1, 2], [-2, -1]],
        '2→R' => [[0, 0], [-2, 0], [1, 0], [-2, 1], [1, 2]],
    ];

    /** Transition key → [from, to] rotation indices. */
    private const TRANSITIONS = [
        '0→R' => [0, 1],
        'R→2' => [1, 2],
        '2→L' => [2, 3],
        'L→0' => [3, 0],
        'R→0' => [1, 0],
        '0→L' => [0, 3],
        'L→2' => [3, 2],
        '2→R' => [2, 1],
    ];

    public function testTableMatchesThePublishedSrsTranscription(): void
    {
        foreach (self::SPEC_JLSTZ as $key => $spec) {
            [$from, $to] = self::TRANSITIONS[$key];
            foreach ([Tetromino::T, Tetromino::J, Tetromino::L, Tetromino::S, Tetromino::Z] as $kind) {
                self::assertSame(
                    $spec,
                    SrsKickTable::kicks($kind, $from, $to),
                    sprintf('JLSTZ kick %s drifted from the spec for %s', $key, $kind->name),
                );
            }
        }

        foreach (self::SPEC_I as $key => $spec) {
            [$from, $to] = self::TRANSITIONS[$key];
            self::assertSame(
                $spec,
                SrsKickTable::kicks(Tetromino::I, $from, $to),
                sprintf('I-piece kick %s drifted from the spec', $key),
            );
        }
    }

    public function testEighteenDegreeTransitionsAreNaiveOnly(): void
    {
        // 180° flips have no SRS spec entry; the shipped tables give them the
        // naive offset only, so callers never invent an unspec'd kick.
        foreach ([[0, 2], [2, 0], [1, 3], [3, 1]] as [$from, $to]) {
            self::assertSame([[0, 0]], SrsKickTable::kicks(Tetromino::T, $from, $to));
            self::assertSame([[0, 0]], SrsKickTable::kicks(Tetromino::I, $from, $to));
        }
    }

    public function testOPieceRoutesThroughTheJlstzTable(): void
    {
        $this->assertSame(
            SrsKickTable::kicks(Tetromino::T, 0, 1),
            SrsKickTable::kicks(Tetromino::O, 0, 1),
            'O uses the JLSTZ table',
        );
    }

    public function testAllKicksAgreesWithPerTransitionLookup(): void
    {
        // Structural consistency pin (no literals): allKicks() and kicks()
        // must expose one table, and the I piece must differ from JLSTZ.
        foreach ([Tetromino::T, Tetromino::I, Tetromino::O] as $kind) {
            $all = SrsKickTable::allKicks($kind);
            foreach (self::TRANSITIONS as $key => [$from, $to]) {
                self::assertArrayHasKey($key, $all);
                self::assertSame($all[$key], SrsKickTable::kicks($kind, $from, $to));
            }
        }

        $this::assertNotSame(
            SrsKickTable::allKicks(Tetromino::T)['0→R'],
            SrsKickTable::allKicks(Tetromino::I)['0→R'],
            'the I piece must keep its own larger offsets',
        );
    }
}
