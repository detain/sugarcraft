<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Stats;

/**
 * M10 guard: Game and Stats were refactored off hand-copied `new self(...)`
 * reconstruction onto the shared Mutable trait, whose mutate() rebuilds via
 *   new static(...array_merge(get_object_vars($this), $changes)).
 *
 * That is only SAFE if every constructor parameter name equals its property
 * name (a mismatch means the field is silently never spread into the
 * constructor). These censuses enumerate each class's promoted constructor
 * parameters through reflection and assert the identity-reconstruct path
 * preserves every one of them — so a future field added WITHOUT a matching
 * property, or a rename that desyncs the two, reddens here instead of
 * quietly vanishing on the next keypress.
 */
final class CarryCensusTest extends TestCase
{
    /**
     * A cursor-only move must carry every other Game field forward
     * byte-identically (board object, PRNG identity, clock, stats, path).
     */
    public function testMoveCursorCarriesEveryCtorField(): void
    {
        $rand = static fn(int $max): int => $max;         // identity closure, asserted by handle
        $stats = new Stats(easyGames: 3, easyWins: 1, easyBest: 9, standardGames: 7);
        $game = new Game(
            board: Board::blank(5, 5, 3),
            cursorX: 1,
            cursorY: 2,
            rand: $rand,
            startedAt: 1000.25,
            elapsedSeconds: 7,
            stats: $stats,
            statsPath: '/tmp/whatever.json',
        );

        $moved = self::invokePrivate($game, 'moveCursor', [0, 0]); // zero delta → same cursor

        // Every constructor parameter is a real readonly property on the class.
        foreach ($this->ctorParams(Game::class) as $param) {
            self::assertTrue(
                (new ReflectionClass(Game::class))->hasProperty($param),
                "Game ctor parameter \${$param} has no backing property — mutate() cannot carry it",
            );
        }

        self::assertSame($game->board, $moved->board, 'board object identity survives a null move');
        self::assertSame($game->cursorX, $moved->cursorX);
        self::assertSame($game->cursorY, $moved->cursorY);
        self::assertSame($rand, $moved->rand(), 'PRNG closure identity survives');
        self::assertSame($game->startedAt, $moved->startedAt);
        self::assertSame($game->elapsedSeconds, $moved->elapsedSeconds);
        self::assertSame($stats, $moved->stats, 'stats object identity survives');
        self::assertSame($game->statsPath, $moved->statsPath);
    }

    /**
     * withGame(Easy) must touch ONLY the three easy fields; the other nine
     * (three presets x three fields) ride the carry unchanged.
     */
    public function testStatsWithGameTouchesOnlyItsOwnPresetBucket(): void
    {
        $stats = new Stats(
            easyGames: 0, easyWins: 0, easyBest: null,
            standardGames: 5, standardWins: 4, standardBest: 11,
            mediumGames: 2, mediumWins: 1, mediumBest: 60,
            expertGames: 8, expertWins: 6, expertBest: 200,
        );
        $after = $stats->withGame(Difficulty::Easy, won: true, time: 42);

        foreach ($this->ctorParams(Stats::class) as $param) {
            self::assertTrue(
                (new ReflectionClass(Stats::class))->hasProperty($param),
                "Stats ctor parameter \${$param} has no backing property — mutate() cannot carry it",
            );
        }

        // easy bucket moved
        self::assertSame(1, $after->easyGames);
        self::assertSame(1, $after->easyWins);
        self::assertSame(42, $after->easyBest);
        // every other bucket carried byte-identically
        self::assertSame(5, $after->standardGames);
        self::assertSame(4, $after->standardWins);
        self::assertSame(11, $after->standardBest);
        self::assertSame(2, $after->mediumGames);
        self::assertSame(1, $after->mediumWins);
        self::assertSame(60, $after->mediumBest);
        self::assertSame(8, $after->expertGames);
        self::assertSame(6, $after->expertWins);
        self::assertSame(200, $after->expertBest);
    }

    /** @return list<string> */
    private function ctorParams(string $class): array
    {
        return array_map(
            static fn($p): string => $p->getName(),
            (new ReflectionClass($class))->getConstructor()->getParameters(),
        );
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function invokePrivate(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invoke($obj, ...$args);
    }
}
