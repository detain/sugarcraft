<?php

/**
 * candy-tetris/tests/Support/ScoresTest.php
 *
 * MA-23 pins for the record store: the missing-file null door, the
 * round-trip through the versioned envelope, the 0600 mode, and the
 * loud-refusal shapes (malformed JSON, foreign version, missing or
 * mis-typed fields, negative counters) that keep a corrupt file from
 * ever being trusted — or silently zeroed.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Support\Scores;

final class ScoresTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-tetris-scores-' . uniqid('', true);
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $leftover) {
            if ($leftover !== '.' && $leftover !== '..') {
                @unlink($this->dir . '/' . $leftover);
            }
        }
        @rmdir($this->dir);
    }

    public function testLoadAnswersNullForNoPathAndForAMissingFile(): void
    {
        self::assertNull(Scores::load(null));
        self::assertNull(Scores::load($this->dir . '/absent.json'));
    }

    public function testSymlinkedStoreIsRefusedLoudlyNotReadThrough(): void
    {
        // lstat never follows a link: a planted scores.json -> <record> is
        // refused by name (the same loud channel corruption uses) instead of
        // being opened through the link — and the refusal must not cost the
        // record living at the target.
        $target = $this->dir . '/target.json';
        (new Scores(10, 1, 0, 0, true))->save($target);
        $link = $this->dir . '/scores.json';
        symlink($target, $link);

        try {
            Scores::load($link);
            self::fail('a symlinked store must be refused, not opened through the link');
        } catch (\RuntimeException $problem) {
            self::assertStringContainsString($link, $problem->getMessage());
            self::assertStringContainsString('not a regular file', $problem->getMessage());
        }

        self::assertNotNull(Scores::load($target), 'refusing the link must leave the target intact');
    }

    public function testSaveThenLoadRoundTripsEveryField(): void
    {
        $path = $this->dir . '/scores.json';
        $record = new Scores(41_300, 12, 3, 5, true);
        $record->save($path);

        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertSame(41_300, $read->soloBest);
        self::assertSame(12, $read->soloGames);
        self::assertSame(3, $read->versusWins);
        self::assertSame(5, $read->versusLosses);
        self::assertTrue($read->onboarded);
    }

    public function testSavePublishesTheFileAtOwnerOnlyMode(): void
    {
        $path = $this->dir . '/scores.json';
        (new Scores())->save($path);

        clearstatcache();
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testSaveCarriesTheVersionedEnvelopeShape(): void
    {
        $path = $this->dir . '/scores.json';
        (new Scores(null, 0, 0, 0, false))->save($path);

        $raw = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($raw);
        self::assertSame(1, $raw['version']);
        self::assertSame(
            ['soloBest', 'soloGames', 'versusWins', 'versusLosses', 'onboarded'],
            array_keys($raw['data']),
        );
        self::assertNull($raw['data']['soloBest']);
    }

    public function testMalformedJsonIsRefusedNamingThePath(): void
    {
        $this->refuse("not json at all\n", 'invalid persistence format');
    }

    public function testForeignEnvelopeVersionIsRefused(): void
    {
        $this->refuse(json_encode(['version' => 2, 'data' => []]), 'invalid persistence format');
    }

    public function testEnvelopeWithoutDataIsRefused(): void
    {
        $this->refuse(json_encode(['version' => 1]), 'invalid persistence format');
    }

    public function testMissingFieldIsRefused(): void
    {
        $this->refuse(json_encode([
            'version' => 1,
            'data' => ['soloBest' => 10, 'soloGames' => 1, 'versusWins' => 0, 'versusLosses' => 0],
        ]), 'invalid persistence format');
    }

    public function testStringTypedCounterIsRefused(): void
    {
        $this->refuse(json_encode([
            'version' => 1,
            'data' => ['soloBest' => 10, 'soloGames' => 'one', 'versusWins' => 0, 'versusLosses' => 0, 'onboarded' => false],
        ]), 'invalid persistence format');
    }

    public function testNegativeCountersAreRefusedAsIncoherent(): void
    {
        $this->refuse(json_encode([
            'version' => 1,
            'data' => ['soloBest' => -5, 'soloGames' => 1, 'versusWins' => 0, 'versusLosses' => 0, 'onboarded' => false],
        ]), 'invalid persistence counters');
    }

    public function testWithSoloMovesTheBestUpwardsOnlyAndCountsOnce(): void
    {
        $record = new Scores(500, 1, 0, 0, false);
        $lower = $record->withSolo(300);
        self::assertSame(500, $lower->soloBest, 'the best never moves backwards');
        self::assertSame(2, $lower->soloGames);

        $higher = $record->withSolo(900);
        self::assertSame(900, $higher->soloBest);
        self::assertSame(2, $higher->soloGames);

        $first = (new Scores())->withSolo(120);
        self::assertSame(120, $first->soloBest, 'the very first game seeds the best');
        self::assertSame(1, $first->soloGames);
    }

    public function testBeatsSoloBestTreatsTheEmptyRecordAsBeatable(): void
    {
        self::assertTrue((new Scores())->beatsSoloBest(0));
        self::assertTrue((new Scores(500))->beatsSoloBest(501));
        self::assertFalse((new Scores(500))->beatsSoloBest(500), 'a tie is not a new best');
        self::assertFalse((new Scores(500))->beatsSoloBest(100));
    }

    public function testVersusResultWalksExactlyOneSide(): void
    {
        $won = (new Scores())->withVersusResult(true);
        self::assertSame(1, $won->versusWins);
        self::assertSame(0, $won->versusLosses);

        $lost = (new Scores())->withVersusResult(false);
        self::assertSame(0, $lost->versusWins);
        self::assertSame(1, $lost->versusLosses);
    }

    public function testOnboardedFlagFlipsOnceAndNeverBack(): void
    {
        $flagged = (new Scores())->withOnboarded();
        self::assertTrue($flagged->onboarded);
        self::assertTrue($flagged->withOnboarded()->onboarded);
    }

    public function testSoloRecordCarriesTheOnboardedFlagAcrossSaves(): void
    {
        $path = $this->dir . '/scores.json';
        (new Scores(10, 1, 0, 0, true))->save($path);
        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertTrue($read->onboarded, 'the on-ramp choice must survive a solo settle write');
    }

    private function refuse(string $contents, string $needle): void
    {
        $path = $this->dir . '/scores.json';
        file_put_contents($path, $contents);

        try {
            Scores::load($path);
            self::fail("expected a refusal naming {$needle}");
        } catch (\RuntimeException $problem) {
            self::assertStringContainsString($needle, $problem->getMessage());
            self::assertStringContainsString($path, $problem->getMessage(), 'refusals name the file they read');
        }
    }
}
