<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Mines\Difficulty;
use SugarCraft\Mines\Stats;
use SugarCraft\Mines\Stats\DifficultyStats;
use PHPUnit\Framework\TestCase;

final class DifficultyStatsTest extends TestCase
{
    private string $tmpDir;
    private string $persistencePath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-mines-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->persistencePath = $this->tmpDir . '/stats.json';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $stats = new Stats(
            easyGames: 5,
            easyWins: 3,
            easyBest: 42,
            mediumGames: 2,
            mediumWins: 1,
            mediumBest: 120,
            expertGames: 0,
            expertWins: 0,
            expertBest: null,
        );

        $ds = DifficultyStats::fromStats($stats);
        $ds->save($this->persistencePath);

        $loaded = DifficultyStats::load($this->persistencePath);
        $this->assertNotNull($loaded);

        $loadedStats = $loaded->getStats();
        $this->assertSame(5, $loadedStats->easyGames);
        $this->assertSame(3, $loadedStats->easyWins);
        $this->assertSame(42, $loadedStats->easyBest);
        $this->assertSame(2, $loadedStats->mediumGames);
        $this->assertSame(1, $loadedStats->mediumWins);
        $this->assertSame(120, $loadedStats->mediumBest);
        $this->assertSame(0, $loadedStats->expertGames);
    }

    public function testLoadReturnsNullWhenFileDoesNotExist(): void
    {
        $this->assertNull(DifficultyStats::load($this->tmpDir . '/nonexistent.json'));
    }

    public function testWithGameReturnsNewInstance(): void
    {
        $stats = new Stats();
        $ds = DifficultyStats::fromStats($stats);

        $ds2 = $ds->withGame(Difficulty::EASY, true, 30);

        $this->assertNotSame($ds, $ds2);
        $this->assertSame(1, $ds2->getStats()->gamesPlayed(Difficulty::EASY));
        $this->assertSame(30, $ds2->getStats()->bestTime(Difficulty::EASY));

        // Original is unchanged.
        $this->assertSame(0, $ds->getStats()->gamesPlayed(Difficulty::EASY));
    }

    public function testAtomicSaveCreatesTempFileThenRenames(): void
    {
        $stats = new Stats(easyGames: 1);
        $ds = DifficultyStats::fromStats($stats);

        $ds->save($this->persistencePath);

        // Target file should exist.
        $this->assertFileExists($this->persistencePath);

        // No temp files should remain.
        $tempFiles = glob($this->tmpDir . '/.tmp_*');
        $this->assertEmpty($tempFiles, 'No leftover temp files expected');
    }

    public function testSaveOverwritesExistingFile(): void
    {
        $ds1 = DifficultyStats::fromStats(new Stats(easyGames: 1));
        $ds1->save($this->persistencePath);

        $ds2 = DifficultyStats::fromStats(new Stats(easyGames: 99));
        $ds2->save($this->persistencePath);

        $loaded = DifficultyStats::load($this->persistencePath);
        $this->assertSame(99, $loaded->getStats()->easyGames);
    }

    public function testLoadThrowsOnNonIntegerField(): void
    {
        // Write a valid v1 payload but with easyGames as a string instead of int.
        $payload = json_encode([
            'version' => 1,
            'data' => [
                'easyGames' => 'not-an-integer',
                'easyWins' => 0,
                'easyBest' => null,
                'mediumGames' => 0,
                'mediumWins' => 0,
                'mediumBest' => null,
                'expertGames' => 0,
                'expertWins' => 0,
                'expertBest' => null,
            ],
        ]);
        file_put_contents($this->persistencePath, $payload);
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }
}
