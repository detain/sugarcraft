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
     * The gate is an ADDITIONAL layer, not a replacement — but after the owner
     * ruling of 2026-10-06 the built-in guard-rail hooks stand down under
     * `bypass-permissions`: destructive-but-not-terminal Bash (`rm -rf
     * ./build`) now runs. What still refuses in bypass is the gate's own
     * step-0 breaker (`rm -rf /`), pinned as the pair below, and the
     * ProtectFiles policy floor (pinned in {@see ProtectFilesHookTest}).
     */
    public function testBypassRunsGuardrailedWorkWhileTheBreakerHolds(): void
    {
        $tool = $this->recordingTool('Bash');

        (new EngineBackend($this->toolThenAnswerProvider('Bash', ['command' => 'rm -rf ./build']), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->complete([Message::user('go')]);

        $this->assertSame(1, $tool->calls, 'the ConfirmRemove guard-rail is below bypass');

        $breaker = $this->recordingTool('Bash');

        (new EngineBackend($this->toolThenAnswerProvider('Bash', ['command' => 'rm -rf /']), 'test'))
            ->withTools([$breaker])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->complete([Message::user('go')]);

        $this->assertSame(0, $breaker->calls, 'the rm -rf / breaker is unswitchable');
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
     *
     * The bypass pair (owner ruling 2026-10-06): the same unanswered Ask — a
     * user Ask rule under a bypass session — answers itself ALLOW, because the
     * human already consented by switching the mode; there is no second
     * question left to ask them.
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

        $bypassed = $this->recordingTool();

        (new EngineBackend($this->toolThenAnswerProvider(), 'test'))
            ->withTools([$bypassed])
            ->withPermissionGate(new PermissionGate(
                PermissionMode::BypassPermissions,
                [new PermissionRule('Edit', PermissionAction::Ask)],
            ))
            ->complete([Message::user('go')]);

        $this->assertSame(1, $bypassed->calls, 'bypass answers an Ask rule without a UI');

        // And the arm for a HOOK's ask (Runtime's no-UI fail-closed): the
        // POLICY_ASK class is never waived by the ruling — but with nobody to
        // put the question to, bypass is itself the standing yes. In the TUI
        // an approver is attached and the question still reaches the human.
        $policy = $this->recordingTool('Write');

        (new EngineBackend($this->toolThenAnswerProvider('Write', ['file_path' => '.mcp.json', 'content' => '{}']), 'test'))
            ->withTools([$policy])
            ->withPermissionGate(new PermissionGate(PermissionMode::BypassPermissions))
            ->complete([Message::user('go')]);

        $this->assertSame(1, $policy->calls, 'an unanswered hook ask settles allow under bypass');
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

    // ---- F5 batch-spawn grant memo (Task only, turn-scoped) ----------------

    /**
     * The defect F5 cures: N Task spawns batched across a turn asked the human
     * N identical questions. One grant for `agent|mode` now answers the whole
     * batch — and every later spawn of that same agent under the same mode
     * inside the same turn.
     */
    public function testSecondTaskSpawnForTheSameAgentDoesNotReaskTheApprover(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = [];

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$asked): bool {
                $asked[] = [$call->name(), (string) ($call->arguments()['agent'] ?? '')];

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $tool->calls, 'both spawns ran');
        $this->assertCount(1, $asked, 'the approver was asked exactly once for the pair');
        $this->assertSame(['Task', 'coder'], $asked[0]);
    }

    /**
     * The memo key carries the agent name: consent to run `coder` is not
     * consent to run anyone else.
     */
    public function testADifferentAgentStillGetsItsOwnQuestion(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = [];

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'reviewer', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call) use (&$asked): bool {
                $asked[] = (string) ($call->arguments()['agent'] ?? '');

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $tool->calls);
        $this->assertSame(['coder', 'reviewer'], $asked, 'each agent keeps its own question');
    }

    /**
     * Grants memoise, refusals never do — a rejected spawn must not quietly
     * authorise the retries the model will attempt after seeing the refusal.
     */
    public function testARefusalIsNotMemoised(): void
    {
        $tool = $this->recordingTool('Task');
        $answers = [false, true];
        $asked = 0;

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function (ToolCall $call) use (&$asked, &$answers): bool {
                $asked++;

                return array_shift($answers) === true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $asked, 'the second spawn re-asked after a refusal');
        $this->assertSame(1, $tool->calls, 'only the approved spawn executed');
    }

    /**
     * Explicit-Deny precedence survives the memo untouched: a Deny is not an
     * ASK, so it never reaches the branch that consults the map — one deny rule
     * keeps silencing every spawn in the batch with zero prompts.
     */
    public function testAnExplicitDenyRuleBlocksEverySpawnWithZeroPrompts(): void
    {
        $tool = $this->recordingTool('Task');

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(
                PermissionMode::Default,
                [new PermissionRule('Task', PermissionAction::Deny)],
            ))
            ->withPermissionApprover(static function (): bool {
                self::fail('a denied spawn must never reach the approver');
            })
            ->complete([Message::user('go')]);

        $this->assertSame(0, $tool->calls);
    }

    /**
     * Task is the whole scope: two identical Edit asks still prompt twice,
     * because no batch doctrine tells Edit callers to fan out and a memo there
     * would turn one approval into standing permission for unrelated calls.
     */
    public function testNonTaskToolsAreNeverMemoised(): void
    {
        $tool = $this->recordingTool('Edit');
        $asked = 0;

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Edit', ['file_path' => 'a.txt'])],
            [new ToolCall('call_2', 'Edit', ['file_path' => 'a.txt'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked++;

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $asked);
        $this->assertSame(2, $tool->calls);
    }

    /**
     * A spawn whose agent cannot be read (missing or blank — the alias-only
     * shape resolves inside TaskTool, not in the gate) never enters the memo:
     * the null-key path keeps today's byte-identical prompt, whatever the tool
     * layer then does with the call.
     */
    public function testATaskCallWithoutAReadableAgentNeverMemoises(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = 0;

        (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['prompt' => 'a'])],
            [new ToolCall('call_2', 'Task', ['agent' => '  ', 'prompt' => 'b'])],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked++;

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(2, $asked, 'neither call was eligible for the memo');
    }

    /**
     * One question per BATCH, not just per turn: a provider that fans out
     * several Task calls in a single message has every member gated
     * sequentially in the parent during phase 1, so the first approval covers
     * the rest of the group.
     */
    public function testOneApprovalCoversAFannedOutBatchOfTaskCalls(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = 0;

        (new EngineBackend($this->scriptedProvider([
            [
                new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a']),
                new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'b']),
                new ToolCall('call_3', 'Task', ['agent' => 'coder', 'prompt' => 'c']),
            ],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked++;

                return true;
            })
            ->complete([Message::user('go')]);

        $this->assertSame(1, $asked, 'the batch cost one prompt');
        $this->assertSame(3, $tool->calls, 'and all three spawns ran');
    }

    /**
     * Steps beyond this turn's memo boundary are a new turn, hence a new
     * Runtime, hence no inherited consent — the same fresh question as today.
     * (Pinned via the map's instance scope rather than a two-turn driver: the
     * second complete() call builds a new Runtime internally.)
     */
    public function testTheMemoDoesNotCrossTurns(): void
    {
        $tool = $this->recordingTool('Task');
        $asked = 0;
        $approver = static function () use (&$asked): bool {
            $asked++;

            return true;
        };

        // One batch + one clean answer per turn: the scripted provider keeps
        // handing out [asks, answers] so the second complete() re-plays the
        // very same spawn the first turn approved.
        $engine = (new EngineBackend($this->scriptedProvider([
            [new ToolCall('call_1', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [],
            [new ToolCall('call_2', 'Task', ['agent' => 'coder', 'prompt' => 'a'])],
            [],
        ]), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover($approver);

        $engine->complete([Message::user('go')]);
        $engine->complete([Message::user('again')]);

        $this->assertSame(2, $asked, 'each turn re-asks; consent was given to a plan, not forever');
        $this->assertSame(2, $tool->calls);
    }

    /**
     * @param array<int, array<int, ToolCall>> $steps one tool-call batch per provider round-trip
     */
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
