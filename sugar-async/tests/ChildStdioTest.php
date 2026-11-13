<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\ChildPump;
use SugarCraft\Async\ChildStdio;
use SugarCraft\Async\DrainResult;
use SugarCraft\Async\WindowsPipes;

final class ChildStdioTest extends TestCase
{
    public function testDescriptorsAreSocketsOnWindowsAndPipesElsewhere(): void
    {
        $specs = ChildStdio::descriptors();
        self::assertSame([0, 1, 2], array_keys($specs));
        if (ChildStdio::usesSockets()) {
            self::assertSame([['socket'], ['socket'], ['pipe', 'w']], array_values($specs), 'stderr stays a pipe: grandchildren (git hooks under MSYS sh) inherit it');
        } else {
            self::assertSame([['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], array_values($specs));
        }

        self::assertSame([1, 2], array_keys(ChildStdio::descriptors([1, 2])));
        self::assertSame(\PHP_OS_FAMILY === 'Windows', ChildStdio::usesSockets());
    }

    public function testIsSocketTellsSocketsFromFiles(): void
    {
        $pair = stream_socket_pair(\PHP_OS_FAMILY === 'Windows' ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        self::assertTrue(ChildStdio::isSocket($pair[0]));
        $memory = fopen('php://memory', 'r+');
        self::assertFalse(ChildStdio::isSocket($memory));
        fclose($pair[0]);
        fclose($pair[1]);
        fclose($memory);
        self::assertFalse(ChildStdio::isSocket($memory), 'a closed stream is nothing');
    }

    /**
     * The point of the class: a relay writes a 200 KB stdin through these
     * descriptors under select without ever blocking the parent (a Windows
     * pipe would park the write at 4096 bytes), and reads the answer back.
     */
    public function testARelayWritesALargeStdinWithoutEverBlocking(): void
    {
        $proc = proc_open(
            [\PHP_BINARY, '-r', '$in = stream_get_contents(STDIN); fwrite(STDOUT, "got " . strlen($in)); fwrite(STDERR, "err");'],
            ChildStdio::descriptors(),
            $pipes,
        );
        self::assertIsResource($proc);
        stream_set_blocking($pipes[0], false);

        $payload = str_repeat('y', 200_000);
        $offset = 0;
        $deadline = microtime(true) + 10;
        while ($offset < \strlen($payload) && microtime(true) < $deadline) {
            $read = null;
            $write = [$pipes[0]];
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
                $n = fwrite($pipes[0], substr($payload, $offset, 65_536));
                $offset += \is_int($n) ? $n : 0;
            }
        }
        fclose($pipes[0]);
        self::assertSame(\strlen($payload), $offset);

        $t0 = microtime(true);
        $r = ChildPump::drain([1 => $pipes[1], 2 => $pipes[2]], $t0 + 10, 1024, running: WindowsPipes::runningProbe($proc));
        proc_close($proc);

        self::assertSame(DrainResult::CLOSED, $r->reason);
        self::assertSame('got 200000', $r->buffer(1));
        self::assertSame('err', $r->buffer(2));
        self::assertLessThan(5.0, microtime(true) - $t0);
    }
}
