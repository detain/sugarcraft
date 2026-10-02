<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit 15a A11: a tool call whose arguments the provider could not decode
 * reached {@see Runtime} as an ordinary call with `[]`, so the tool RAN with
 * no arguments and the model read that tool's "missing parameter" error - a
 * problem that was never the parameters'. Runtime now answers such a call
 * ({@see ToolCall::argumentsError()} set) with an error result naming the
 * JSON problem, on both the sequential and the concurrent arm, and never
 * reaches the hook chain or the tool.
 */
final class RuntimeMalformedToolArgumentsTest extends TestCase
{
    private const TRUNCATED = '{"path": "a';

    private string $markerDir;

    protected function setUp(): void
    {
        $this->markerDir = sys_get_temp_dir() . '/sc_a11_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->markerDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->markerDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->markerDir . '/' . $entry);
            }
        }
        @rmdir($this->markerDir);

        parent::tearDown();
    }

    /**
     * The audit's scenario end to end: a real CustomProvider stream carrying
     * `{"path": "a`, through Runtime::run().
     */
    public function testATruncatedStreamedPayloadBecomesAnErrorResultAndTheToolNeverRuns(): void
    {
        $body = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
            'index' => 0,
            'id' => 'call_trunc',
            'type' => 'function',
            'function' => ['name' => 'spy_read', 'arguments' => self::TRUNCATED],
        ]]], 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . "data: [DONE]\n\n";

        $client = $this->createMock(Client::class);
        $client->method('post')->willReturn(new Response(200, [], $body));
        $custom = new CustomProvider('custom', 'https://api.example.com', 'm', null, $client, true, true);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('a11-stub');
        $provider->method('supportsStreaming')->willReturn(true);
        $provider->method('completeStream')->willReturnCallback(
            static fn (CompleteRequest $request): \Generator => $custom->completeStream($request),
        );

        $spy = $this->spyTool('spy_read', parallel: false);
        [$results, $events] = $this->runTurn($provider, [$spy]);

        $toolResults = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof ToolResultMessage));
        $this->assertCount(1, $toolResults);
        $this->assertSame('call_trunc', $toolResults[0]->toolCallId());
        $this->assertTrue($toolResults[0]->isError());
        $this->assertStringContainsString('not valid JSON', $toolResults[0]->content());
        $this->assertStringContainsString(self::TRUNCATED, $toolResults[0]->content());
        $this->assertSame([], $this->executions(), 'the tool must not run with the [] placeholder');

        $this->assertCount(2, $events, 'one ToolStarted and one ToolFinished, like "Tool not found"');
        $this->assertInstanceOf(ToolStarted::class, $events[0]);
        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertTrue($events[1]->result->isError());
        $this->assertSame($toolResults[0]->content(), $events[1]->result->content());
    }

    public function testTheSequentialArmSkipsThePreToolUseChainForAMalformedCall(): void
    {
        $registry = new HookRegistry();
        $seen = new \ArrayObject();
        $registry->register(new class($seen) implements HookInterface {
            public function __construct(private \ArrayObject $seen) {}
            public function name(): string { return 'a11-recorder'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult
            {
                $this->seen->append($context->toolName);

                return HookResult::allow();
            }
        });

        $call = (new ToolCall('call_bad', 'spy_read', []))->withArgumentsError('arguments were not valid JSON (Syntax error): ' . self::TRUNCATED);
        $messages = $this->dispatch([$call], [$this->spyTool('spy_read', parallel: false)], $registry);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isError());
        $this->assertStringContainsString('The tool was not run because its arguments were not valid JSON', $messages[0]->content());
        $this->assertSame([], $this->executions());
        $this->assertCount(0, $seen, 'no argument map exists for a hook to judge');
    }

    /**
     * The concurrent arm gates every member up front; a malformed member must
     * settle there as an error while its well-formed sibling still runs, and
     * results keep provider order. Executions are counted through marker
     * files because a forked child cannot bump an in-memory counter.
     */
    public function testTheConcurrentArmRefusesTheMalformedMemberAndRunsItsSibling(): void
    {
        $tool = $this->spyTool('spy_par', parallel: true);
        $calls = [
            (new ToolCall('call_par_bad', 'spy_par', []))->withArgumentsError('arguments were not valid JSON (Syntax error): ' . self::TRUNCATED),
            new ToolCall('call_par_good', 'spy_par', ['path' => 'b.php']),
        ];

        $finished = [];
        $messages = $this->dispatch($calls, [$tool], null, static function (object $event) use (&$finished): void {
            if ($event instanceof ToolFinished) {
                $finished[$event->toolCallId] = $event;
            }
        });

        $this->assertSame(
            ['call_par_bad', 'call_par_good'],
            array_map(static fn (ToolResultMessage $m): string => $m->toolCallId(), $messages),
        );
        $this->assertTrue($messages[0]->isError());
        $this->assertStringContainsString('not valid JSON', $messages[0]->content());
        $this->assertFalse($messages[1]->isError());
        $this->assertSame('ran with b.php', $messages[1]->content());
        $this->assertSame(['b.php'], $this->executions(), 'only the well-formed sibling ran');
        $this->assertCount(2, $finished);
        $this->assertTrue($finished['call_par_bad']->result->isError());
    }

    /**
     * @param list<Tool> $tools
     * @return array{0: list<mixed>, 1: list<object>}
     */
    private function runTurn(ProviderInterface $provider, array $tools): array
    {
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $events = [];
        $results = iterator_to_array($runtime->run($app, static function (object $event) use (&$events): void {
            $events[] = $event;
        }), false);

        return [$results, $events];
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool> $tools
     * @return list<ToolResultMessage>
     */
    private function dispatch(array $calls, array $tools, ?HookRegistry $registry = null, ?\Closure $onEvent = null): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('a11-stub');
        $runtime = new Runtime($provider, new HookManager($registry ?? new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($runtime, $calls, $app, $onEvent), false));
    }

    /** @return list<string> the `path` argument of every execution, sorted */
    private function executions(): array
    {
        $paths = [];
        foreach (glob($this->markerDir . '/run-*') ?: [] as $file) {
            $paths[] = (string) file_get_contents($file);
        }
        sort($paths);

        return $paths;
    }

    private function spyTool(string $name, bool $parallel): Tool
    {
        $spy = new class($name, $this->markerDir) implements Tool {
            public function __construct(private string $toolName, private string $markerDir) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'records every execution';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                $path = (string) ($args['path'] ?? '(none)');
                file_put_contents($this->markerDir . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4)), $path);

                return new ToolResult(toolCallId: $this->toolName, content: "ran with {$path}");
            }
        };

        if (!$parallel) {
            return $spy;
        }

        return new class($spy) implements Tool, ParallelSafe {
            public function __construct(private Tool $inner) {}

            public function name(): string
            {
                return $this->inner->name();
            }

            public function description(): string
            {
                return $this->inner->description();
            }

            public function inputSchema(): array
            {
                return $this->inner->inputSchema();
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function execute(array $args): ToolResult
            {
                return $this->inner->execute($args);
            }
        };
    }
}
