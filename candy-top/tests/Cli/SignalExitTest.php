<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Tests\Support\PtyRun;

/**
 * S-X06: a plain `kill <pid>` (SIGTERM) or a closed terminal (SIGHUP) must
 * leave candy-top through the clean path: alt screen left, the config
 * written by the post-run exitSave(). Runs bin/candy-top on a real pty;
 * the barrier is the alt-screen enter in the output, never a sleep.
 */
final class SignalExitTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $why = PtyRun::unavailable();
        if ($why !== null) {
            $this->markTestSkipped($why);
        }
        $this->dir = sys_get_temp_dir() . '/ct-sig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @return iterable<string, array{int}> */
    public static function signals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGHUP' => [SIGHUP];
    }

    #[DataProvider('signals')]
    public function testSignalStopsCleanlyAndSavesConfig(int $signal): void
    {
        $config = $this->dir . '/btop.conf';
        $run = PtyRun::start(['--fake', '--config', $config]);

        $this->assertTrue($run->readUntil("\x1b[?1049h"), "alt screen was entered; got: " . substr(json_encode($run->output()) ?: '', 0, 300));
        // Two synchronized frames: the first is painted BEFORE the loop starts
        // (a stop requested in that gap is lost: sugar-core's Program stops
        // the loop before run() sets it running), so the second proves the
        // loop itself is turning and the signal arms are live.
        $this->assertTrue($run->readUntilCount("\x1b[?2026l", 2, 15.0), 'the loop is running (second frame drawn)');

        $run->signal($signal);
        $code = $run->reap(10.0);

        $this->assertNotNull($code, 'the child exited under the deadline instead of hanging');
        $this->assertSame(0, $code, 'a signal-driven stop is a clean exit');
        $this->assertStringContainsString("\x1b[?1049l", $run->output(), 'alt screen left');
        $this->assertFileExists($config, 'exitSave wrote the new config after the stop');
    }
}
