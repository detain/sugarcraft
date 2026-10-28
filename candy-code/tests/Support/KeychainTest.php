<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Support\Keychain;

/**
 * C-SEC-8.e: `${keychain:<name>}` provider keys.
 *
 * The backend argv spellings, the per-process memoisation of hits and misses,
 * and the launch-notice shape of every miss are pinned through the spawn seam
 * so no test can hang on a host's real secret service; the real spawn path
 * (drain, exit-code verdict, wall bound, in-function reap) is pinned directly
 * against plain shells, which carry no secret anywhere.
 */
final class KeychainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Keychain::resetForTesting();
        LaunchNotices::reset();
    }

    protected function tearDown(): void
    {
        Keychain::resetForTesting();
        LaunchNotices::reset();
        parent::tearDown();
    }

    // ---- backend argv spellings ------------------------------------------

    public function testTheLinuxSpellingCarriesTheEntryAsItsAccount(): void
    {
        $this->assertSame(
            ['secret-tool', 'lookup', 'service', 'candy-code', 'account', 'candy-openai'],
            Keychain::argvFor('Linux', 'candy-openai'),
        );
    }

    public function testTheMacSpellingAsksSecurityForThePasswordItself(): void
    {
        $this->assertSame(
            ['security', 'find-generic-password', '-s', 'candy-code', '-a', 'candy-openai', '-w'],
            Keychain::argvFor('Darwin', 'candy-openai'),
        );
    }

    public function testFamiliesWithoutABackendHaveNoArgvAtAll(): void
    {
        $this->assertNull(Keychain::argvFor('Windows', 'candy-openai'));
        $this->assertNull(Keychain::argvFor('BSD', 'candy-openai'));
    }

    // ---- hit path through the spawn seam ---------------------------------

    public function testAHitResolvesTheSecretOffStdout(): void
    {
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'hit', 'stdout' => 'k3y-from-the-store', 'exitCode' => 0],
        );

        $this->assertSame('k3y-from-the-store', Keychain::lookup('candy-openai'));
    }

    public function testTheSecretNeverCrossesArgvOnlyTheEntryNameDoes(): void
    {
        $secret = 'sup3r-s3cr3t-value';
        $captured = [];
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static function (array $argv) use (&$captured, $secret): array {
                $captured = $argv;

                return ['status' => 'hit', 'stdout' => $secret, 'exitCode' => 0];
            },
        );

        $this->assertSame($secret, Keychain::lookup('openai/team-key'));

        // The entry name is visible in the listing (argv[5]); the secret is
        // absent from every argument, which is the whole point of this shape.
        $this->assertSame('openai/team-key', $captured[5]);
        foreach ($captured as $argument) {
            $this->assertStringNotContainsString($secret, $argument);
        }
    }

    public function testAHitIsMemoisedSoOneSpawnServesTheLaunch(): void
    {
        $calls = 0;
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static function (array $argv) use (&$calls): array {
                $calls++;

                return ['status' => 'hit', 'stdout' => 'once-only', 'exitCode' => 0];
            },
        );

        $this->assertSame('once-only', Keychain::lookup('shared-entry'));
        $this->assertSame('once-only', Keychain::lookup('shared-entry'));
        $this->assertSame(1, $calls);
    }

    // ---- miss shapes: null, one notice, no fatal --------------------------

    public function testAMissResolvesToNullAndNamesTheEntryInALaunchNotice(): void
    {
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'miss', 'stdout' => '', 'exitCode' => 1],
        );

        $this->assertNull(Keychain::lookup('gone-key'));

        $rows = LaunchNotices::rows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('gone-key', $rows[0]);
        $this->assertStringContainsString('not found', $rows[0]);
    }

    public function testAMissIsMemoisedLikeAHitSoTheLaunchSaysItOnce(): void
    {
        $calls = 0;
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static function (array $argv) use (&$calls): array {
                $calls++;

                return ['status' => 'miss', 'stdout' => '', 'exitCode' => 1];
            },
        );

        $this->assertNull(Keychain::lookup('gone-key'));
        $this->assertNull(Keychain::lookup('gone-key'));
        $this->assertSame(1, $calls);
        $this->assertCount(1, LaunchNotices::rows());
    }

    public function testAnExitZeroWithNothingOnStdoutIsAMissNotAnEmptySecret(): void
    {
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'hit', 'stdout' => '', 'exitCode' => 0],
        );

        $this->assertNull(Keychain::lookup('empty-answer'));
        $this->assertCount(1, LaunchNotices::rows());
    }

    public function testAnAbsentHelperBinaryIsAnnouncedAndResolvesToNull(): void
    {
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'missing', 'stdout' => '', 'exitCode' => 127],
        );

        $this->assertNull(Keychain::lookup('candy-openai'));

        $rows = LaunchNotices::rows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('is not installed', $rows[0]);
        $this->assertStringContainsString('secret-tool', $rows[0]);
    }

    public function testAWedgedHelperTimesOutAnnouncedWithItsBound(): void
    {
        Keychain::armOsForTesting('Linux');
        Keychain::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'timeout', 'stdout' => '', 'exitCode' => 124],
        );

        $this->assertNull(Keychain::lookup('wedged-entry'));

        $rows = LaunchNotices::rows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('wedged-entry', $rows[0]);
        $this->assertStringContainsString('did not answer', $rows[0]);
        $this->assertStringContainsString('seconds', $rows[0]);
    }

    public function testAnEmptyEntryNameCostsNoSpawn(): void
    {
        $calls = 0;
        Keychain::armSpawnSeamForTesting(
            static function (array $argv) use (&$calls): array {
                $calls++;

                return ['status' => 'hit', 'stdout' => 'never', 'exitCode' => 0];
            },
        );

        $this->assertNull(Keychain::lookup(''));
        $this->assertSame(0, $calls);
        $rows = LaunchNotices::rows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('names no entry', $rows[0]);
    }

    public function testAFamilyWithoutABackendSaysSoPlainlyWithoutSpawning(): void
    {
        $calls = 0;
        Keychain::armOsForTesting('Windows');
        Keychain::armSpawnSeamForTesting(
            static function (array $argv) use (&$calls): array {
                $calls++;

                return ['status' => 'hit', 'stdout' => 'impossible', 'exitCode' => 0];
            },
        );

        $this->assertNull(Keychain::lookup('candy-openai'));
        $this->assertSame(0, $calls);
        $rows = LaunchNotices::rows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Windows', $rows[0]);
        $this->assertStringContainsString('no keychain backend', $rows[0]);
    }

    // ---- the real spawn path (plain shells; no secret anywhere) ----------

    /**
     * @param list<string> $argv
     * @return array{status:string,stdout:string,exitCode:int}
     */
    private function spawnForReal(array $argv): array
    {
        $method = new \ReflectionMethod(Keychain::class, 'spawn');

        return $method->invoke(null, $argv);
    }

    public function testTheRealSpawnPathReadsTheSecretOffStdoutAndExitsZero(): void
    {
        $result = $this->spawnForReal(['sh', '-c', 'printf s3cr3t']);

        $this->assertSame('hit', $result['status']);
        $this->assertSame('s3cr3t', $result['stdout']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function testTheRealSpawnPathReportsANonZeroExitAsAMiss(): void
    {
        $result = $this->spawnForReal(['sh', '-c', 'exit 3']);

        $this->assertSame('miss', $result['status']);
        $this->assertSame(3, $result['exitCode']);
    }

    public function testTheRealSpawnPathIsMissingWhenTheBinaryAnswersNothing(): void
    {
        $result = $this->spawnForReal(['no-such-helper-xyz']);

        $this->assertSame('missing', $result['status']);
        $this->assertSame(127, $result['exitCode']);
    }

    public function testTheRealSpawnPathBoundsAWedgedHelperAndReapsItHere(): void
    {
        // A shrunk bound stands in for the shipped five-second one: the point
        // pinned is that the deadline fires, the TERM/KILL ladder runs, and
        // proc_close happens inside spawn() — never a child left behind.
        Keychain::armTimeoutForTesting(0.2);
        $started = microtime(true);

        $result = $this->spawnForReal(['sh', '-c', 'sleep 5']);
        $elapsed = microtime(true) - $started;

        $this->assertSame('timeout', $result['status']);
        $this->assertSame(124, $result['exitCode']);
        // Bound 0.2s + the reaper's two one-second rungs + scheduling slack;
        // a missing ladder would wait out the full sleep instead.
        $this->assertLessThan(4.5, $elapsed);
    }

    public function testTheRealSpawnPathNeverForwardsStdoutToTheNoticeShelfOnTimeout(): void
    {
        Keychain::armTimeoutForTesting(0.2);

        $result = $this->spawnForReal(['sh', '-c', 'printf printed; sleep 5']);

        // Whatever the child managed to print before the cut belongs to nobody:
        // a timeout is a miss, whole, and its stdout is discarded.
        $this->assertSame('timeout', $result['status']);
        $this->assertSame('', $result['stdout']);
    }
}
