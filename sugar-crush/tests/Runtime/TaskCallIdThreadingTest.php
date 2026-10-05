<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\TakesToolCallId;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * W9 integration (W9-e handoff 1): a `Task` call's sub-agent beats carry the
 * id of the call that started them.
 *
 * {@see TaskTool::execute()} took its call id from `$args['id']`, and the
 * engine's tool dispatch never put one there — so every `subagent.*` beat on
 * the engine path went out with `parentCallId: ""`, and neither the TUI's live
 * lines nor the web's agent tree could hang a run under its `Task` row. The
 * Runtime now hands the call id to a tool that declares
 * {@see TakesToolCallId}, and only to such a tool.
 */
final class TaskCallIdThreadingTest extends TestCase
{
    private ?string $storeDir = null;

    protected function tearDown(): void
    {
        if ($this->storeDir !== null) {
            foreach (glob($this->storeDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storeDir);
        }
    }

    public function testATaskCallsSubAgentBeatsNameTheCallThatStartedThem(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_callid_' . bin2hex(random_bytes(6));
        $tools = [self::echoTool('Read')];
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: $tools, toolUniverse: $tools);
        $manager->register(RosterAgent::named('coder', ['Read'], maxTurns: 4));
        $beats = [];
        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))->withEngine(
            EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'all done')], contextWindow: 1_000_000), 'm')->withTools($tools),
            subAgentEmitter: static function (SubAgentActivity $activity) use (&$beats): void {
                $beats[] = $activity;
            },
        );

        $messages = $this->dispatch([new ToolCall('call_task_7', 'Task', [
            'description' => 'Audit candy-core',
            'prompt' => 'Audit candy-core and report the findings',
            'agent' => 'coder',
        ])], [$task]);

        self::assertCount(1, $messages);
        self::assertNotSame([], $beats, 'the run must report at least its start and finish');
        foreach ($beats as $beat) {
            self::assertSame('call_task_7', $beat->parentCallId, sprintf('a %s beat lost its Task call', $beat->op));
        }
    }

    public function testTheCallIdOverwritesAnyTheModelSentAndReachesOnlyAToolThatAsksForIt(): void
    {
        $messages = $this->dispatch([
            new ToolCall('call_1', 'wants', ['id' => 'forged']),
            new ToolCall('call_2', 'plain', ['x' => 1]),
        ], [self::idEchoTool('wants', true), self::idEchoTool('plain', false)]);

        self::assertSame('id=call_1', $messages[0]->content(), 'the engine\'s id, never the model\'s');
        self::assertSame('keys=x', $messages[1]->content(), 'a tool that does not opt in sees exactly the model\'s arguments');
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool> $tools
     * @return list<\SugarCraft\Crush\Messages\ToolResultMessage>
     */
    private function dispatch(array $calls, array $tools): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('call-id-stub');
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($runtime, $calls, $app, null, null, null), false));
    }

    private static function echoTool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private readonly string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'stub';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'ok');
            }
        };
    }

    private static function idEchoTool(string $name, bool $optIn): Tool
    {
        $echo = static fn (array $args): string => array_key_exists('id', $args)
            ? 'id=' . (string) $args['id']
            : 'keys=' . implode(',', array_keys($args));

        return $optIn
            ? new class ($name, $echo) implements Tool, TakesToolCallId {
                public function __construct(private readonly string $toolName, private readonly \Closure $echo)
                {
                }

                public function name(): string
                {
                    return $this->toolName;
                }

                public function description(): string
                {
                    return 'stub';
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object'];
                }

                public function execute(array $args): ToolResult
                {
                    return new ToolResult(toolCallId: '', content: ($this->echo)($args));
                }
            }
            : new class ($name, $echo) implements Tool {
                public function __construct(private readonly string $toolName, private readonly \Closure $echo)
                {
                }

                public function name(): string
                {
                    return $this->toolName;
                }

                public function description(): string
                {
                    return 'stub';
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object'];
                }

                public function execute(array $args): ToolResult
                {
                    return new ToolResult(toolCallId: '', content: ($this->echo)($args));
                }
            };
    }
}
