<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tools\BuiltIn\PlanExitTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 5.7-2: `PlanExit` puts the written plan to the user through the
 * turn's approver and, on approval, names the mode the session leaves plan
 * mode for.
 */
final class PlanExitToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/planexit-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/' . PermissionGate::PLANS_DIR, 0o777, true);
        file_put_contents($this->root . '/' . PermissionGate::PLANS_DIR . '/retry.md', "# Retry backoff\n\n1. Add jitter\n2. Cap at 30 s\n3. Test it\n4. Ship\n5. Watch\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function testItIsACatalogedNoAskToolThePlanGateAllows(): void
    {
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(PlanExitTool::NAME));
        self::assertSame(
            PermissionDecision::Allow,
            (new PermissionGate(PermissionMode::Plan))->evaluate(new \SugarCraft\Crush\ToolCall(PlanExitTool::NAME, ['plan_path' => 'x.md'])),
        );
        self::assertInstanceOf(\SugarCraft\Crush\Tools\DelegatesToEngine::class, PlanExitTool::new($this->root), 'withheld from sub-agents');
    }

    public function testAnApprovalNamesTheModePlanWasEnteredFrom(): void
    {
        $seen = null;
        $tool = $this->inMode((new PermissionGate(PermissionMode::AcceptEdits))->withMode(PermissionMode::Plan))
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$seen): ApprovalVerdict {
                $seen = [$call, $ask];

                return ApprovalVerdict::once();
            });

        $result = $tool->execute(['id' => 'call_3', 'plan_path' => '.sugar-crush/plans/retry.md', 'summary' => "Back off\nwith jitter"]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringStartsWith(PlanExitTool::APPROVED . ' plan mode ends when this turn ends, and the session switches to `accept-edits`.', $result->content());

        $switch = PlanExitTool::approval(PlanExitTool::NAME, $result);
        self::assertNotNull($switch);
        self::assertSame(PermissionMode::AcceptEdits, $switch->mode);

        self::assertNotNull($seen);
        [$call, $ask] = $seen;
        self::assertSame('call_3', $call->id());
        self::assertSame(PlanExitTool::NAME, $call->name());
        self::assertSame('.sugar-crush/plans/retry.md', $call->arguments()['plan_path']);
        self::assertSame('Back off with jitter', $call->arguments()['summary']);
        self::assertStringStartsWith('# Retry backoff', $call->arguments()['plan'], 'a client is handed the whole plan');
        self::assertTrue($ask->isAsk());

        $lines = explode("\n", $ask->message);
        self::assertStringContainsString('y = approve and switch to `accept-edits`', $lines[0], 'the keys lead the prompt');
        self::assertContains('Plan: .sugar-crush/plans/retry.md', $lines);
        self::assertContains('# Retry backoff', $lines);
        self::assertNotContains('5. Watch', $lines, 'the modal quotes only the start of the plan');
    }

    public function testASessionThatStartedInPlanGoesBackToDefault(): void
    {
        $result = $this->inMode(new PermissionGate(PermissionMode::Plan))
            ->withPermissionApprover(static fn (): bool => true)
            ->execute(['plan_path' => $this->root . '/.sugar-crush/plans/retry.md']);

        self::assertStringContainsString('switches to `default`', $result->content());
        self::assertSame(PermissionMode::Default, PlanExitTool::approval(PlanExitTool::NAME, $result)?->mode);
    }

    public function testWithTheModeUnknownAnApprovalIsTheToggleOutOfPlan(): void
    {
        $result = PlanExitTool::new($this->root)
            ->withPermissionApprover(static fn (): bool => true)
            ->execute(['plan_path' => '.sugar-crush/plans/retry.md']);

        self::assertStringStartsWith(PlanExitTool::APPROVED . ' plan mode ends when this turn ends.', $result->content());
        $switch = PlanExitTool::approval(PlanExitTool::NAME, $result);
        self::assertNotNull($switch);
        self::assertNull($switch->mode);
    }

    public function testFeedbackKeepsPlanningAndIsNoApproval(): void
    {
        $result = $this->planTool(ApprovalVerdict::rejectedByUser('split step 2'));

        self::assertFalse($result->isError());
        self::assertStringContainsString('did not approve the plan and said: split step 2', $result->content());
        self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, $result));

        $refused = $this->planTool(ApprovalVerdict::reject());
        self::assertFalse($refused->isError());
        self::assertStringContainsString('Plan mode stays on', $refused->content());
        self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, $refused));
    }

    public function testNobodyAnsweringOrAHarnessRefusalIsAnError(): void
    {
        $unanswered = $this->planTool(ApprovalVerdict::unanswered(ChildChannel::PARENT_GONE));
        self::assertTrue($unanswered->isError());
        self::assertStringContainsString(ChildChannel::PARENT_GONE, $unanswered->content());
        self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, $unanswered));

        $harness = $this->planTool(ApprovalVerdict::reject(ChildChannel::GRANDCHILD_REFUSAL));
        self::assertTrue($harness->isError());
        self::assertStringContainsString('could not be put to the user', $harness->content());
    }

    public function testOutsidePlanModeThereIsNothingToLeaveAndNobodyIsAsked(): void
    {
        $asked = false;
        $result = $this->inMode(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked = true;

                return true;
            })
            ->execute(['plan_path' => '.sugar-crush/plans/retry.md']);

        self::assertFalse($asked);
        self::assertTrue($result->isError());
        self::assertStringContainsString('Plan mode is not active', $result->content());
    }

    public function testWithoutAUserOrAnApproverPlanModeStaysOn(): void
    {
        $none = PlanExitTool::new($this->root)->execute(['plan_path' => '.sugar-crush/plans/retry.md']);
        self::assertTrue($none->isError());
        self::assertStringContainsString('no interactive user is attached', $none->content());

        $asked = false;
        $headless = PlanExitTool::new($this->root)
            ->withoutInteractiveUser('this is a test run')
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked = true;

                return true;
            })
            ->execute(['plan_path' => '.sugar-crush/plans/retry.md']);
        self::assertFalse($asked);
        self::assertTrue($headless->isError());
        self::assertStringContainsString('this is a test run', $headless->content());
        self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, $headless));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function notAPlan(): iterable
    {
        yield 'missing' => [null, 'must name the plan file'];
        yield 'absent file' => ['.sugar-crush/plans/nope.md', 'there is no plan file'];
        yield 'outside the plans dir' => ['notes.md', 'not a Markdown file directly in'];
        yield 'nested' => ['.sugar-crush/plans/deep/x.md', 'not a Markdown file directly in'];
        yield 'not markdown' => ['.sugar-crush/plans/retry.txt', 'not a Markdown file directly in'];
        yield 'empty' => ['.sugar-crush/plans/empty.md', 'is empty'];
    }

    /**
     * @dataProvider notAPlan
     */
    public function testOnlyAMarkdownFileDirectlyInThePlansDirIsAPlan(mixed $path, string $why): void
    {
        file_put_contents($this->root . '/notes.md', "# not a plan\n");
        file_put_contents($this->root . '/.sugar-crush/plans/retry.txt', "plan\n");
        file_put_contents($this->root . '/.sugar-crush/plans/empty.md', "  \n");
        mkdir($this->root . '/.sugar-crush/plans/deep');
        file_put_contents($this->root . '/.sugar-crush/plans/deep/x.md', "# deep\n");

        $asked = false;
        $result = PlanExitTool::new($this->root)
            ->withPermissionApprover(static function () use (&$asked): bool {
                $asked = true;

                return true;
            })
            ->execute(['plan_path' => $path]);

        self::assertFalse($asked);
        self::assertTrue($result->isError());
        self::assertStringContainsString($why, $result->content());
        self::assertStringContainsString('Plan mode stays on', $result->content());
    }

    public function testAPlansDirLinkedOutOfTheProjectHoldsNoPlan(): void
    {
        $elsewhere = $this->root . '-elsewhere';
        mkdir($elsewhere);
        file_put_contents($elsewhere . '/x.md', "# planted\n");
        exec('rm -rf ' . escapeshellarg($this->root . '/.sugar-crush/plans'));
        symlink($elsewhere, $this->root . '/.sugar-crush/plans');

        try {
            $result = PlanExitTool::new($this->root)
                ->withPermissionApprover(static fn (): bool => true)
                ->execute(['plan_path' => '.sugar-crush/plans/x.md']);

            self::assertTrue($result->isError(), $result->content());
            self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, $result));
        } finally {
            exec('rm -rf ' . escapeshellarg($elsewhere));
        }
    }

    public function testALongPlanIsCutForTheAskArguments(): void
    {
        file_put_contents($this->root . '/.sugar-crush/plans/big.md', str_repeat("step\n", PlanExitTool::MAX_PLAN_BYTES));
        $seen = null;
        PlanExitTool::new($this->root)
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$seen): bool {
                $seen = [$call, $ask];

                return true;
            })
            ->execute(['plan_path' => '.sugar-crush/plans/big.md']);

        self::assertNotNull($seen);
        self::assertLessThanOrEqual(PlanExitTool::MAX_PLAN_BYTES, \strlen($seen[0]->arguments()['plan']));
        self::assertStringContainsString('(long; the start is shown)', $seen[1]->message);
    }

    public function testApprovalReadsOnlyAnApprovedPlanExitResult(): void
    {
        $text = PlanExitTool::APPROVED . ' plan mode ends when this turn ends, and the session switches to `default`.';

        self::assertNull(PlanExitTool::approval('Bash', new ToolResult('', $text)), 'only PlanExit can approve a plan');
        self::assertNull(PlanExitTool::approval(PlanExitTool::NAME, new ToolResult('', $text, true)));
        self::assertNotNull(PlanExitTool::approval(PlanExitTool::NAME, new ToolResult('', $text)));
    }

    private function inMode(PermissionGate $gate): PlanExitTool
    {
        return PlanExitTool::new($this->root)->withEngine(EngineBackend::new(new EchoProvider(), 'echo')->withPermissionGate($gate));
    }

    private function planTool(ApprovalVerdict $verdict): ToolResult
    {
        return $this->inMode(new PermissionGate(PermissionMode::Plan))
            ->withPermissionApprover(static fn (): ApprovalVerdict => $verdict)
            ->execute(['plan_path' => '.sugar-crush/plans/retry.md']);
    }
}
