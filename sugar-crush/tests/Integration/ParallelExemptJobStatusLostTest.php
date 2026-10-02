<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit C1: a parallel group made only of {@see ExemptFromParallelDeadline}
 * jobs must still settle when `pcntl_waitpid()` cannot hand back a job's
 * status because something else already took it.
 *
 * `SIGCHLD = SIG_IGN` is the real-world way that happens: the kernel then
 * auto-reaps every exited child, and a later `waitpid($pid, WNOHANG)` answers
 * -1 (ECHILD), never the pid. {@see Runtime::executeConcurrently()} used to
 * settle a job only on `=== $pid`; the deadline kill settles a lost job
 * anyway, but it skips exempt jobs, so a group of delegated Tasks polled
 * forever.
 *
 * THE SCENARIO RUNS IN A FORKED CHILD, for two reasons. The signal
 * disposition must not leak into the PHPUnit process (other tests wait on
 * their own pids). And the pre-fix behaviour is an endless loop. PHPUnit's
 * own time limit is `pcntl_alarm()` in this process, and arming a second
 * alarm here would clobber it. So the parent waits on the child with its own
 * deadline and turns a hang into a failed assertion; the tracked-fork reaper
 * kills a wedged child in tearDown().
 */
final class ParallelExemptJobStatusLostTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    /** Far above the ~0.3s the fixed group takes; far below the suite's 60s per-test limit. */
    private const SCENARIO_BUDGET_SECONDS = 15.0;

    private string $resultFile = '';

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('pcntl_signal')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        if ($this->resultFile !== '') {
            @unlink($this->resultFile);
        }

        parent::tearDown();
    }

    public function testAnAllExemptGroupSettlesWhenTheKernelAlreadyReapedItsChildren(): void
    {
        $this->resultFile = (string) tempnam(sys_get_temp_dir(), 'c1-status-lost-');
        $resultFile = $this->resultFile;

        $child = $this->forkTracked();
        if ($child === -1) {
            $this->markTestSkipped('fork failed');
        }

        if ($child === 0) {
            $payload = ['error' => 'scenario did not finish'];
            try {
                pcntl_signal(SIGCHLD, SIG_IGN);

                $provider = $this->createStub(ProviderInterface::class);
                $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true, 1);
                $app = App::new($provider, 'gpt-4')->withTools([self::exemptTool('task_a'), self::exemptTool('task_b')]);

                $method = new \ReflectionMethod($runtime, 'executeToolCalls');
                /** @var list<ToolResultMessage> $results */
                $results = array_values(iterator_to_array($method->invoke(
                    $runtime,
                    [new ToolCall('call_a', 'task_a', []), new ToolCall('call_b', 'task_b', [])],
                    $app,
                    null,
                    null,
                    null,
                )));

                $payload = ['results' => array_map(
                    static fn(ToolResultMessage $r): array => ['error' => $r->isError(), 'content' => $r->content()],
                    $results,
                )];
            } catch (\Throwable $e) {
                $payload = ['error' => $e::class . ': ' . $e->getMessage()];
            }

            file_put_contents($resultFile, json_encode($payload));
            ForkedChild::exitNow(0);
        }

        $deadline = microtime(true) + self::SCENARIO_BUDGET_SECONDS;
        $status = 0;
        $finished = false;
        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($child, $status, WNOHANG) !== 0) {
                $finished = true;
                break;
            }
            usleep(20_000);
        }

        $this->assertTrue($finished, sprintf(
            'an all-exempt parallel group whose job statuses were already reaped (SIGCHLD=SIG_IGN) never settled within %.0fs',
            self::SCENARIO_BUDGET_SECONDS,
        ));
        $this->forgetForkedChild($child);

        $decoded = json_decode((string) file_get_contents($resultFile), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded, (string) ($decoded['error'] ?? 'no results'));
        $this->assertSame([
            ['error' => false, 'content' => 'task_a done'],
            ['error' => false, 'content' => 'task_b done'],
        ], $decoded['results'], 'every job\'s real result arrives from its payload file, not a "produced no result" error');
    }

    private static function exemptTool(string $name): Tool
    {
        return new class ($name) implements Tool, ParallelSafe, ExemptFromParallelDeadline {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'answers after a short pause';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                usleep(100_000);

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }
}
