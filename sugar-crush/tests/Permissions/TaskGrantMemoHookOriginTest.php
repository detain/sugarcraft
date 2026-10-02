<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit F-P7: the per-turn Task grant memo (F5) is keyed `agent|mode` and used
 * to short-circuit ANY ask, whichever hook raised it. A user PreToolUse script
 * that asks about Task prompts mentioning "prod" fired once; every later Task
 * to the same agent in that turn — with a different prompt — was approved
 * without asking.
 *
 * The memo now stands in for the user only when the permission gate was the
 * SOLE asker, which {@see HookRegistry::executeHooks()} stamps on the ASK
 * ({@see HookResult::$askedBy}) from its own record of who asked.
 *
 * @see \SugarCraft\Crush\Runtime::taskGrantMemoKey()
 */
final class TaskGrantMemoHookOriginTest extends TestCase
{
    /**
     * The audit's test, with a real {@see ScriptHook} that exits 3 (ASK) on
     * every Task, chained with the gate: two Task calls to the same agent,
     * different prompts — the approver must be asked twice.
     */
    public function testAUserScriptHookAskIsPutForEveryTaskCall(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = [];

        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new ScriptHook('confirm-task', HookEvent::PreToolUse, 'Task', 'exit 3', 'confirm every spawn'));

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'tidy the README'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'migrate the prod database'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withHooks($hooks)
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$asked): bool {
                $asked[] = (string) ($call->arguments()['prompt'] ?? '');

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $tool->calls, 'both spawns ran once approved');
        $this->assertSame(
            ['tidy the README', 'migrate the prod database'],
            $asked,
            "the user hook's question for the second prompt was silenced by the first approval",
        );
    }

    /**
     * The audit's scenario exactly: the user hook asks only about prompts
     * mentioning "prod". The gate asks about the first spawn (the gate alone),
     * which is memoisable; the second spawn's question comes from the user
     * hook as well, so the memo must not answer it.
     */
    public function testAGateGrantDoesNotAnswerALaterUserHookAsk(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = [];

        $hooks = new HookManager(new HookRegistry());
        $hooks->register($this->askingHook('confirm-prod', static fn(HookContext $c): bool =>
            str_contains((string) ($c->toolArgs['prompt'] ?? ''), 'prod')));

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'tidy the README'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'migrate the prod database'])],
            [new ToolCall('call_3', 'Task', ['agent' => 'coder', 'prompt' => 'write the changelog'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withHooks($hooks)
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$asked): bool {
                $asked[] = (string) ($call->arguments()['prompt'] ?? '');

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(3, $tool->calls);
        $this->assertSame(
            ['tidy the README', 'migrate the prod database'],
            $asked,
            'the prod spawn must be asked about; the third (gate-only again) is answered by the memo',
        );
    }

    /**
     * A refusal of the user hook's question refuses that call only, and the
     * memo the gate-only first call earned is not consumed by it.
     */
    public function testRefusingTheUserHookAskRefusesThatCallOnly(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = 0;

        $hooks = new HookManager(new HookRegistry());
        $hooks->register($this->askingHook('confirm-prod', static fn(HookContext $c): bool =>
            str_contains((string) ($c->toolArgs['prompt'] ?? ''), 'prod')));

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'tidy'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'drop prod'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withHooks($hooks)
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call) use (&$asked): bool {
                ++$asked;

                return !str_contains((string) ($call->arguments()['prompt'] ?? ''), 'prod');
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $asked);
        $this->assertSame(1, $tool->calls, 'the refused prod spawn must not have run');
    }

    /** The F5 control: with the gate as the only asker the batch memo still holds. */
    public function testTheGateAloneIsStillMemoised(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = 0;

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function () use (&$asked): bool {
                ++$asked;

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $tool->calls);
        $this->assertSame(1, $asked);
    }

    // ---- the origin tag itself -------------------------------------------

    public function testTheRegistryStampsEveryHookThatAsked(): void
    {
        $registry = new HookRegistry();
        $registry->register(new PermissionGateHook(new PermissionGate(PermissionMode::Default)));
        $registry->register($this->askingHook('confirm-prod', static fn(): bool => true));

        $result = $registry->executeHooks(HookEvent::PreToolUse->value, $this->context());

        $this->assertTrue($result->isAsk());
        $this->assertSame([PermissionGateHook::NAME, 'confirm-prod'], $result->askedBy);
        $this->assertFalse($result->askedOnlyBy(PermissionGateHook::NAME));
    }

    public function testAGateOnlyAskIsAttributedToTheGate(): void
    {
        $registry = new HookRegistry();
        $registry->register(new PermissionGateHook(new PermissionGate(PermissionMode::Default)));
        $registry->register($this->askingHook('confirm-prod', static fn(): bool => false));

        $result = $registry->executeHooks(HookEvent::PreToolUse->value, $this->context());

        $this->assertTrue($result->askedOnlyBy(PermissionGateHook::NAME));
    }

    /**
     * The tag is the registry's record, not the hook's claim: a hook that
     * hands back an ASK already stamped with the gate's name is re-stamped
     * with its own.
     */
    public function testAHookCannotClaimToBeTheGate(): void
    {
        $forger = new class implements HookInterface {
            public function name(): string { return 'forger'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask('trust me')->withAskedBy([PermissionGateHook::NAME]);
            }
        };
        $registry = new HookRegistry();
        $registry->register($forger);

        $result = $registry->executeHooks(HookEvent::PreToolUse->value, $this->context());

        $this->assertSame(['forger'], $result->askedBy);
        $this->assertFalse($result->askedOnlyBy(PermissionGateHook::NAME));
    }

    /** An ASK nobody attributed (built by hand, not settled by a registry) is never the gate's. */
    public function testAnUnattributedAskIsNotTheGates(): void
    {
        $this->assertFalse(HookResult::ask('x')->askedOnlyBy(PermissionGateHook::NAME));
        $this->assertFalse(HookResult::allow()->withAskedBy([PermissionGateHook::NAME])->askedOnlyBy(PermissionGateHook::NAME));
        $this->assertSame(['a', 'b'], HookResult::ask('x')->withAskedBy(['a', 'b', 'a'])->askedBy);
        $this->assertSame(['g'], HookResult::ask('x')->withAskedBy(['g'])->withContextSet('note')->askedBy, 'withContextSet keeps the tag');
    }

    // ---- fixtures ----------------------------------------------------------

    private function context(): HookContext
    {
        return new HookContext(
            sessionId: 's',
            toolName: 'Task',
            toolArgs: ['agent' => 'coder', 'prompt' => 'p'],
            toolInput: '{"agent":"coder","prompt":"p"}',
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: '',
        );
    }

    /** @param \Closure(HookContext): bool $asks */
    private function askingHook(string $name, \Closure $asks): HookInterface
    {
        return new class ($name, $asks) implements HookInterface {
            public function __construct(private readonly string $hookName, private readonly \Closure $asks) {}
            public function name(): string { return $this->hookName; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return 'Task'; }

            public function execute(HookContext $context): HookResult
            {
                return ($this->asks)($context) ? HookResult::ask('Confirm: this spawn touches prod?') : HookResult::allow();
            }
        };
    }

    /** @param array<int, array<int, ToolCall>> $steps */
    private function scriptedProvider(array $steps): ProviderInterface
    {
        return new class ($steps) implements ProviderInterface {
            private int $index = 0;

            /** @param array<int, array<int, ToolCall>> $steps */
            public function __construct(private array $steps) {}

            public function name(): string { return 'test'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $step = $this->steps[$this->index++] ?? [];

                return $step === []
                    ? new CompleteResponse(content: 'done')
                    : new CompleteResponse(content: 'working', toolCalls: $step);
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function recordingTool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public int $calls = 0;

            public function __construct(private string $toolName) {}

            public function name(): string { return $this->toolName; }
            public function description(): string { return 'records that it ran'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }
}
