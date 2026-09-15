<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Core\WorkerState;

/**
 * E716 (round 82): the pool owns its children's lifetime.
 *
 * Pins the three shapes the sweep flagged: stop() must reject unsettled
 * jobs loudly (never leave a promise awaiting a dead pool), closing a
 * synthetic worker state must not unregister fd 0 from the shared loop,
 * and a worker wedged inside a task must die within the bounded
 * grace→TERM→KILL ladder.
 */
final class WorkerPoolLifetimeTest extends TestCase
{
    private StreamSelectLoop $loop;

    /** @var list<string> temp artifacts to remove in tearDown */
    private array $scratch = [];

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->scratch = [];
    }

    public function testStopRejectsEveryUnsettledJobLoudly(): void
    {
        $pool = new WorkerPool($this->loop, 1);

        $rejections = [];
        $capture = static function (\Throwable $e) use (&$rejections): void {
            $rejections[] = $e->getMessage();
        };

        // No loop run between dispatch and stop: both jobs are still pending
        // (one in flight on the just-spawned child, one queued) and neither
        // can have been polled.
        $pool->dispatch('php_sapi_name')->otherwise($capture);
        $pool->dispatch('php_sapi_name')->otherwise($capture);

        $pool->stop();

        $this->assertCount(2, $rejections, 'every pending job must settle on stop()');
        foreach ($rejections as $index => $message) {
            $this->assertStringContainsString('stopped', $message);
            $this->assertStringContainsString('job ' . ($index + 1), $message);
        }
    }

    public function testClosingASyntheticWorkerLeavesTheStdinWatcherRegistered(): void
    {
        if (!is_resource(STDIN)) {
            $this->markTestSkipped('STDIN is not a resource on this runner');
        }

        $pool = new WorkerPool($this->loop, 1);
        $this->loop->addReadStream(STDIN, static function (): void {
        });

        // The regression is `removeReadStream((int) null)` → unset of key 0 —
        // the slot a TUI app's STDIN watcher lives on. Some harnesses start
        // PHP with fd 0 CLOSED (STDIN then reopens at a higher number), so
        // seed the fd-0 slot explicitly to keep this pin deterministic
        // everywhere. The loop is never run() again, so the stand-in value
        // only needs to occupy the array key.
        $readStreams = new ReflectionProperty(StreamSelectLoop::class, 'readStreams');
        $seeded = $readStreams->getValue($this->loop);
        $seeded[0] = $seeded[(int) STDIN];
        $readStreams->setValue($this->loop, $seeded);

        // The spawn-failure path hands closeWorker() a WorkerState whose
        // stderr is null; the unguarded call would silently cut the
        // application's fd-0 watcher out of the loop.
        $closeWorker = new ReflectionMethod(WorkerPool::class, 'closeWorker');
        $closeWorker->invoke($pool, new WorkerState(id: 7, process: null, stdin: null, stdout: null, stderr: null));

        $after = $readStreams->getValue($this->loop);
        $this->assertIsArray($after);
        $this->assertArrayHasKey((int) STDIN, $after, 'closeWorker(synthetic) must not unregister the live pipe');
        $this->assertArrayHasKey(0, $after, 'closeWorker(synthetic) must never unregister fd 0');

        $pool->stop();
    }

    public function testWedgedWorkerDiesWithinBoundedReapLadder(): void
    {
        $budget = 40.0;
        if (trim((string) @shell_exec('command -v timeout 2>/dev/null')) === '') {
            $this->markTestSkipped('timeout(1) is required to bound the probe child');
        }

        $script = $this->writeProbeChild();

        // The child SIGSTOPs its own pool worker, calls stop(), and prints
        // the elapsed wall time. With the ladder the SIGSTOPped child is
        // KILLed after grace+TERM and the run finishes ≈2.5s; without it,
        // proc_close blocks forever and the outer timeout -s KILL turns the
        // regression into exit 137, never a hung suite (E716 leash).
        $process = proc_open(
            ['timeout', '-s', 'KILL', (string) (int) $budget, PHP_BINARY, $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $budget + 5.0;
        while (microtime(true) < $deadline) {
            $stdout .= (string) @fread($pipes[1], 4096);
            $stderr .= (string) @fread($pipes[2], 4096);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(20_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit === 137 || $exit === -1) {
            $this->fail('the probe child outlived its wall-clock leash — stop() did not return: ' . $stderr);
        }
        $this->assertSame(0, $exit, 'probe child must exit cleanly: ' . $stderr);

        if (str_contains($stdout, 'SKIP')) {
            $this->markTestSkipped('probe child lacks posix_kill/SIGSTOP');
        }

        $this->assertMatchesRegularExpression('/ELAPSED \d+\.\d{3}/', $stdout);
        $elapsed = (float) explode(' ', trim(explode("\n", trim($stdout))[0]))[1];
        $this->assertGreaterThan(1.0, $elapsed, 'the grace window must actually be waited out');
        $this->assertLessThan(5.0, $elapsed, 'grace+TERM+KILL ladder must finish in bounded time');
    }

    private function writeProbeChild(): string
    {
        $autoload = escapeshellarg(dirname(__DIR__) . '/vendor/autoload.php');
        $code = <<<'PHP'
<?php
require <AUTOLOAD>;
if (!function_exists('posix_kill') || !defined('SIGSTOP')) {
    echo "SKIP\n";
    exit(0);
}
$loop = new React\EventLoop\StreamSelectLoop();
$pool = new SugarCraft\Core\WorkerPool($loop, 1);
$pool->dispatch('getmypid')->otherwise(static function (): void {});

$workers = (new ReflectionProperty(SugarCraft\Core\WorkerPool::class, 'workers'))->getValue($pool);
$worker = reset($workers);
$status = proc_get_status($worker->process);
posix_kill($status['pid'], SIGSTOP);

$started = microtime(true);
$pool->stop();
printf("ELAPSED %.3f\n", microtime(true) - $started);
exit($status['pid'] > 0 ? 0 : 1);
PHP;
        $code = str_replace('<AUTOLOAD>', $autoload, $code);

        $path = tempnam(sys_get_temp_dir(), 'q7_reap_probe_');
        $this->assertIsString($path);
        $this->scratch[] = $path;
        $this->assertNotFalse(file_put_contents($path, $code));

        return $path;
    }
}
