<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The escape hatches for {@see Runtime}'s concurrent tool dispatch, and the
 * fact that they REACH it.
 *
 * {@see Runtime} has carried `$parallelToolCalls`/`$parallelToolDeadlineSeconds`
 * since crush_code.md Phase 0 item 14, but the one production construction site
 * — {@see EngineBackend::complete()} — passed neither, so both were reachable
 * only from tests: a user hitting a bad interaction could not turn concurrency
 * off without editing source. Every assertion below is therefore paired: the
 * resolver decides the right thing, AND the decision is observable in how a
 * real turn dispatches.
 *
 * HOME is redirected to a sandbox for the whole class, same convention as
 * BootstrapUserConfigTest, so nothing here can read or write the real
 * ~/.sugar-crush/config.json.
 */
final class EngineBackendParallelConfigTest extends TestCase
{
    private string $dir;

    private string $originalHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_parallel_config_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/home', 0o700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->dir . '/home');

        foreach (['SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS', 'SUGARCRUSH_PARALLEL_TOOL_DEADLINE'] as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        $this->originalHome === '' ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // =========================================================================
    // On/off
    // =========================================================================

    public function testConcurrencyIsOnWhenNothingSaysOtherwise(): void
    {
        $this->assertTrue($this->enabled());
    }

    public function testTheEnvFlagTurnsConcurrencyOff(): void
    {
        putenv('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS=1');

        $this->assertFalse($this->enabled());
    }

    /**
     * Same semantics as the lib's other `SUGARCRUSH_DISABLE_*` flags: set, and
     * neither empty nor "0".
     */
    public function testAnEmptyOrZeroEnvFlagIsNotSet(): void
    {
        putenv('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS=0');
        $this->assertTrue($this->enabled());

        putenv('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS=');
        $this->assertTrue($this->enabled());
    }

    public function testThePersistedConfigKeyTurnsConcurrencyOff(): void
    {
        Bootstrap::writeUserConfig(['parallelToolCalls' => false]);

        $this->assertFalse($this->enabled());
    }

    /**
     * Only a literal `false` counts, so a typo (`"false"`, `0`) cannot switch
     * a feature off by accident.
     */
    public function testOnlyALiteralFalseInTheConfigDisables(): void
    {
        foreach ([true, 'false', 0, null] as $value) {
            Bootstrap::writeUserConfig(['parallelToolCalls' => $value]);
            $this->assertTrue($this->enabled(), var_export($value, true) . ' must not disable');
        }
    }

    // =========================================================================
    // Deadline
    // =========================================================================

    public function testTheDeadlineDefaultsToRuntimesOwn(): void
    {
        $this->assertSame(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, $this->deadline());
    }

    public function testTheDeadlineComesFromTheEnvVarThenTheConfig(): void
    {
        Bootstrap::writeUserConfig(['parallelToolDeadlineSeconds' => 45]);
        $this->assertSame(45, $this->deadline());

        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=12');
        $this->assertSame(12, $this->deadline(), 'the per-invocation override must win');
    }

    /**
     * An env var only outranks the persisted setting when it actually says
     * something. A rejected one used to jump straight to the hardcoded 90,
     * silently discarding a deliberately persisted value — the same trap the
     * on/off flag already avoids by treating a not-really-set var as unset.
     *
     * @dataProvider unusableEnvDeadlines
     */
    public function testAnUnusableEnvDeadlineFallsThroughToTheConfig(string $env): void
    {
        Bootstrap::writeUserConfig(['parallelToolDeadlineSeconds' => 45]);
        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=' . $env);

        $this->assertSame(45, $this->deadline());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableEnvDeadlines(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'past the turn ceiling' => ['200'];
    }

    /**
     * ...and with nothing persisted either, it is still the documented default
     * rather than anything derived from the nonsense.
     */
    public function testAnUnusableEnvDeadlineWithNoConfigIsStillTheDefault(): void
    {
        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=200');

        $this->assertSame(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, $this->deadline());
    }

    /**
     * The two sources are judged by identical rules. An env var has no type but
     * string, so honouring `45.7` there while rejecting a JSON `45.9` was a
     * judgement about where the value came from rather than about the value.
     */
    public function testAFractionalDeadlineIsTruncatedWhicheverSourceCarriesIt(): void
    {
        Bootstrap::writeUserConfig(['parallelToolDeadlineSeconds' => 45.9]);
        $this->assertSame(45, $this->deadline());

        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=12.7');
        $this->assertSame(12, $this->deadline());
    }

    /**
     * Truncation is not a way in past the ceiling: the bound is checked on the
     * number as written, and a fraction under 1s truncates to nothing usable.
     *
     * The config is handed in directly rather than persisted because INF/NAN
     * are exactly the floats `json_encode()` refuses to write — they can only
     * reach the resolver from an in-process caller, and casting a non-finite
     * float to int is undefined, so the guard has to hold for them anyway.
     */
    public function testAFractionalDeadlineStillHasToBeInRange(): void
    {
        foreach ([0.5, 119.9999, 120.4, 1e30, INF, -INF, NAN] as $value) {
            $this->assertSame(
                $value === 119.9999 ? 119 : Runtime::PARALLEL_TOOL_DEADLINE_SECONDS,
                $this->deadlineFrom(['parallelToolDeadlineSeconds' => $value]),
                var_export($value, true),
            );
        }
    }

    // =========================================================================
    // Resolving must not touch the disk beyond reading
    // =========================================================================

    /**
     * Resolving dispatch settings is a READ. It used to run through a
     * {@see Bootstrap::userConfigPath()} that created ~/.sugar-crush on the way
     * past — so a turn conjured the directory into being, twice, on a machine
     * whose user had never persisted anything.
     */
    public function testResolvingTheSettingsDoesNotCreateTheConfigDirectory(): void
    {
        $configDir = $this->dir . '/home/.sugar-crush';

        $this->assertTrue($this->enabled());
        $this->assertSame(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, $this->deadline());

        $this->assertDirectoryDoesNotExist($configDir);
    }

    /**
     * And a real turn does not either — the end of the same claim, past
     * {@see EngineBackend::complete()}'s own call sites rather than the
     * resolvers in isolation.
     */
    public function testARealTurnDoesNotCreateTheConfigDirectory(): void
    {
        $reply = EngineBackend::new($this->answeringProvider(), 'test')
            ->complete([Message::user('go')]);

        $this->assertSame('done', $reply->content);
        $this->assertDirectoryDoesNotExist($this->dir . '/home/.sugar-crush');
    }

    /**
     * The ceiling is not a preference. The group deadline is enforced inside
     * the forked completion child and no frame reaches the parent while a
     * group runs, so a group allowed past EngineBackend's 120s idle timeout
     * would have the whole turn SIGKILLed from above — losing every sibling's
     * result — instead of the one stuck call being reported as failed.
     *
     * @dataProvider nonsenseDeadlines
     */
    public function testNonsenseFallsBackToTheDocumentedDefault(mixed $value): void
    {
        Bootstrap::writeUserConfig(['parallelToolDeadlineSeconds' => $value]);

        $this->assertSame(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, $this->deadline());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonsenseDeadlines(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'at the turn ceiling' => [120];
        yield 'past the turn ceiling' => [600];
        yield 'not a number' => ['soon'];
        yield 'a bool' => [true];
        yield 'an array' => [[90]];
    }

    public function testTheLargestHonouredDeadlineIsOneSecondUnderTheTurnCeiling(): void
    {
        Bootstrap::writeUserConfig(['parallelToolDeadlineSeconds' => 119]);

        $this->assertSame(119, $this->deadline());
    }

    // =========================================================================
    // …and it reaches the Runtime
    // =========================================================================

    /**
     * The end-to-end claim: a real {@see EngineBackend::complete()} turn, two
     * parallel-safe calls, and a rendezvous witness that can only report 2 if
     * the two calls genuinely overlapped.
     */
    public function testARealTurnDispatchesConcurrentlyByDefault(): void
    {
        $this->requireFork();

        $this->assertSame(['saw=2', 'saw=2'], $this->runTwoCallTurn(wait: 3.0));
    }

    public function testTheEnvFlagReachesTheRuntimeAndSerializesARealTurn(): void
    {
        $this->requireFork();
        putenv('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS=1');

        // The serialized runs pay the wait in full, once, so it stays small.
        $this->assertSame(['saw=1', 'saw=2'], $this->runTwoCallTurn(wait: 0.25));
    }

    public function testThePersistedConfigKeyReachesTheRuntimeToo(): void
    {
        $this->requireFork();
        Bootstrap::writeUserConfig(['parallelToolCalls' => false]);

        $this->assertSame(['saw=1', 'saw=2'], $this->runTwoCallTurn(wait: 0.25));
    }

    /**
     * And the configured deadline is the one actually enforced — proved by the
     * number the timeout failure reports back to the model, which is
     * interpolated from whatever {@see Runtime} was handed.
     */
    public function testTheConfiguredDeadlineIsTheOneEnforced(): void
    {
        $this->requireFork();

        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=1');

        $started = microtime(true);
        $contents = $this->runTwoCallTurn(wait: 0.25, hangSecond: true);
        $elapsed = microtime(true) - $started;

        $this->assertStringContainsString('killed at the 1s parallel-tool deadline', $contents[1]);
        $this->assertLessThan(20.0, $elapsed, 'the configured deadline, not the hung tool, must bound the group');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    private function enabled(): bool
    {
        return (new \ReflectionMethod(EngineBackend::class, 'parallelToolCallsEnabled'))->invoke(null);
    }

    private function deadline(): int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'parallelToolDeadlineSeconds'))->invoke(null);
    }

    /**
     * The same resolver, handed a config instead of reading one — the shape
     * {@see EngineBackend::complete()} uses so a turn reads the file once.
     *
     * @param array<string, mixed> $config
     */
    private function deadlineFrom(array $config): int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'parallelToolDeadlineSeconds'))->invoke(null, $config);
    }

    /**
     * A provider that answers without asking for a tool: one round of the
     * agentic loop, no fork, no concurrency — just enough of a real turn to
     * watch what it touches on disk.
     */
    private function answeringProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string { return 'answering'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                return new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    /**
     * One turn, two calls against the rendezvous tool, returning what each
     * call reported. Read off {@see ToolFinished} because `complete()` hands
     * back only the last assistant message.
     *
     * @return list<string>
     */
    private function runTwoCallTurn(float $wait, bool $hangSecond = false): array
    {
        $backend = EngineBackend::new($this->twoCallProvider($wait, $hangSecond), 'test')
            ->withTools([$this->rendezvousTool()]);

        $contents = [];
        $backend->complete([Message::user('go')], null, static function ($event) use (&$contents): void {
            if ($event instanceof ToolFinished) {
                $contents[] = $event->result->content();
            }
        });

        return $contents;
    }

    private function twoCallProvider(float $wait, bool $hangSecond): ProviderInterface
    {
        return new class ($wait, $hangSecond) implements ProviderInterface {
            public int $calls = 0;

            public function __construct(private float $wait, private bool $hangSecond) {}

            public function name(): string { return 'two-call'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;

                if ($this->calls > 1) {
                    return new CompleteResponse(content: 'done');
                }

                return new CompleteResponse(content: 'working', toolCalls: [
                    new ToolCall('call_a', 'rendezvous', ['marker' => 'a', 'wait' => $this->wait, 'hang' => false]),
                    new ToolCall('call_b', 'rendezvous', ['marker' => 'b', 'wait' => $this->wait, 'hang' => $this->hangSecond]),
                ]);
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    /**
     * Drops a marker and reports how many it saw. Sequential dispatch runs the
     * first call before the second has left a marker, so it can only ever
     * report `1,2`; genuine overlap reports `2,2`. The wait is bounded either
     * way, so a regression fails rather than hangs.
     */
    private function rendezvousTool(): Tool
    {
        return new class ($this->dir . '/markers') implements Tool, ParallelSafe {
            public function __construct(private string $markers) {}

            public function name(): string { return 'rendezvous'; }
            public function description(): string { return 'rendezvous witness'; }
            public function inputSchema(): array { return ['type' => 'object']; }
            public function isParallelSafe(): bool { return true; }

            public function execute(array $args): ToolResult
            {
                if ($args['hang'] ?? false) {
                    sleep(20);
                }

                if (!is_dir($this->markers)) {
                    @mkdir($this->markers, 0o777, true);
                }
                file_put_contents($this->markers . '/' . $args['marker'], '1');

                $deadline = microtime(true) + (float) ($args['wait'] ?? 1.0);
                $seen = 0;
                do {
                    $seen = max($seen, count(glob($this->markers . '/*') ?: []));
                    if ($seen >= 2) {
                        break;
                    }
                    usleep(1_000);
                } while (microtime(true) < $deadline);

                return new ToolResult(toolCallId: '', content: 'saw=' . $seen);
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
