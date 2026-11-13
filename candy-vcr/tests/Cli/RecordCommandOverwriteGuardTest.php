<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Tests\Support\RequiresWorkingPty;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * M10 (and its mi8 sibling): `record --output <existing>` used to fopen
 * 'wb' straight over whatever cassette already lived at the path — the
 * silent annihilation of possibly the only copy of a session. Recording
 * is destructive BY NATURE, so the gate is explicit: an existing target
 * is refused (exit 2) unless --force says otherwise. The third pin is
 * the C3/mi8 half: when the recorder never opened, the CLI must not
 * announce a "recorded" success it did not earn.
 */
final class RecordCommandOverwriteGuardTest extends TestCase
{
    use RequiresWorkingPty;

    public function testExistingCassetteIsRefusedWithoutForce(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rec-guard-');
        $this->assertIsString($path);
        \file_put_contents($path, 'SENTINEL-CASSETTE');

        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory();
        $stderr = Stream::memory();

        try {
            $rc = $cmd->run(
                ['--output', $path, '--', '/bin/echo', 'unused'],
                $stdout,
                $stderr,
            );
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('refusing to overwrite', $err);
            // The refusal must happen BEFORE any spawn: the file is intact.
            $this->assertSame('SENTINEL-CASSETTE', \file_get_contents($path));
        } finally {
            \fclose($stdout);
            \fclose($stderr);
            @\unlink($path);
        }
    }

    public function testUsageAdvertisesTheForceFlag(): void
    {
        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory();
        $stderr = Stream::memory();

        try {
            $rc = $cmd->run([], $stdout, $stderr);
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $usage = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('--force', $usage);
            $this->assertStringContainsString('refuses (exit 2)', $usage);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }

    public function testForceFlagReplacesTheTarget(): void
    {
        $this->requirePtySyscalls();

        // The positive half of M10: --force means it — the same path gets a
        // real cassette and the success line rides back.
        $path = \tempnam(\sys_get_temp_dir(), 'rec-force-');
        $this->assertIsString($path);
        \file_put_contents($path, 'OLD-CASSETTE');

        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory();
        $stderr = Stream::memory();

        try {
            $rc = $cmd->run(
                ['--force', '--output', $path, '--', ...self::echoArgv('ok')],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc);
            $this->assertStringStartsWith('{"v":1', (string) \file_get_contents($path));
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('candy-vcr: recorded', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
            @\unlink($path);
        }
    }

    public function testFailedCaptureDoesNotAnnounceSuccess(): void
    {
        $this->requirePtySyscalls();

        // A directory that cannot exist at fopen time: the framework fails
        // AFTER the child was spawned (the M11 shape), so the error path
        // must not also print the unconditional success line.
        $missingDir = \sys_get_temp_dir() . '/candy-vcr-guard-' . \uniqid() . '/never/path';
        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory();
        $stderr = Stream::memory();

        try {
            $rc = $cmd->run(
                ['--output', $missingDir . '/x.cas', '--', ...self::echoArgv('ok')],
                $stdout,
                $stderr,
            );
            $this->assertSame(1, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('cannot open recorder file', $err);
            $this->assertStringNotContainsString('candy-vcr: recorded', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }
}
