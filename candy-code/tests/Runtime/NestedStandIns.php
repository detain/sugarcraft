<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\DispatchesNestedTools;
use SugarCraft\Code\Tools\MutatesContextLedger;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Stand-in tools for {@see NestedToolDispatchTest}: a fake `Script` whose
 * only job is to hold the nested-dispatch binding the Runtime installs, a
 * write-class leaf named `Edit` (the gate classifies by NAME, so the real
 * roster reaches it), and one fake for every structural refusal target.
 * Every fake shares a single stdClass bag so the test can observe what ran,
 * under which call id, and what the gate handed back — the binding clone
 * the Runtime keeps running shares the same bag.
 *
 * Mirrors the C-TOOL-6.d design block; lives beside its test the way
 * DroppedStreamProvider lives beside DroppedStreamContinueTest.
 */
final class NestedStandIns
{
    /**
     * Fresh observation bag: rows in dispatch order, per-fake executed
     * flags, and the catalog the script actually pulled.
     */
    public static function bag(): \stdClass
    {
        $bag = new \stdClass();
        $bag->rows = [];
        $bag->bound = false;
        $bag->catalogCalled = false;
        $bag->catalogRows = [];
        $bag->leaf = new \stdClass();
        $bag->leaf->executed = false;
        $bag->leaf->output = 'leaf-ran-' . uniqid((string) getmypid(), true);
        $bag->leaf->seenIds = [];

        return $bag;
    }

    /**
     * Assemble the real Runtime + App over the bag.
     *
     * @param list<PermissionRule> $rules
     * @param list<object>         $extraHooks additional Pre/PostToolUse hooks
     *
     * @return array{Runtime, App}
     */
    public static function harness(\stdClass $bag, PermissionMode $mode, array $rules, array $extraHooks = []): array
    {
        $provider = new ScriptedProvider([]);
        $registry = new HookRegistry();
        $registry->register(new PermissionGateHook(new PermissionGate($mode, $rules)));
        foreach ($extraHooks as $hook) {
            $registry->register($hook);
        }
        $runtime = new Runtime($provider, new HookManager($registry));

        $script = new class ($bag) implements Tool, DispatchesNestedTools {
            public ?\Closure $call = null;
            public ?\Closure $catalog = null;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'Script';
            }

            public function description(): string
            {
                return 'fake script host';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code']];
            }

            public function withNestedDispatch(\Closure $call, \Closure $catalog): static
            {
                $clone = clone $this;
                $clone->call = $call;
                $clone->catalog = $catalog;
                $clone->bag->bound = true;

                return $clone;
            }

            public function execute(array $args): ToolResult
            {
                foreach ($args['targets'] ?? [] as $target) {
                    $result = ($this->call)((string) $target['name'], []);
                    $this->bag->rows[] = [
                        'name' => (string) $target['name'],
                        'id' => $result->toolCallId(),
                        'content' => $result->content(),
                        'isError' => $result->isError(),
                        'denial' => $result->denial()?->token(),
                    ];
                }
                $this->bag->catalogRows = ($this->catalog)();
                $this->bag->catalogCalled = true;

                return new ToolResult(toolCallId: '', content: 'script-done');
            }
        };

        $leaf = new class ($bag) implements Tool, TakesToolCallId {
            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'Edit';
            }

            public function description(): string
            {
                return 'fake write leaf';
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['file_path' => ['type' => 'string'], 'hint' => ['type' => 'string']],
                    'required' => ['file_path'],
                ];
            }

            public function execute(array $args): ToolResult
            {
                $this->bag->leaf->executed = true;
                $this->bag->leaf->seenIds[] = (string) ($args['id'] ?? '');

                return new ToolResult(toolCallId: '', content: $this->bag->leaf->output);
            }
        };

        $nested = new class ($bag) implements Tool, DispatchesNestedTools {
            public bool $executed = false;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'Script2';
            }

            public function description(): string
            {
                return 'fake dispatcher';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function withNestedDispatch(\Closure $call, \Closure $catalog): static
            {
                return clone $this;
            }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'nested-ran');
            }
        };

        $task = new class ($bag) implements Tool, DelegatesToEngine {
            public bool $executed = false;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'Task';
            }

            public function description(): string
            {
                return 'fake engine delegator';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function withEngine(
                EngineBackend $engine,
                ?\Closure $heartbeat = null,
                ?\Closure $subAgentEmitter = null,
            ): Tool {
                return clone $this;
            }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'task-ran');
            }
        };

        $prune = new class ($bag) implements Tool, MutatesContextLedger {
            public bool $executed = false;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'Prune';
            }

            public function description(): string
            {
                return 'fake ledger mutator';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function withLedger(\Closure $read, \Closure $apply): Tool
            {
                return clone $this;
            }

            public function withCompactionGate(\Closure $gate): Tool
            {
                return clone $this;
            }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'prune-ran');
            }
        };

        $mcpCall = new class ($bag) implements Tool {
            public bool $executed = false;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'mcp_call';
            }

            public function description(): string
            {
                return 'fake mcp dispatcher';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'mcp-call-ran');
            }
        };

        $mcpBridge = new class ($bag) implements Tool {
            public bool $executed = false;

            public function __construct(private readonly \stdClass $bag)
            {
            }

            public function name(): string
            {
                return 'mcp__git__push';
            }

            public function description(): string
            {
                return 'fake mcp bridge';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'mcp-bridge-ran');
            }
        };

        $bag->script = $script;
        $bag->nested = $nested;
        $bag->task = $task;
        $bag->prune = $prune;
        $bag->mcpCall = $mcpCall;
        $bag->mcpBridge = $mcpBridge;

        $app = App::new($provider, 'gpt-4')->withTools([$script, $leaf, $nested, $task, $prune, $mcpCall, $mcpBridge]);

        return [$runtime, $app];
    }
}
