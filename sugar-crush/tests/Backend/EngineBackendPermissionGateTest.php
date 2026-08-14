<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * crush_code.md Phase 1 item 2: {@see PermissionGate} had exactly one consumer
 * (the sub-agent path) while the main loop got only the built-in hooks. These
 * exercise the {@see EngineBackend::withPermissionGate()} seam end-to-end
 * through the real {@see \SugarCraft\Crush\Runtime}, so a "the gate is wired"
 * claim is backed by a tool that actually did or did not run.
 *
 * @see EngineBackend::withPermissionGate()
 * @see \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook
 */
final class EngineBackendPermissionGateTest extends TestCase
{
    public function testWithoutAGateTheToolStillRuns(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->complete([Message::user('go')]);

        $this->assertSame(1, $tool->calls, 'baseline: no gate attached, nothing blocks the call');
    }

    public function testADenyingGateStopsTheToolFromRunning(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            // Plan mode denies every write tool, and Edit is one.
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    public function testADenyingGateReportsTheRefusalToTheModel(): void
    {
        $finished = [];

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$this->recordingTool()])
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')], null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            });

        $this->assertCount(1, $finished);
        $this->assertStringContainsString("mode 'plan'", $finished[0]->result->content());
    }

    public function testAnExplicitAllowRuleReachesTheEngine(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(
                PermissionMode::Plan,
                [new PermissionRule('Edit', PermissionAction::Allow)],
            ))
            ->complete([Message::user('go')]);

        $this->assertSame(1, $tool->calls, 'an explicit Allow rule outranks the mode');
    }

    /**
     * The gate is an ADDITIONAL layer, not a replacement: a mode as permissive
     * as BypassPermissions must not talk the built-in hooks out of a refusal.
     */
    public function testTheBuiltInHooksSurviveAPermissiveGate(): void
    {
        $tool = $this->recordingTool('Bash');

        (new EngineBackend($this->toolThenAnswerProvider('Bash', ['command' => 'rm -rf ./build']), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    /**
     * An explicitly attached {@see HookManager} keeps its own hooks AND gains
     * the gate — the two seams compose rather than displacing each other.
     */
    public function testAnExplicitHookManagerComposesWithTheGate(): void
    {
        $tool = $this->recordingTool();
        $manager = new HookManager($registry = new HookRegistry());
        $manager->registerBuiltIns();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->withHooks($manager)
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
        $this->assertNotNull($registry->get('PreToolUse', 'protect-files'));
        $this->assertNotNull($registry->get('PreToolUse', 'permission-gate'));
    }

    /**
     * With no approver attached, an ASK fails CLOSED — {@see \SugarCraft\Crush\Runtime::settleAsk()}'s
     * pre-existing contract, now reachable from the gate.
     */
    public function testAnAskingGateFailsClosedWithNoApprover(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            // Default mode asks about anything that is not read-only.
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    /**
     * The other half of the same seam: this is what makes an Ask-producing
     * permission mode distinguishable from a deny-everything one. Before
     * {@see EngineBackend::withPermissionApprover()}, this class passed a
     * hard-coded `null` for Runtime's approver parameter.
     */
    public function testAnAttachedApproverSettlesTheAskAndLetsTheToolRun(): void
    {
        $tool = $this->recordingTool();
        $asked = [];

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$asked): bool {
                $asked[] = [$call->name(), $ask->message];

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(1, $tool->calls);
        $this->assertCount(1, $asked);
        $this->assertSame('Edit', $asked[0][0]);
        $this->assertStringContainsString('Edit', $asked[0][1]);
    }

    public function testAnApproverThatRefusesKeepsTheToolFromRunning(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static fn(): bool => false)
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    /**
     * Only a literal `true` grants. Every {@see \SugarCraft\Crush\Permissions\PermissionReply}
     * case is a truthy object, so an approver returning one must NOT be read
     * as permission by accident.
     */
    public function testATruthyNonTrueApproverReturnIsNotPermission(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            /** @phpstan-ignore-next-line deliberately the wrong return type */
            ->withPermissionApprover(static fn(): mixed => 'yes please')
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    public function testTheGateSurvivesEveryOtherWithCall(): void
    {
        $tool = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->withTools([$tool])
            ->withMaxSteps(4)
            ->withRoot(sys_get_temp_dir())
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls, 'withPermissionGate() must not be dropped by later with*() calls');
    }

    /**
     * `->withoutHooks()->withPermissionGate()` is a coherent request for
     * gate-only guarding and must not silently resurrect the built-ins.
     */
    public function testWithoutHooksThenAGateGuardsWithTheGateAlone(): void
    {
        $tool = $this->recordingTool('Bash');

        (new EngineBackend($this->toolThenAnswerProvider('Bash', ['command' => 'rm -rf ./build']), 'test'))
            ->withTools([$tool])
            ->withoutHooks()
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->complete([Message::user('go')]);

        $this->assertSame(
            1,
            $tool->calls,
            'ConfirmRemoveHook would have blocked this; withoutHooks() opted out of it',
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function toolThenAnswerProvider(string $tool = 'Edit', array $args = ['file_path' => 'a.txt']): ProviderInterface
    {
        return new class ($tool, $args) implements ProviderInterface {
            public int $calls = 0;

            /** @param array<string, mixed> $args */
            public function __construct(private string $tool, private array $args) {}

            public function name(): string { return 'test'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new CompleteResponse(content: 'working', toolCalls: [new ToolCall('call_1', $this->tool, $this->args)])
                    : new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function recordingTool(string $name = 'Edit'): Tool
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

                return new ToolResult(toolCallId: 'call_1', content: 'ran');
            }
        };
    }
}
