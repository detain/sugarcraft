<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Stats;
use SugarCraft\Minesweeper\Stats\DifficultyStats;
use PHPUnit\Framework\TestCase;

final class DifficultyStatsTest extends TestCase
{
    private string $tmpDir;
    private string $persistencePath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-minesweeper-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->persistencePath = $this->tmpDir . '/stats.json';
    }

    protected function tearDown(): void
    {
        // FilesystemIterator, not glob('*'): AtomicJsonFile's lock sidecar is
        // a DOTFILE and glob would miss it, leaving rmdir to warn (which
        // failOnWarning turns into suite noise).
        foreach (new \FilesystemIterator($this->tmpDir) as $residue) {
            @unlink($residue->getPathname());
        }
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

        $loadedStats = $loaded->stats();
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

        $ds2 = $ds->withGame(Difficulty::Easy, true, 30);

        $this->assertNotSame($ds, $ds2);
        $this->assertSame(1, $ds2->stats()->gamesPlayed(Difficulty::Easy));
        $this->assertSame(30, $ds2->stats()->bestTime(Difficulty::Easy));

        // Original is unchanged.
        $this->assertSame(0, $ds->stats()->gamesPlayed(Difficulty::Easy));
    }

    public function testSaveOverwritesExistingFile(): void
    {
        $ds1 = DifficultyStats::fromStats(new Stats(easyGames: 1));
        $ds1->save($this->persistencePath);

        $ds2 = DifficultyStats::fromStats(new Stats(easyGames: 99));
        $ds2->save($this->persistencePath);

        $loaded = DifficultyStats::load($this->persistencePath);
        $this->assertSame(99, $loaded->stats()->easyGames);
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
     * Test expectNullableInt throws when a nullable field (like easyBest) is
     * present but not int — exercises the !is_int($v) branch in expectNullableInt.
     */
    public function testLoadThrowsOnNonIntegerNullableField(): void
    {
        // easyBest is nullable, but we write a string instead.
        $payload = json_encode([
            'version' => 1,
            'data' => [
                'easyGames' => 0,
                'easyWins' => 0,
                'easyBest' => 'not-an-int',
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
        $loaded = DifficultyStats::load($this->persistencePath)?->stats();

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
     * The atomic write must leave the directory holding ONLY the target file
     * plus the documented fixed-name lock sidecar — no random temp artifact
     * may survive (whatever naming scheme AtomicJsonFile uses). Robust to the
     * sugar-core temp pattern `.<name>.tmp.<hex>`; the persistent
     * `.stats.json.lock` flock sidecar is documented sugar-core design and
     * carved explicitly (same ruling as the sugar-dashboard temp-residue
     * carve) — carving it by exact name keeps every "no stray temp" leg live.
     */
    public function testSaveLeavesNoTempArtifacts(): void
    {
        DifficultyStats::fromStats(new Stats(easyGames: 1))->save($this->persistencePath);

        $entries = array_values(array_diff(scandir($this->tmpDir), ['.', '..', '.stats.json.lock']));
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
     * Missing 'data' key in the envelope must throw RuntimeException
     * (checked before version, so missing data also hits that branch first).
     */
    public function testLoadThrowsWhenDataKeyMissing(): void
    {
        $payload = json_encode([
            'version' => 1,
            // 'data' intentionally missing
        ]);
        file_put_contents($this->persistencePath, $payload);
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }

    /**
     * Unsupported version number must throw RuntimeException with the
     * version value in the message.
     */
    public function testLoadThrowsOnUnsupportedVersion(): void
    {
        $payload = json_encode([
            'version' => 99,
            'data' => [
                'easyGames' => 0, 'easyWins' => 0, 'easyBest' => null,
                'mediumGames' => 0, 'mediumWins' => 0, 'mediumBest' => null,
                'expertGames' => 0, 'expertWins' => 0, 'expertBest' => null,
            ],
        ]);
        file_put_contents($this->persistencePath, $payload);
        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }

    /**
     * On-disk format law: files may arrive compact or pretty (the format
     * switch to pretty-printed output never broke reads of the FULL key set),
     * but msw-12 made absent counter keys a refusal. This leg pins both
     * truths for a pre-Standard-era compact file: readable shape, refused
     * because the standard trio is genuinely missing.
     */
    public function testLoadRefusesLegacyCompactPayloadMissingStandardTrio(): void
    {
        // msw-12 turned the default-on-absent rung into a refusal, so a
        // pre-Standard-era compact file (which genuinely lacked the standard
        // trio) is now refused rather than silently re-zeroed: the CLI warns
        // "ignoring unreadable stats file" and starts a fresh full-set tally.
        // Losing one ancient tally is honest; overwriting a live file with
        // zeros is the clobber the guard exists to stop.
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

        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }

    public function testLoadRefusesSingleAbsentCounterKey(): void
    {
        // msw-12 law in isolation: a well-formed full-set file minus exactly
        // one counter is tampering, not a first-time zero.
        $payload = json_encode([
            'version' => 1,
            'data' => [
                'easyGames' => 3, 'easyWins' => 1, 'easyBest' => 21,
                'standardGames' => 0, 'standardWins' => 0, 'standardBest' => null,
                'mediumGames' => 0, 'mediumWins' => 0, 'mediumBest' => null,
                'expertWins' => 0, 'expertBest' => null,
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->persistencePath, $payload);

        $this->expectException(\RuntimeException::class);
        DifficultyStats::load($this->persistencePath);
    }
}
