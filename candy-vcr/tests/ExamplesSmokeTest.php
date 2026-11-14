<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use Symfony\Component\Process\Process;

/**
 * F4 (round 90): examples/record.php and examples/replay.php fataled at
 * class-definition time once sugar-core's Model contract gained
 * subscriptions() — exactly the class of drift a smoke test exists to
 * catch. All three examples (record, replay, inspect) run for real here:
 * record writes a cassette, replay re-feeds it and prints its equality
 * verdict, inspect reads it back (VCR-S47).
 */
final class ExamplesSmokeTest extends TestCase
{
    private const LIB_ROOT = __DIR__ . '/..';

    public function testRecordThenReplayExamplesRunCleanEndToEnd(): void
    {
        $cassette = tempnam(sys_get_temp_dir(), 'cv-smoke-') . '.cas';
        try {
            $record = $this->runExample('examples/record.php', $cassette);
            $this->assertSame(0, $record->getExitCode(), 'record.php: ' . $record->getErrorOutput());
            $this->assertStringContainsString('recorded cassette', $record->getOutput());
            $this->assertGreaterThan(0, filesize($cassette), 'record.php must leave a non-empty cassette');

            $replay = $this->runExample('examples/replay.php', $cassette);
            $this->assertSame(0, $replay->getExitCode(), 'replay.php: ' . $replay->getErrorOutput() . $replay->getOutput());
            $this->assertStringContainsString('replay OK', $replay->getOutput());

            // Third arm (finding 123): inspect must run over the fresh
            // cassette too — it is the read side of the same trio, and
            // since VCR-S41 an envelope it cannot decode must print '@?'
            // and finish, not fatal.
            $inspect = $this->runExample('examples/inspect.php', $cassette);
            $this->assertSame(0, $inspect->getExitCode(), 'inspect.php: ' . $inspect->getErrorOutput());
            $this->assertStringContainsString('event(s)', $inspect->getOutput());
        } finally {
            @unlink($cassette);
        }
    }

    /**
     * Findings 101/103: argument-less runs must not leave artifacts in
     * the checkout. The no-arg default now lands under the system temp
     * dir, so the examples/ tree stays byte-stable — the test-grade
     * equivalent of `git status --porcelain examples/` being empty.
     */
    public function testNoArgumentRecordRunLeavesTheCheckoutAlone(): void
    {
        $record = $this->runExample('examples/record.php');
        $this->assertSame(0, $record->getExitCode(), $record->getErrorOutput());
        $this->assertStringContainsString('recorded cassette', $record->getOutput());
        $this->assertFileDoesNotExist(self::LIB_ROOT . '/examples/cassettes/recorded.cas');

        $inspect = $this->runExample('examples/inspect.php');
        $this->assertSame(0, $inspect->getExitCode(), $inspect->getErrorOutput());

        $replay = $this->runExample('examples/replay.php');
        $this->assertSame(0, $replay->getExitCode(), $replay->getErrorOutput());
    }

    public function testBundledCounterCassetteStillReplays(): void
    {
        $replay = $this->runExample('examples/replay.php', 'examples/cassettes/counter.cas');

        $this->assertSame(0, $replay->getExitCode(), $replay->getErrorOutput() . $replay->getOutput());
        $this->assertStringContainsString('replay OK', $replay->getOutput());
    }

    /**
     * VCR-S47 × VCR-S41 seam: an envelope the registry refuses must
     * print '@?' in the inspect example, not fatal it. Pre-guard the
     * named refusal escaped the example as an uncaught exception.
     */
    public function testInspectExampleSurvivesAMalformedEnvelope(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-smoke-') . '.cas';
        try {
            (new JsonlFormat())->write(
                new Cassette(
                    new CassetteHeader(
                        version: 1,
                        createdAt: '2026-05-08T12:00:00Z',
                        cols: 80,
                        rows: 24,
                        runtime: 'sugarcraft/candy-vcr@dev',
                    ),
                    [
                        new Event(t: 0.0, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'type' => 'bogus', 'rune' => 'x']]),
                    ],
                ),
                $path,
            );

            $inspect = $this->runExample('examples/inspect.php', $path);
            $this->assertSame(0, $inspect->getExitCode(), 'inspect.php: ' . $inspect->getErrorOutput());
            $this->assertStringContainsString('@?', $inspect->getOutput(), 'the refused envelope prints @? and the walk finishes');
        } finally {
            @unlink($path);
        }
    }

    private function runExample(string $script, string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $script, ...$args], self::LIB_ROOT);
        $process->setTimeout(60.0);
        $process->run();

        return $process;
    }
}
