<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Serve\ServeStartup;

/**
 * candy-code-server-015: a `--detach` whose report never arrives used to fail
 * the start while the daemon kept running. The daemon now greets with its pid
 * first, so the waiting parent can name and stop what it gave up on.
 */
final class ServeDetachReapTest extends TestCase
{
    /** @return array{0: ?array<string, mixed>, 1: ?int} */
    private static function read(string $bytes, float $seconds): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$parent, $daemon] = $pair;
        fwrite($daemon, $bytes);
        fclose($daemon);

        $m = new \ReflectionMethod(ServeStartup::class, 'readReport');
        $m->setAccessible(true);
        $args = [$parent, $seconds, null];
        $report = $m->invokeArgs(null, [&$args[0], $args[1], &$args[2]]);
        fclose($parent);

        return [$report, $args[2]];
    }

    public function testAGreetingWithoutAReportStillYieldsTheDaemonPid(): void
    {
        [$report, $pid] = self::read(json_encode(['hello' => true, 'pid' => 4242]) . "\n", 0.5);

        self::assertNull($report, 'no report line arrived');
        self::assertSame(4242, $pid, 'the greeting names the daemon so the timeout can stop it');
    }

    public function testTheReportFollowingAGreetingIsReturnedAndThePidKept(): void
    {
        [$report, $pid] = self::read(
            json_encode(['hello' => true, 'pid' => 7]) . "\n" . json_encode(['ok' => true, 'url' => 'http://x', 'pid' => 7]) . "\n",
            0.5,
        );

        self::assertSame(true, $report['ok'] ?? null);
        self::assertSame(7, $pid);
    }

    public function testAReportAloneStillWorksWithNoGreeting(): void
    {
        [$report, $pid] = self::read(json_encode(['ok' => false, 'reason' => 'bind failed']) . "\n", 0.5);

        self::assertSame('bind failed', $report['reason'] ?? null);
        self::assertNull($pid);
    }

    public function testTheTimeoutRungsStopTheNamedDaemonAndSayWhichPid(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Serve/ServeStartup.php');

        self::assertStringContainsString("'hello' => true, 'pid' => \\getmypid()", $source);
        self::assertStringContainsString('self::stopUnreportedDaemon(', $source);
        self::assertStringContainsString("'cli.serve.no_report_stopped'", $source);
    }

    /**
     * The live kill path: a real orphan (reparented to init, exactly what a
     * detached daemon is) that never reports is TERMed by the ladder and is
     * gone afterwards — not a source grep.
     */
    public function testALiveOrphanedDaemonIsStoppedByTheTimeoutLadder(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-posix');
        }
        $spawn = proc_open(['sh', '-c', 'sleep 60 >/dev/null 2>&1 & echo $!'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($spawn);
        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($spawn);
        self::assertGreaterThan(1, $pid);
        self::assertTrue(posix_kill($pid, 0), 'fixture: the orphan is alive');

        try {
            $gone = (new \ReflectionMethod(ServeStartup::class, 'stopUnreportedDaemon'))->invoke(null, $pid);

            self::assertTrue($gone, 'TERM landed and the orphan is gone');
            self::assertFalse(@posix_kill($pid, 0), 'nothing is left holding the lock or the port');
        } finally {
            @posix_kill($pid, 9);
        }
    }

    public function testNothingAliveToStopIsNullSoTheMessageStaysTheHonestNoReport(): void
    {
        $m = new \ReflectionMethod(ServeStartup::class, 'stopUnreportedDaemon');

        self::assertNull($m->invoke(null, null), 'no greeting, no pid');
        self::assertNull($m->invoke(null, 2_000_000_000), 'a pid that is not running');
    }
}
