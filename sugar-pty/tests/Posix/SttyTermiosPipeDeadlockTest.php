<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Pty\Posix\SttyTermios;

/**
 * {@see SttyTermios} drains stty's stdout and stderr together, and bounds the
 * run (AUD §2 #59).
 *
 * The shell-out used to read stdout to EOF before it touched stderr. An stty
 * that wrote more than one pipe buffer to stderr blocked on that full pipe and
 * never closed stdout, while this side blocked waiting for stdout's EOF: a
 * deadlock with no bound. The pins swap a fake `stty` onto PATH (proc_open's
 * array form resolves the bare name through PATH), so the stderr volume and
 * the exit code are under the test's control.
 */
final class SttyTermiosPipeDeadlockTest extends TestCase
{
    /** Well past the 64 KiB Linux pipe buffer. */
    private const STDERR_BYTES = 300_000;

    private string $binDir = '';

    private string|false $savedPath = false;

    protected function setUp(): void
    {
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
        $this->binDir = \sys_get_temp_dir() . '/sugar-pty-fake-stty-' . \bin2hex(\random_bytes(6));
        \mkdir($this->binDir);
        $this->savedPath = \getenv('PATH');
        \putenv('PATH=' . $this->binDir . ':' . (string) $this->savedPath);
    }

    protected function tearDown(): void
    {
        if ($this->savedPath === false) {
            \putenv('PATH');
        } else {
            \putenv('PATH=' . $this->savedPath);
        }
        if ($this->binDir !== '') {
            @\unlink($this->binDir . '/stty');
            @\rmdir($this->binDir);
        }
        $this->setTimeout(SttyTermios::DEFAULT_TIMEOUT_SEC);
    }

    public function testStderrPastThePipeBufferDoesNotDeadlockTheCapture(): void
    {
        $this->fakeStty('head -c ' . self::STDERR_BYTES . ' /dev/zero >&2; printf fake-mode; exit 0');

        $start = \microtime(true);
        $snapshot = (new SttyTermios(0))->current();

        $this->assertLessThan(SttyTermios::DEFAULT_TIMEOUT_SEC, \microtime(true) - $start);
        $this->assertSame('fake-mode', $this->savedMode($snapshot), 'stdout was not captured beside the stderr flood');
    }

    public function testNonZeroExitAfterAStderrFloodStillReportsTheFailure(): void
    {
        $this->fakeStty('head -c ' . self::STDERR_BYTES . ' /dev/zero | tr "\\0" e >&2; exit 3');

        try {
            (new SttyTermios(0))->current();
            $this->fail('a failing stty must raise');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rc=3', $e->getMessage());
            $this->assertStringContainsString(\str_repeat('e', 64), $e->getMessage(), 'stderr was not collected');
        }
    }

    public function testAWedgedSttyIsKilledAtTheBound(): void
    {
        $this->fakeStty('exec sleep 30');
        $this->setTimeout(0.3);

        $start = \microtime(true);
        try {
            (new SttyTermios(0))->current();
            $this->fail('a wedged stty must raise');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('killed', $e->getMessage());
        }
        $this->assertLessThan(5.0, \microtime(true) - $start, 'the bound did not stop the wait');
    }

    private function fakeStty(string $body): void
    {
        $path = $this->binDir . '/stty';
        \file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        \chmod($path, 0755);
    }

    private function savedMode(SttyTermios $termios): string
    {
        return (string) (new \ReflectionProperty(SttyTermios::class, 'savedMode'))->getValue($termios);
    }

    private function setTimeout(float $sec): void
    {
        (new \ReflectionProperty(SttyTermios::class, 'timeoutSec'))->setValue(null, $sec);
    }
}
