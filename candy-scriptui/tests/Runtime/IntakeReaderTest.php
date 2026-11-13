<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\IntakeReader;
use SugarCraft\ScriptUi\Runtime\IntakeTimedOut;

/**
 * scriptui-32: piped intake runs under the --timeout deadline. The unit legs
 * drive IntakeReader on a pipe; the child legs prove the real binary exits
 * 124 on a stalled producer instead of blocking forever.
 */
final class IntakeReaderTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/candy-scriptui';

    public function testReadsEverythingBeforeEof(): void
    {
        [$r, $w] = $this->pipe();
        fwrite($w, "alpha\nbeta\n");
        fclose($w);

        self::assertSame("alpha\nbeta\n", IntakeReader::read($r, 2.0));
    }

    public function testZeroDeadlineIsThePlainBlockingRead(): void
    {
        [$r, $w] = $this->pipe();
        fwrite($w, 'x');
        fclose($w);

        self::assertSame('x', IntakeReader::read($r, 0.0));
    }

    public function testSilentProducerPastTheDeadlineThrows(): void
    {
        [$r, $w] = $this->pipe();
        fwrite($w, 'partial');

        $start = microtime(true);
        try {
            IntakeReader::read($r, 0.3);
            self::fail('a stalled pipe must time out');
        } catch (IntakeTimedOut) {
            self::assertLessThan(3.0, microtime(true) - $start);
        } finally {
            fclose($w);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function stalledCommands(): array
    {
        return [
            'filter' => ['filter'],
            'pager'  => ['pager'],
            'table'  => ['table'],
            'join'   => ['join'],
            'log'    => ['log'],
            'style'  => ['style'],
            'format' => ['format'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stalledCommands')]
    public function testStalledPipeExits124AtTheDeadline(string $command): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('needs POSIX pipes');
        }
        $start = microtime(true);
        $proc = proc_open(
            [PHP_BINARY, self::BIN, $command, '--timeout', '1'],
            // stdin is a pipe whose write end this test holds open and never
            // writes: the producer is stalled, not finished.
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'TERM' => 'xterm'],
        );
        self::assertIsResource($proc);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        $status = proc_close($proc);

        self::assertSame(124, $status, "$command must exit 124 when its pipe stalls past --timeout");
        self::assertLessThan(20.0, microtime(true) - $start);
    }

    /** @return array{0:resource,1:resource} */
    private function pipe(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
