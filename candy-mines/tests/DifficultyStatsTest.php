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

    /**
     * Full round-trip through the AtomicJsonFile-backed store, exercising
     * every field including the nullable best-time slots. Pins the save()
     * payload mapping: a swapped/zeroed field would surface here.
     */
    public function testAtomicRoundTripPreservesAllFieldsIncludingNulls(): void
    {
        $stats = new Stats(
            easyGames: 7,
            easyWins: 4,
            easyBest: null,
            mediumGames: 3,
            mediumWins: 2,
            mediumBest: 99,
            expertGames: 1,
            expertWins: 0,
            expertBest: null,
        );

        DifficultyStats::fromStats($stats)->save($this->persistencePath);
        $loaded = DifficultyStats::load($this->persistencePath)?->getStats();

        $this->assertNotNull($loaded);
        $this->assertSame(7, $loaded->easyGames);
        $this->assertSame(4, $loaded->easyWins);
        $this->assertNull($loaded->easyBest);
        $this->assertSame(3, $loaded->mediumGames);
        $this->assertSame(2, $loaded->mediumWins);
        $this->assertSame(99, $loaded->mediumBest);
        $this->assertSame(1, $loaded->expertGames);
        $this->assertSame(0, $loaded->expertWins);
        $this->assertNull($loaded->expertBest);
    }

    /**
     * The atomic write must leave the directory holding ONLY the target file —
     * no temp artifact (whatever naming scheme AtomicJsonFile uses). Robust to
     * the candy-core temp pattern `.<name>.tmp.<hex>`, unlike a `.tmp_*` glob.
     */
    public function testSaveLeavesNoTempArtifacts(): void
    {
        DifficultyStats::fromStats(new Stats(easyGames: 1))->save($this->persistencePath);

        $entries = array_values(array_diff(scandir($this->tmpDir), ['.', '..']));
        $this->assertSame(['stats.json'], $entries, 'Only the target file should remain');
    }

    /**
     * A valid-but-non-array top level (e.g. a bare JSON scalar) is corruption,
     * not empty state — load() must reject it loudly rather than treat it as
     * absent. Enforced by AtomicJsonFile::read()/Json::decodeArray.
     */
    public function testLoadThrowsRuntimeExceptionOnNonArrayTopLevel(): void
    {
        file_put_contents($this->persistencePath, '5');
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }

    /**
     * Malformed JSON in a present file surfaces as \JsonException (the
     * JSON_THROW_ON_ERROR contract carried through AtomicJsonFile::read()).
     */
    public function testLoadThrowsJsonExceptionOnMalformedJson(): void
    {
        file_put_contents($this->persistencePath, '{not valid json');
        $this->expectException(\JsonException::class);
        DifficultyStats::load($this->persistencePath);
    }

    /**
     * On-disk backward-compat: files written by the pre-migration save()
     * were COMPACT `{"version":1,"data":{...}}`. load() must still read them
     * byte-for-byte, so existing stats files survive the format switch to
     * pretty-printed output. Pins the exact on-disk key contract.
     */
    public function testLoadReadsLegacyCompactPayload(): void
    {
        // Exactly what the old hand-rolled save() emitted: compact, no PRETTY.
        $legacy = json_encode([
            'version' => 1,
            'data' => [
                'easyGames' => 11,
                'easyWins' => 6,
                'easyBest' => 55,
                'mediumGames' => 0,
                'mediumWins' => 0,
                'mediumBest' => null,
                'expertGames' => 0,
                'expertWins' => 0,
                'expertBest' => null,
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->persistencePath, $legacy);

        $loaded = DifficultyStats::load($this->persistencePath)?->getStats();
        $this->assertNotNull($loaded);
        $this->assertSame(11, $loaded->easyGames);
        $this->assertSame(6, $loaded->easyWins);
        $this->assertSame(55, $loaded->easyBest);
    }
}
