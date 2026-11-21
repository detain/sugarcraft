<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\DrainsStreamedChild;

/**
 * The stream pump behind StatusLineCommand (ScriptHook now runs on ChildRun), exercised
 * directly. These pin the two ways a streamed child can finish on its own: EOF
 * on both pipes with each stream's bytes kept in order, and a child that writes
 * nothing and exits, which must close the walk at once rather than wait out the
 * deadline. A stop from $keepGoing is pinned too, since it is the seam the
 * status line uses to end a runaway child early.
 */
final class DrainsStreamedChildTest extends TestCase
{
    /**
     * @return array{0: string, 1: string, 2: string, 3: float} reason, stdout, stderr, elapsed seconds
     */
    private static function walkChild(string $script, ?callable $keepGoing = null): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The pump under test reads POSIX pipes through /bin/sh.');
        }

        $process = proc_open(['/bin/sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $captured = ['', ''];
        $started = microtime(true);
        $reason = DrainsStreamedChild::walk(
            $pipes[1],
            $pipes[2],
            $started + 10.0,
            0.05,
            static fn (): float => microtime(true),
            static function (int $slot, string $bytes) use (&$captured): void {
                $captured[$slot] .= $bytes;
            },
            $keepGoing,
        );
        $elapsed = microtime(true) - $started;

        // A STOPPED walk leaves the child running by design; the caller kills it.
        if ($reason !== DrainsStreamedChild::CLOSED) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$reason, $captured[0], $captured[1], $elapsed];
    }

    public function testAStreamedChildIsReadToEofOnBothPipesWithEachStreamInOrder(): void
    {
        [$reason, $stdout, $stderr] = self::walkChild(
            "printf 'out-1'; printf 'err-1' >&2; printf 'out-2'; printf 'err-2' >&2",
        );

        self::assertSame(DrainsStreamedChild::CLOSED, $reason);
        self::assertSame('out-1out-2', $stdout);
        self::assertSame('err-1err-2', $stderr);
    }

    public function testAChildThatWritesNothingAndExitsClosesTheWalkAtOnce(): void
    {
        foreach (['exit 0', 'exit 3'] as $script) {
            [$reason, $stdout, $stderr, $elapsed] = self::walkChild($script);

            self::assertSame(DrainsStreamedChild::CLOSED, $reason, $script);
            self::assertSame('', $stdout, $script);
            self::assertSame('', $stderr, $script);
            self::assertLessThan(5.0, $elapsed, "$script must not wait out the 10 second deadline");
        }
    }

    public function testAKeepGoingAnswerOfFalseStopsTheWalkBeforeTheChildFinishes(): void
    {
        [$reason, $stdout] = self::walkChild(
            'printf started; exec sleep 30',
            static fn (): bool => false,
        );

        self::assertSame(DrainsStreamedChild::STOPPED, $reason);
        self::assertSame('', $stdout, 'the walk checks $keepGoing before it reads anything');
    }
}
