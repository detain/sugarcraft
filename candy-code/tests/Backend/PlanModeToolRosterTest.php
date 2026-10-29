<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ToolDeclaration;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tools\BuiltIn\PlanExitTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * C-SEC-7.c: while the session's gate is in `plan` mode the advertised tool
 * roster is the tools that gate does NOT refuse by declaration
 * ({@see PermissionGate::refuses()}, consulted inside
 * {@see EngineBackend::turnTools()}), so the model can no longer be offered
 * what it can never run. `Write`, `Bash` and `PlanExit` stay whatever the
 * declaration says — plan mode's own contract is that a `.md` write into
 * `.candy-code/plans/` and a read-only `Bash` may run, and leaving the mode
 * is the plan's approval door. Every other mode keeps advertising the full
 * list: the drop is the Plan verdict's, not a general policy.
 *
 * The pair to {@see EngineBackendPermissionGateTest}, which pins dispatch;
 * this file pins the offer. A fabricated call for a dropped tool now answers
 * "Tool not found" from a dispatch that never saw it offered — pinned here
 * end-to-end through the real Runtime so the advertised list and the
 * dispatchable list provably stay the same list.
 *
 * @see EngineBackend::turnTools()
 * @see \SugarCraft\Code\Permissions\PermissionGate::refuses()
 */
final class PlanModeToolRosterTest extends TestCase
{
    /**
     * The fake roster every list leg runs on. Plain names, no arguments: the
     * drop filter sees a {@see ToolDeclaration} only, so a recording tool
     * under the real name behaves exactly like the built-in for the roster.
     */
    private const ROSTER_NAMES = ['Read', 'Glob', 'Grep', 'Edit', 'Write', 'Bash', 'Task', 'ApplyPatch', 'WebFetch', 'TodoWrite', 'mcp__fake__ping'];

    /**
     * What the plan roster must be, name for name: read-only tools offered
     * (the gate allows them), Edit/Task/ApplyPatch/mcp__* dropped (the gate
     * refuses them), the keep trio offered, and the real `PlanExitTool`
     * surviving its {@see \SugarCraft\Code\Tools\DelegatesToEngine} binding
     * untouched.
     */
    private const PLAN_EXPECTED = ['Read', 'Glob', 'Grep', 'Write', 'Bash', 'WebFetch', 'TodoWrite', 'PlanExit'];

    public function testThePlanRosterIsTheToolsTheGateDoesNotRefuseByDeclaration(): void
    {
        $names = $this->advertisedUnder(new PermissionGate(PermissionMode::Plan));

        $this->assertSame(self::PLAN_EXPECTED, $names);
        $this->assertNotContains('Edit', $names);
        $this->assertNotContains('ApplyPatch', $names);
    }

    /**
     * The contract as a law instead of a table: for every name on the fake
     * roster (and `PlanExit`), advertised ⇔ the gate's own declaration
     * verdict says it may run — or it is one of the three the mode's write
     * promise keeps. Anything that makes offer and enforcement disagree on
     * either side reddens here, not in a hand-copied expectation.
     */
    public function testTheAdvertisedRosterAgreesWithTheGateDeclarationVerdictNameForName(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);
        $names = $this->advertisedUnder($gate);
        $kept = ['Write', 'Bash', 'PlanExit'];

        foreach ([...self::ROSTER_NAMES, 'PlanExit'] as $name) {
            $this->assertSame(
                in_array($name, $kept, true) || !$gate->refuses(new ToolDeclaration($name)),
                in_array($name, $names, true),
                "roster and declaration verdict disagree on {$name}",
            );
        }
    }

    /**
     * Mode-scoping: `Default`, `AcceptEdits`, `DontAsk` and
     * `BypassPermissions` all still advertise the full list — even `DontAsk`,
     * whose declaration verdict does refuse `Edit`, keeps the old contract,
     * because the drop is Plan's verdict alone.
     */
    public function testEveryOtherModeStillAdvertisesTheFullList(): void
    {
        $modes = ['default' => PermissionMode::Default, 'accept-edits' => PermissionMode::AcceptEdits, 'dont-ask' => PermissionMode::DontAsk, 'bypass' => PermissionMode::BypassPermissions];

        foreach ($modes as $label => $mode) {
            $names = $this->advertisedUnder(new PermissionGate($mode));
            $expected = [...self::ROSTER_NAMES, 'PlanExit'];

            $this->assertSame($expected, $names, "the full list must survive in {$label} mode");
        }
    }

    public function testWithoutAGateNothingIsDropped(): void
    {
        $names = array_map(
            static fn (Tool $tool): string => $tool->name(),
            $this->backend(null)->promptTools(),
        );

        $this->assertSame([...self::ROSTER_NAMES, 'PlanExit'], $names);
    }

    /**
     * The keep trio wins against a `Deny` rule: hiding `Write` from a plan
     * turn would make the mode unable to write the plan it exists to
     * produce. Such a rule still refuses the call at dispatch — what it can
     * no longer do in plan mode is silence the tool by riding the drop door.
     */
    public function testTheKeepListWinsAgainstADenyRule(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan, [new PermissionRule('Write', PermissionAction::Deny)]);
        $this->assertTrue($gate->refuses(new ToolDeclaration('Write')), 'the rule does refuse the declaration on its own');

        $names = $this->advertisedUnder($gate);
        $this->assertContains('Write', $names);
    }

    /**
     * The accepted risk of the same door: a `Deny <Tool>` rule rides the
     * declaration verdict, so outside the keep trio it HIDES the tool in
     * plan mode rather than merely refusing it. Documented in
     * docs/PERMISSIONS.md beside the flipped sentence.
     */
    public function testADenyRuleHidesItsToolUnderPlan(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan, [new PermissionRule('TodoWrite', PermissionAction::Deny)]);

        $names = $this->advertisedUnder($gate);
        $expected = array_values(array_filter(self::PLAN_EXPECTED, static fn (string $n): bool => $n !== 'TodoWrite'));

        $this->assertSame($expected, $names);
    }

    /**
     * The drop consults the gate, not a hardcoded blocklist: the explicit
     * `Allow` rule outranks the mode exactly as it does at dispatch, so the
     * tool reappears in the advertised roster.
     */
    public function testAnAllowRuleRestoresAToolUnderPlan(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan, [new PermissionRule('Edit', PermissionAction::Allow)]);

        $names = $this->advertisedUnder($gate);
        $this->assertContains('Edit', $names);
    }

    /**
     * The other side of mode-scoping, through the rule door: a `Deny` rule
     * under `Default` refuses the declaration — {@see PermissionGate} says
     * so — yet the tool stays advertised, because only Plan runs the drop.
     */
    public function testDenyUnderDefaultDoesNotHideTheTool(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [new PermissionRule('TodoWrite', PermissionAction::Deny)]);
        $this->assertTrue($gate->refuses(new ToolDeclaration('TodoWrite')));

        $names = $this->advertisedUnder($gate);
        $this->assertContains('TodoWrite', $names, 'the drop is the Plan verdict alone');
    }

    /**
     * End-to-end through the real Runtime: a fabricated call for a dropped
     * tool answers "Tool not found" — the dispatch never saw it offered —
     * and the tool body is never reached.
     */
    public function testAFabricatedCallForADroppedToolAnswersToolNotFound(): void
    {
        $tool = $this->recordingTool();
        $provider = $this->toolThenAnswerProvider();
        $finished = [];

        (new EngineBackend($provider, 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')], null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            });

        $this->assertSame(0, $tool->calls, 'the dropped tool was never dispatched');
        $this->assertCount(1, $finished);
        $this->assertStringContainsString('Tool not found', $finished[0]->result->content());
        $this->assertSame(2, $provider->calls, 'the answer round still follows the failed call');
    }

    /**
     * The keep-list pair of the leg above: `Write` IS advertised under Plan,
     * so a fabricated call for a non-plan target reaches the gate at
     * dispatch and is refused as a mode decision — the refusal wording the
     * model used to read one denied call at a time still answers for the
     * tools that stay offered.
     */
    public function testAKeptWriteStillHitsTheGateWhenTheTargetIsNotAPlanFile(): void
    {
        $tool = $this->recordingTool('Write');
        $finished = [];

        (new EngineBackend($this->toolThenAnswerProvider('Write', ['file_path' => 'notes.txt']), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')], null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished[] = $event;
                }
            });

        $this->assertSame(0, $tool->calls);
        $this->assertCount(1, $finished);
        $this->assertStringContainsString("mode 'plan'", $finished[0]->result->content());
    }

    /**
     * And the promise that made `Write` a keep name is real, not decorative:
     * the one write of plan mode — a `.md` directly in the plans directory —
     * runs under the very gate that dropped `Edit` around it.
     */
    public function testTheKeptWriteCanActuallyWriteThePlanFile(): void
    {
        $tool = $this->recordingTool('Write');

        (new EngineBackend($this->toolThenAnswerProvider('Write', ['file_path' => '.candy-code/plans/plan.md']), 'test'))
            ->withTools([$tool])
            ->withPermissionGate(new PermissionGate(PermissionMode::Plan))
            ->complete([Message::user('go')]);

        $this->assertSame(1, $tool->calls, 'the plan file is the one write plan mode allows');
    }

    /** @return list<string> */
    private function advertisedUnder(PermissionGate $gate): array
    {
        return array_map(
            static fn (Tool $tool): string => $tool->name(),
            $this->backend($gate)->promptTools(),
        );
    }

    private function backend(?PermissionGate $gate): EngineBackend
    {
        $backend = (new EngineBackend($this->answerOnlyProvider(), 'test'))
            ->withTools($this->rosterTools());

        return $gate === null ? $backend : $backend->withPermissionGate($gate);
    }

    /** @return list<Tool> */
    private function rosterTools(): array
    {
        $tools = array_map(
            fn (string $name): Tool => $this->recordingTool($name),
            self::ROSTER_NAMES,
        );
        // The real approval-door tool, through the DelegatesToEngine binding
        // every other turn's roster carries it through; a fake named
        // PlanExit would pin the keep arm but not the binding.
        $tools[] = PlanExitTool::new('');

        return $tools;
    }

    private function answerOnlyProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string { return 'test'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsDocuments(): bool { return false; }
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
            public function supportsDocuments(): bool { return false; }
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
}
