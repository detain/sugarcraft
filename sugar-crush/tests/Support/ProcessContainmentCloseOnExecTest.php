<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * {@see ProcessContainment::closeOnExec()} (audit B3): a stream_socket_pair()
 * end is inheritable by default; once marked, an exec'd child no longer
 * receives it, and every unsupported input answers false without a warning.
 */
final class ProcessContainmentCloseOnExecTest extends TestCase
{
    public function testAMarkedSocketEndIsNotInheritedByASpawnedCommand(): void
    {
        if (!\extension_loaded('ffi') || !\is_dir('/proc/self/fd')) {
            self::markTestSkipped('closeOnExec() needs ext-ffi and /proc/self/fd.');
        }

        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$kept, $marked] = $pair;

        self::assertTrue(ProcessContainment::closeOnExec($marked));

        $process = \proc_open(['/bin/sh', '-c', 'ls -l /proc/self/fd'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $listing = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        self::assertSame(0, \proc_close($process), $stderr);

        $keptIno = (string) \fstat($kept)['ino'];
        $markedIno = (string) \fstat($marked)['ino'];
        \fclose($kept);
        \fclose($marked);

        self::assertStringContainsString('socket:[' . $keptIno . ']', $listing, 'the unmarked control end must still be inherited, or this proves nothing');
        self::assertStringNotContainsString('socket:[' . $markedIno . ']', $listing);
    }

    public function testAnythingButALiveStreamIsAQuietNo(): void
    {
        $handle = \fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        \fclose($handle);

        self::assertFalse(ProcessContainment::closeOnExec($handle));
        self::assertFalse(ProcessContainment::closeOnExec(null));
        self::assertFalse(ProcessContainment::closeOnExec('not a stream'));
    }
}
