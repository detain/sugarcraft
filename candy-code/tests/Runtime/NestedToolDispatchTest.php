<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-TOOL-6.d: a Script's nested calls ride the SAME permission gate and the
 * SAME Pre/PostToolUse hook chain as an in-process tool call — there is no
 * second validator, and the bridge cannot smuggle a call past a rule that
 * would stop the same tool typed by the model. These pins drive the real
 * Runtime (its private executeToolCalls) with a fake Script tool that only
 * exists to hold the binding the Runtime installs, so every assertion below
 * travels through gate(), the hook chain, executeGuarded() and settle().
 *
 * Truth notes (probed, not believed): a DENY rule or a plan/dont-ask mode
 * refusal surfaces under DenialKind::Hook with the `Hook denied: Permission
 * mode` prefix (gate() seeds the kind there and only the ASK arms refine
 * it); an approver answering a question is the only path to
 * DenialKind::Refused (`Permission denied:`), and an ask with no approver
 * attached is DenialKind::Unanswered (`Permission required:`).
 */
final class NestedToolDispatchTest extends TestCase
{
    public function testAnInnerAskReachesTheSameApproverAndAGrantRunsIt(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, []);

        $asks = [];
        $results = $this->drive($runtime, $app, null, static function (ToolCall $call) use (&$asks): bool {
            $asks[] = $call->name() . '#' . $call->id();

            return true;
        });

        // The outer script itself is a write-class call, so the approver
        // sees it FIRST — the fd-3 side is never asked for less than the
        // direct call would be, it is asked for exactly the same.
        $this->assertSame(['Script#outer', 'Edit#outer~1'], $asks);
        $this->assertTrue($bag->leaf->executed);
        $this->assertSame($bag->leaf->output, $bag->rows[0]['content']);
        $this->assertFalse($bag->rows[0]['isError']);
        $this->assertNull($bag->rows[0]['denial']);
        $this->assertFalse($results[0]->isError());
    }

    public function testADenyRuleRefusesTheInnerCallAndCarriesItsKind(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness(
            $bag,
            PermissionMode::Default,
            [new PermissionRule('Edit', PermissionAction::Deny)],
        );

        $this->drive($runtime, $app, null, static fn (ToolCall $call): bool => $call->name() !== 'Edit');

        // A rule that would stop `Edit` typed by the model stops it through
        // the bridge with the same verdict — this is the CRITICAL class of
        // this family: the nested path must not fork a softer validator.
        $this->assertFalse($bag->leaf->executed);
        $this->assertCount(1, $bag->rows);
        $this->assertTrue($bag->rows[0]['isError']);
        $this->assertSame('hook', $bag->rows[0]['denial']);
        $this->assertStringStartsWith('Hook denied: Permission mode', $bag->rows[0]['content']);
        $this->assertStringContainsString('does not allow Edit', $bag->rows[0]['content']);
    }

    public function testAnApproverAnsweringNoRefusesWithTheRefusedKind(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, []);

        $this->drive($runtime, $app, null, static fn (ToolCall $call): bool => $call->name() === 'Script');

        $this->assertFalse($bag->leaf->executed);
        $this->assertSame('refused', $bag->rows[0]['denial']);
        $this->assertStringStartsWith('Permission denied:', $bag->rows[0]['content']);
    }

    public function testAPreToolUseDenyHookBlocksTheInnerCall(): void
    {
        $bag = NestedStandIns::bag();
        $word = 'inner-gate-' . uniqid((string) getmypid(), true);
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, [], [
            new class ($word) implements HookInterface {
                public function __construct(private readonly string $word)
                {
                }

                public function name(): string
                {
                    return 'pre-deny-probe';
                }

                public function event(): HookEvent
                {
                    return HookEvent::PreToolUse;
                }

                public function matcher(): string
                {
                    return '^Edit$';
                }

                public function execute(HookContext $context): HookResult
                {
                    return HookResult::deny('blocked for the probe: ' . $this->word);
                }
            },
        ]);

        $this->drive($runtime, $app, null, static fn (): bool => true);

        // The hook chain judges nested calls exactly as it judges direct
        // ones; the sub-agent grant hook story depends on this arm holding.
        $this->assertFalse($bag->leaf->executed);
        $this->assertSame('hook', $bag->rows[0]['denial']);
        $this->assertStringContainsString($word, $bag->rows[0]['content']);
        $this->assertStringContainsString('blocked for the probe', $bag->rows[0]['content']);
    }

    public function testAPostToolUseWithholdingReplacesTheInnerOutput(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, [], [
            new class implements HookInterface {
                public function name(): string
                {
                    return 'post-withhold-probe';
                }

                public function event(): HookEvent
                {
                    return HookEvent::PostToolUse;
                }

                public function matcher(): string
                {
                    return '^Edit$';
                }

                public function execute(HookContext $context): HookResult
                {
                    return HookResult::deny('the transcript must not carry this body');
                }
            },
        ]);

        $this->drive($runtime, $app, null, static fn (): bool => true);

        // Withholding is settle()'s job and the bridge inherits it: the
        // child script never sees the raw output, only the notice — the
        // same contract a direct call's model turn gets.
        $this->assertTrue($bag->leaf->executed);
        $this->assertStringNotContainsString($bag->leaf->output, $bag->rows[0]['content']);
        $this->assertStringContainsString('[output withheld by', $bag->rows[0]['content']);
        $this->assertStringContainsString('the transcript must not carry this body', $bag->rows[0]['content']);
    }

    public function testRebindingAndProxyTargetsAreRefusedStructurally(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, []);

        $targets = [['name' => 'Script2'], ['name' => 'Task'], ['name' => 'Prune'], ['name' => 'mcp_call'], ['name' => 'mcp__git__push'], ['name' => 'NoSuchTool']];

        $this->drive($runtime, $app, null, static fn (): bool => true, $targets);

        // Nesting a dispatcher (or a Task/Workflow-style engine-delegator,
        // a ledger mutator, or the MCP bridges) behind a script would let a
        // script re-root its own permission story; the leaf rule refuses
        // them all BEFORE the gate, so none of them ever executed.
        $this->assertCount(6, $bag->rows);
        foreach (array_slice($bag->rows, 0, 5) as $row) {
            $this->assertTrue($row['isError']);
            $this->assertStringContainsString('cannot be called from a script', $row['content']);
        }
        $this->assertStringContainsString('Error: tool not found: NoSuchTool', $bag->rows[5]['content']);
        $this->assertFalse($bag->nested->executed);
        $this->assertFalse($bag->task->executed);
        $this->assertFalse($bag->prune->executed);
        $this->assertFalse($bag->mcpCall->executed);
        $this->assertFalse($bag->mcpBridge->executed);
    }

    public function testInnerIdsCarryTheOuterPrefixAndEmitNoLifecycleFrames(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, []);

        $events = [];
        $this->drive($runtime, $app, static function (object $event) use (&$events): void {
            $events[] = $event;
        }, static fn (): bool => true, [['name' => 'Edit'], ['name' => 'Edit']]);

        // The model's transcript frames belong to the ONE Script row; the
        // per-inner ToolStarted/ToolFinished would double-bill and desync
        // the id correlation the UI keys off. The ids exist for AUDIT
        // (deny rows, relay) but ride silently to the parent frame.
        $this->assertSame(['outer~1', 'outer~2'], array_column($bag->rows, 'id'));
        $started = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolStarted));
        $finished = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolFinished));
        $this->assertCount(1, $started);
        $this->assertCount(1, $finished);
        $this->assertSame('outer', $started[0]->toolCallId);
        $this->assertSame('outer', $finished[0]->toolCallId);
        foreach (array_merge($started, $finished) as $frame) {
            $this->assertStringNotContainsString('~', $frame->toolCallId);
        }
    }

    public function testTheCatalogClosureShipsLeafRowsWithSignatures(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Default, []);

        $this->drive($runtime, $app, null, static fn (): bool => true);

        $this->assertTrue($bag->catalogCalled);
        $rows = $bag->catalogRows;
        $names = array_column($rows, 'name');
        // Exactly the targets the dispatch arm refuses must be absent from
        // the list the child sees — the prelude's tools() cannot offer what
        // tool() would refuse.
        $this->assertNotContains('Script', $names);
        $this->assertNotContains('Script2', $names);
        $this->assertNotContains('Task', $names);
        $this->assertNotContains('Prune', $names);
        $this->assertNotContains('mcp_call', $names);
        $this->assertNotContains('mcp__git__push', $names);
        $this->assertContains('Edit', $names);
        $edit = null;
        foreach ($rows as $row) {
            if ($row['name'] === 'Edit') {
                $edit = $row['signature'];
            }
        }
        $this->assertSame('Edit(file_path: string, hint' . chr(63) . ': string)', $edit);
    }

    public function testPlanModeRefusesTheOuterScriptBeforeAnythingBinds(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::Plan, []);

        $results = $this->drive($runtime, $app, null, static fn (): bool => true);

        // Design: plan and dont-ask deny the OUTER call (Script is
        // Write-class), so no inner call ever happens and the child-side
        // surface is dark — the binding itself is pure wiring the Runtime
        // installs BEFORE judging, and an unwired script is an error, so
        // what must be absent after a refusal is any execution and any
        // catalog pull, not the closure handoff.
        $this->assertTrue($results[0]->isError());
        $this->assertStringStartsWith('Hook denied: Permission mode', $results[0]->content());
        $this->assertStringContainsString('does not allow Script', $results[0]->content());
        $this->assertFalse($bag->catalogCalled);
        $this->assertSame([], $bag->rows);
    }

    public function testDontAskModeRefusesTheOuterScriptToo(): void
    {
        $bag = NestedStandIns::bag();
        [$runtime, $app] = NestedStandIns::harness($bag, PermissionMode::DontAsk, []);

        $results = $this->drive($runtime, $app, null, static fn (): bool => true);

        $this->assertTrue($results[0]->isError());
        $this->assertStringStartsWith('Hook denied: Permission mode', $results[0]->content());
        $this->assertFalse($bag->leaf->executed);
    }

    /**
     * @param list<array{name: string}> $targets
     *
     * @return list<\SugarCraft\Code\Messages\ToolResultMessage>
     */
    private function drive(Runtime $runtime, App $app, ?callable $onEvent, ?callable $onPermissionRequest, array $targets = [['name' => 'Edit']]): array
    {
        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $method->setAccessible(true);

        return iterator_to_array($method->invokeArgs($runtime, [
            [new ToolCall('outer', 'Script', ['targets' => $targets])],
            $app,
            $onEvent,
            $onPermissionRequest,
        ]), false);
    }
}
