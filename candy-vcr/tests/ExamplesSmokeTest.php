<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * F4 (round 90): examples/record.php and examples/replay.php fataled at
 * class-definition time once candy-core's Model contract gained
 * subscriptions() — exactly the class of drift a smoke test exists to
 * catch. Both examples run for real here: record writes a cassette,
 * replay re-feeds it and prints its equality verdict.
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
        } finally {
            @unlink($cassette);
        }
    }

    public function testBundledCounterCassetteStillReplays(): void
    {
        $replay = $this->runExample('examples/replay.php', 'examples/cassettes/counter.cas');

        $this->assertSame(0, $replay->getExitCode(), $replay->getErrorOutput() . $replay->getOutput());
        $this->assertStringContainsString('replay OK', $replay->getOutput());
    }

    private function runExample(string $script, string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $script, ...$args], self::LIB_ROOT);
        $process->setTimeout(60.0);
        $process->run();

        return $process;
    }
}
