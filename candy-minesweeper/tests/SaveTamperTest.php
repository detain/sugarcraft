<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Stats\DifficultyStats;

/**
 * lib-reviews-1103 M01-M03 (+m01, m06): the savegame trust boundary.
 *
 * The single-slot save under $HOME is user-editable by definition, so the
 * loaders are the enforcement point: Board::unserialize() refuses wire
 * payloads that describe impossible boards (type casts, mine-count census
 * mismatches, poisoned adjacency, exploded-without-mines, revealed-cell
 * fake wins, out-of-ladder dimensions), and Game's 'L' envelope handling
 * refuses junk cursors/elapsed instead of casting them. DifficultyStats
 * refuses a non-array data section and negative/incoherent counters that
 * would otherwise silently clobber the real tally on the next save.
 *
 * HOME and XDG_CONFIG_HOME are sandboxed per test exactly as in SaveLoadTest
 * (both snapshotted BEFORE any mutation, both restore forms handled —
 * savePath() asks XDG first, so a HOME-only sandbox leaks the slot wherever
 * XDG_CONFIG_HOME is exported).
 */
final class SaveTamperTest extends TestCase
{
    private ?string $savedHome;
    private string|false $savedXdg;
    private string $homeDir = '';

    protected function setUp(): void
    {
        $this->savedHome = getenv('HOME');
        $this->savedXdg = getenv('XDG_CONFIG_HOME');
        $this->homeDir = sys_get_temp_dir() . '/candy-minesweeper-tamper-' . bin2hex(random_bytes(6));
        mkdir($this->homeDir, 0700, true);
        putenv('HOME=' . $this->homeDir);
        putenv('XDG_CONFIG_HOME=' . $this->homeDir . '/xdg-config');
        mkdir($this->homeDir . '/xdg-config', 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->savedHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->savedHome);
        }
        if ($this->savedXdg === false) {
            putenv('XDG_CONFIG_HOME');
        } else {
            putenv('XDG_CONFIG_HOME=' . $this->savedXdg);
        }
        if (is_dir($this->homeDir)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->homeDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $node) {
                $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
            }
            @rmdir($this->homeDir);
        }
    }

    // ---- Board::unserialize wire-shape refusals -------------------------

    /** @param list<list<array{0:bool,1:bool,2:bool,3:int}>> $cells */
    private static function payload(int $w, int $h, int $m, bool $p, bool $e, array $cells): string
    {
        return (string) json_encode([
            'v' => 1, 'w' => $w, 'h' => $h, 'm' => $m,
            'p' => $p, 'e' => $e, 'r' => 0, 'c' => $cells,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return list<list<array{0:bool,1:bool,2:bool,3:int}>> */
    private static function blankCells(int $w, int $h): array
    {
        $cells = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $row[] = [false, false, false, 0];
            }
            $cells[] = $row;
        }
        return $cells;
    }

    private function expectRefusal(string $json, string $why): void
    {
        try {
            Board::unserialize($json);
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail("expected refusal: {$why}");
    }

    /**
     * Positive control: the walk only ever fails on the tamper under test —
     * a same-shape honest payload always loads.
     */
    public function testHonestPlacedBoardLoads(): void
    {
        $g = Game::start(3, 3, 2, static fn(int $max): int => 0);
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));
        $board = Board::unserialize($g->board->serialize());
        $this->assertSame($g->board->serialize(), $board->serialize());
    }

    public function testPlacedMineCensusMismatchIsRefused(): void
    {
        // Claims m=2 but plants 3 mines — 'remaining' and the win threshold
        // would both be computed off a lie. Adjacency is fully consistent
        // with the 3-mine layout so ONLY the census guard can catch this.
        $cells = self::blankCells(3, 3);
        foreach ([[0,0],[0,1],[1,0]] as [$yy,$xx]) { $cells[$yy][$xx][0] = true; }
        foreach ($cells as $y => $row) {
            foreach ($row as $x => $cell) {
                if ($cell[0]) { continue; }
                $n = 0;
                foreach ([-1,0,1] as $dy) foreach ([-1,0,1] as $dx) {
                    if ($dx === 0 && $dy === 0) { continue; }
                    $ny = $y+$dy; $nx = $x+$dx;
                    if ($ny>=0 && $ny<3 && $nx>=0 && $nx<3 && $cells[$ny][$nx][0]) { $n++; }
                }
                $cells[$y][$x][3] = $n;
            }
        }
        $this->expectRefusal(
            self::payload(3, 3, 2, true, false, $cells),
            'planted bits above the promised mine count',
        );
    }

    public function testUnplacedBoardCannotCarryMines(): void
    {
        // p=false with planted bits: mines must only appear via the placement
        // walk, which also recomputes adjacency.
        $cells = self::blankCells(3, 3);
        $cells[2][2][0] = true;
        $this->expectRefusal(
            self::payload(3, 3, 1, false, false, $cells),
            'planted mines without placement',
        );
    }

    public function testUnplacedBoardCannotCarryRevealedCells(): void
    {
        // The fake-win shape: a 3×3 claiming m=2 with 7 revealed empties and
        // no mines would satisfy revealedCount === w*h - m on the first draw.
        $cells = self::blankCells(3, 3);
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                $cells[$y][$x][1] = true;
            }
        }
        $this->expectRefusal(
            self::payload(3, 3, 2, false, false, $cells),
            'revealed cells on an unplaced board',
        );
    }

    public function testAdjacencyPoisonIsRefused(): void
    {
        // Census passes (one planted mine, m=1), but the number lying next to
        // it claims 12 — the renderer would write a two-glyph string into a
        // one-cell buffer and desync the frame.
        $cells = self::blankCells(3, 3);
        $cells[0][0][0] = true;
        $cells[0][1][3] = 12;
        $this->expectRefusal(
            self::payload(3, 3, 1, true, false, $cells),
            'adjacency above the recomputed neighbour count',
        );
    }

    public function testSilentTypeCastsAreRefused(): void
    {
        // The pre-fix loader did (bool)/(int) casts on everything: "4" and
        // true became legal wire values. Each slot must carry its own type.
        $base = self::blankCells(2, 2);
        $strInt = $base;
        $strInt[0][0][3] = '4';
        $this->expectRefusal(json_encode(['v' => 1, 'w' => 2, 'h' => 2, 'm' => 1, 'p' => false, 'e' => false, 'r' => 0, 'c' => $strInt]), 'string adjacency');

        $boolM = $base;
        $this->expectRefusal(str_replace('"m":1', '"m":true', self::payload(2, 2, 1, true, false, $boolM)), 'boolean mine count');

        $this->expectRefusal(str_replace('"w":2', '"w":"2"', self::payload(2, 2, 1, false, false, $base)), 'string width');
        $this->expectRefusal(str_replace('"p":false', '"p":0', self::payload(2, 2, 1, false, false, $base)), 'integer minesPlaced');
        $this->expectRefusal(str_replace('"v":1', '"v":"1"', self::payload(2, 2, 1, false, false, $base)), 'string version');
    }

    public function testDimensionsOutsideTheLadderAreRefused(): void
    {
        // 200x200 "savegames" used to boot into a terminal-crawling board.
        // The loader clamps to the same 2..50 ladder CustomDifficulty enforces.
        $this->expectRefusal(self::payload(51, 2, 1, false, false, self::blankCells(51, 2)), 'width above the 50-cell ladder');
        $this->expectRefusal(self::payload(2, 60, 1, false, false, self::blankCells(2, 60)), 'height above the 50-cell ladder');
        $this->expectRefusal(self::payload(1, 2, 1, false, false, self::blankCells(1, 2)), 'width below two');
    }

    public function testFlaggedAndRevealedTogetherIsRefused(): void
    {
        $cells = self::blankCells(3, 3);
        $cells[0][0][1] = true;
        $cells[0][0][2] = true;
        $this->expectRefusal(
            self::payload(3, 3, 2, false, false, $cells),
            'cell both flagged and revealed (unreachable state)',
        );
    }

    public function testExplodedWithoutPlacementIsRefused(): void
    {
        $cells = self::blankCells(3, 3);
        $this->expectRefusal(
            self::payload(3, 3, 2, false, true, $cells),
            'exploded board with no mines placed',
        );
    }

    public function testRevealedMineWithoutExplosionIsRefused(): void
    {
        // A revealed mine with EVERY adjacency consistent: (0,0) planted and
        // revealed, its three non-mine neighbours all claiming 1. Only the
        // exploded ⇔ revealed-mine coherence guard refuses this.
        $cells = self::blankCells(3, 3);
        $cells[0][0][0] = true;
        $cells[0][0][1] = true; // revealed mine
        $cells[0][1][3] = 1;
        $cells[1][0][3] = 1;
        $cells[1][1][3] = 1;
        $this->expectRefusal(
            self::payload(3, 3, 1, true, false, $cells),
            'revealed mine that never detonated',
        );
    }

    // ---- Game 'L' envelope refusals ---------------------------------------

    /** @param array<string,mixed> $envelope */
    private function writeSlot(array $envelope): void
    {
        $path = Game::savePath();
        self::assertIsString($path, 'sanity: HOME is set so the slot resolves');
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    private static function loadArm(Game $g): Game
    {
        [$next, ] = $g->update(new KeyMsg(KeyType::Char, 'L'));
        return $next;
    }

    public function testEnvelopeCursorJunkKeepsTheCurrentGame(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'l')); // cursorX → 1
        $this->assertSame(1, $g->cursorX, 'sanity: the cursor moved');

        // A live, loadable save one click from a fresh cursor (0): if the
        // envelope ever loads, the cursor visibly differs from 1.
        $this->writeSlot([
            'v'       => 1,
            'board'   => $g->board->serialize(),
            'cursorX' => [1],   // array — an (int) cast would TypeError inside new self()
            'cursorY' => 0,
            'elapsed' => null,
        ]);
        $this->assertSame(1, self::loadArm($g)->cursorX, 'array cursor must refuse the whole envelope');

        $this->writeSlot([
            'v'       => 1,
            'board'   => $g->board->serialize(),
            'cursorX' => '0',   // numeric string — the pre-fix (int) cast accepted it
            'cursorY' => 0,
            'elapsed' => null,
        ]);
        $this->assertSame(1, self::loadArm($g)->cursorX, 'string cursor must refuse the envelope');

        // Positive control: the honest integer form DOES load and moves the cursor.
        $this->writeSlot([
            'v'       => 1,
            'board'   => $g->board->serialize(),
            'cursorX' => 0,
            'cursorY' => 0,
            'elapsed' => null,
        ]);
        $this->assertSame(0, self::loadArm($g)->cursorX, 'sanity: a well-typed envelope loads');
    }

    public function testNonFiniteAndOverlongElapsedAreRefused(): void
    {
        // msw-03: json_decode delivers 1e400 as INF and any string of nines
        // as a finite giant — both used to pass the (float) >= 0 rung and
        // seed the clock with a fantasy. The band is [0, 3600].
        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'l')); // cursorX → 1
        $honest = json_encode([
            'v' => 1, 'board' => $g->board->serialize(),
            'cursorX' => 0, 'cursorY' => 0, 'elapsed' => null,
        ], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"elapsed":null', $honest, 'sanity: the null anchor is present to rewrite');

        foreach (['INF via 1e400' => '1e400', 'finite giant' => '999999', 'just over the band' => '3601'] as $why => $literal) {
            $this->writeSlotRaw(str_replace('"elapsed":null', '"elapsed":' . $literal, $honest));
            $this->assertSame(1, self::loadArm($g)->cursorX, "elapsed {$why} must refuse the envelope");
        }

        // Positive control: the top of the band and the floor both still load.
        foreach (['42', '3600', '0.0'] as $literal) {
            $this->writeSlotRaw(str_replace('"elapsed":null', '"elapsed":' . $literal, $honest));
            $this->assertSame(0, self::loadArm($g)->cursorX, "elapsed {$literal} must still load");
        }
    }

    /** @param array<string,mixed> $envelope */
    private function writeSlotRaw(string $json): void
    {
        $path = Game::savePath();
        self::assertIsString($path, 'sanity: HOME is set so the slot resolves');
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $json);
    }

    public function testRestoredWinCountsButNeverSetsABestTime(): void
    {
        // msw-03 trust spine: the clock rides back from a user-editable file,
        // so a win on restored time is ineligible for the record — games and
        // wins still tally, bestTime and the badge never move. The board is
        // EASY-shaped so it files into the same bucket the record lives in.
        $seed = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);
        [$seed, ] = $seed->update(new KeyMsg(KeyType::Space)); // opener, board unfinished
        $this->assertFalse($seed->board->isWon(), 'sanity: opener leaves the board live');
        $this->writeSlot([
            'v'       => 1,
            'board'   => $seed->board->serialize(),
            'cursorX' => 0, 'cursorY' => 0,
            'elapsed' => 5,
        ]);

        $g = Game::withDifficulty(
            Difficulty::EASY,
            static fn(int $max): int => 0,
            stats: new \SugarCraft\Minesweeper\Stats(easyGames: 4, easyWins: 2, easyBest: 300),
        );
        $loaded = self::loadArm($g);
        $this->assertTrue($loaded->restoredGame, 'L brands the session as restored');

        // Drive the loaded board home: reveal every safe cell not yet open.
        $board = $loaded->board;
        for ($y = 0; $y < $board->height; $y++) {
            for ($x = 0; $x < $board->width; $x++) {
                $c = $board->cell($x, $y);
                if ($c !== null && !$c->mine && !$c->revealed) {
                    $loaded = $this->walkSpace($loaded, $x, $y);
                }
            }
        }
        $this->assertTrue($loaded->board->isWon(), 'sanity: the restored board was drivable to a win');
        $this->assertFalse($loaded->newBest, 'a restored clock cannot wear the badge');
        $this->assertSame(5, $loaded->stats->gamesPlayed(Difficulty::EASY), 'the game still counts');
        $this->assertSame(3, $loaded->stats->wins(Difficulty::EASY), 'the win still counts');
        $this->assertSame(300, $loaded->stats->bestTime(Difficulty::EASY), 'the record never moved');
    }

    public function testRestoredFlagSurvivesRestartButNothingElseLendsIt(): void
    {
        $this->assertFalse(Game::start(3, 3, 2)->restoredGame, 'a fresh session starts clean');
        $this->writeSlot([
            'v' => 1, 'board' => self::payload(3, 3, 2, false, false, self::blankCells(3, 3)),
            'cursorX' => 0, 'cursorY' => 0, 'elapsed' => null,
        ]);
        [$loaded, ] = Game::start(3, 3, 2)->update(new KeyMsg(KeyType::Char, 'L'));
        $this->assertTrue($loaded->restoredGame, 'sanity: the load branded it');
        [$restarted, ] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertTrue($restarted->restoredGame, 'the brand is monotonic per session — no laundering');
    }

    private function walkSpace(Game $g, int $x, int $y): Game
    {
        while ($g->cursorX !== $x) {
            [$g, ] = $g->update(new KeyMsg($g->cursorX < $x ? KeyType::Right : KeyType::Left));
        }
        while ($g->cursorY !== $y) {
            [$g, ] = $g->update(new KeyMsg($g->cursorY < $y ? KeyType::Down : KeyType::Up));
        }
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));
        return $g;
    }

    public function testNegativeAndStringElapsedAreRefused(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));      // arms the clock
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'l'));  // cursorX → 1

        // -100 rendered as a garbage '-1:-40' clock before the guard.
        $this->writeSlot([
            'v' => 1, 'board' => $g->board->serialize(),
            'cursorX' => 0, 'cursorY' => 0, 'elapsed' => -100,
        ]);
        $this->assertSame(1, self::loadArm($g)->cursorX, 'negative elapsed must refuse the envelope');

        $this->writeSlot([
            'v' => 1, 'board' => $g->board->serialize(),
            'cursorX' => 0, 'cursorY' => 0, 'elapsed' => '12',
        ]);
        $this->assertSame(1, self::loadArm($g)->cursorX, 'string elapsed must refuse the envelope');

        $this->writeSlot([
            'v' => 1, 'board' => $g->board->serialize(),
            'cursorX' => 0, 'cursorY' => 0, 'elapsed' => 12.0,
        ]);
        $loaded = self::loadArm($g);
        $this->assertSame(0, $loaded->cursorX, 'sanity: the float form loads');
        $this->assertEqualsWithDelta(12.0, $loaded->elapsed(), 0.5, 'and re-anchors the clock near 12s');
    }

    public function testFinishedSaveKeepsItsFrozenTime(): void
    {
        // m06: a save taken on a finished board used to load into a running
        // clock (or a null one); the envelope's elapsed must ride back as the
        // frozen final time instead.
        $cells = self::blankCells(3, 3);
        $cells[0][0][0] = true; // mine (0,0)
        $cells[2][2][0] = true; // mine (2,2)
        // Honest adjacency for the 7 open cells around those two mines.
        $adj = [[null, 1, 0], [1, 2, 1], [0, 1, null]];
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                if ($cells[$y][$x][0]) {
                    continue;
                }
                $cells[$y][$x][1] = true;      // all seven revealed → won
                $cells[$y][$x][3] = $adj[$y][$x];
            }
        }
        $this->writeSlot([
            'v'       => 1,
            'board'   => self::payload(3, 3, 2, true, false, $cells),
            'cursorX' => 0, 'cursorY' => 0,
            'elapsed' => 42,
        ]);

        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        $loaded = self::loadArm($g);
        $this->assertTrue($loaded->board->isWon(), 'sanity: the consistent finished layout loaded');
        $this->assertSame(42.0, $loaded->elapsed(), 'final time is frozen, not re-anchored to now');
    }

    // ---- DifficultyStats persistence refusals ----------------------------

    /** @param mixed $data */
    private function writeStats(mixed $data): string
    {
        $path = $this->homeDir . '/stats.json';
        file_put_contents($path, json_encode(['version' => 1, 'data' => $data], JSON_THROW_ON_ERROR));
        return $path;
    }

    public function testStatsWithScalarDataSectionIsRefused(): void
    {
        // m01: a string data section previously zero-defaulted silently and
        // the next save CLOBBERED the real tally.
        $path = $this->writeStats('not-an-array');
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($path);
    }

    public function testStatsWithNegativeBestTimeIsRefused(): void
    {
        $path = $this->writeStats(['easyGames' => 1, 'easyWins' => 1, 'easyBest' => -7]);
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($path);
    }

    public function testStatsWithWinsAboveGamesIsRefused(): void
    {
        $path = $this->writeStats(['easyGames' => 2, 'easyWins' => 99]);
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($path);
    }

    public function testStatsHonestFileStillLoads(): void
    {
        // Positive control: the refusals above are shape-specific.
        $path = $this->writeStats(['easyGames' => 5, 'easyWins' => 3, 'easyBest' => 11]);
        $loaded = DifficultyStats::load($path);
        $this->assertNotNull($loaded);
        $this->assertSame(5, $loaded->stats()->gamesPlayed(Difficulty::EASY));
    }

    public function testStatsSaveIsOwnerOnly(): void
    {
        $path = $this->homeDir . '/saved.json';
        DifficultyStats::fromStats(new \SugarCraft\Minesweeper\Stats(easyGames: 1, easyWins: 1, easyBest: 9))
            ->save($path);
        self::assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777, 'm01: stats ride the same 0600 gate as the savegame slot');
    }
}
