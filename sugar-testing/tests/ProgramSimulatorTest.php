<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\View;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\TestResult;

final class ProgramSimulatorTest extends TestCase
{
    public function testForFactoryReturnsSimulator(): void
    {
        $model = new CounterModel();
        $program = new Program($model);

        $sim = ProgramSimulator::for($program);

        $this->assertInstanceOf(ProgramSimulator::class, $sim);
    }

    public function testForAcceptsBareModelAndWrapsItInProgram(): void
    {
        // A bare Model may be passed directly; it is wrapped in a default
        // Program internally, so run() drives its init/update/view.
        $sim = ProgramSimulator::for(new CounterModel(7));

        $this->assertInstanceOf(ProgramSimulator::class, $sim);

        $result = $sim->run();

        $this->assertInstanceOf(CounterModel::class, $result->model);
        $this->assertSame("Count: 7\n", $result->view);
    }

    public function testForBareModelProcessesQueuedMessages(): void
    {
        $sim = ProgramSimulator::for(new CounterModel(0));
        $sim->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ));

        $result = $sim->run();

        /** @var CounterModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(1, $finalModel->count());
    }

    public function testSendReturnsSelfForChaining(): void
    {
        $model = new CounterModel();
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ));

        $this->assertSame($sim, $result);
    }

    public function testRunReturnsTestResult(): void
    {
        $model = new CounterModel();
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->run();

        $this->assertInstanceOf(TestResult::class, $result);
        $this->assertInstanceOf(CounterModel::class, $result->model);
    }

    public function testRunProcessesQueuedMessages(): void
    {
        $model = new CounterModel(0);
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $sim->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ))->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ))->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ));

        $result = $sim->run();

        /** @var CounterModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(3, $finalModel->count());
    }

    public function testRunCapturesViewOutput(): void
    {
        $model = new CounterModel(42);
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->run();

        $this->assertSame("Count: 42\n", $result->view);
    }

    public function testRunCapturesDecrementMessages(): void
    {
        $model = new CounterModel(5);
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $sim->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '-',
            alt: false,
            ctrl: false,
            shift: false,
        ));

        $result = $sim->run();

        /** @var CounterModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(4, $finalModel->count());
    }

    public function testRunCapturesCmds(): void
    {
        $model = new CounterModel();
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        // A model that emits a cmd on init would populate cmds.
        $result = $sim->run();

        $this->assertIsArray($result->cmds);
    }

    public function testRunWithFakeCmdRunner(): void
    {
        $model = new CmdProducingCounterModel();
        $program = new Program($model);

        $capturedCmds = [];
        $sim = ProgramSimulator::for($program)->withFakeCmdRunner(
            function ($cmd) use (&$capturedCmds): ?\SugarCraft\Core\Msg {
                $capturedCmds[] = $cmd;
                return null;
            }
        );

        $sim->run();

        $this->assertCount(1, $capturedCmds);
        $this->assertInstanceOf(\Closure::class, $capturedCmds[0]);
    }

    public function testFakeCmdRunnerInjectedMsgReachesUpdate(): void
    {
        // CmdProducingCounterModel.init() returns a non-null cmd (that increments
        // count via side effect). The fakeRunner intercepts that cmd and returns
        // KeyMsg('+'). The injected msg should be threaded through applyMsg() to
        // drive update(), which creates a new model with count incremented.
        //
        // Flow: init cmd runs (count 0→1 via side effect), fakeRunner returns
        // KeyMsg('+'), applyMsg(KeyMsg('+')) calls update (count 1→2 via new model).
        $model = new CmdProducingCounterModel(0);
        $program = new Program($model);

        $sim = ProgramSimulator::for($program)->withFakeCmdRunner(
            static fn (): ?\SugarCraft\Core\Msg => new KeyMsg(
                type: KeyType::Char,
                rune: '+',
                alt: false,
                ctrl: false,
                shift: false,
            )
        );

        $result = $sim->run();

        /** @var CmdProducingCounterModel $finalModel */
        $finalModel = $result->model;
        // Count 0 → 1 (init closure side effect) → 2 (update from injected KeyMsg)
        $this->assertSame(2, $finalModel->count());
    }

    public function testInitCmdProducedMsgDrivesFirstUpdate(): void
    {
        // MsgProducingInitModel.init() returns a closure that produces KeyMsg('+').
        // That Msg should be threaded through update() as the first message,
        // causing the counter to increment via update() (not via the init closure itself).
        $model = new MsgProducingInitModel(0);
        $program = new Program($model);

        // Use default runner (no fake runner) so the init cmd executes.
        $sim = ProgramSimulator::for($program);

        $result = $sim->run();

        /** @var MsgProducingInitModel $finalModel */
        $finalModel = $result->model;
        // The init cmd produced KeyMsg('+'), which was fed to update(), incrementing count.
        $this->assertSame(1, $finalModel->count());
    }

    public function testCaptureOnlyModeDoesNotExecuteCmds(): void
    {
        // withRealCmdRunner(false) opts into capture-only mode: cmds are
        // collected for inspection but the closures never run, so the
        // side-effecting init cmd of CmdProducingCounterModel must leave
        // count untouched. (Renamed and given a real cmd by docs-fix-1023:
        // the old form used CounterModel, whose init() returns null, so it
        // proved nothing either way.)
        $model = new CmdProducingCounterModel(0);
        $program = new Program($model);

        $sim = ProgramSimulator::for($program)
            ->withRealCmdRunner(false)  // Capture-only mode
            ->withFakeCmdRunner(static fn (): ?\SugarCraft\Core\Msg => null);

        $result = $sim->run();

        /** @var CmdProducingCounterModel $finalModel */
        $finalModel = $result->model;
        // The side-effecting closure was never called in capture-only mode.
        $this->assertSame(0, $finalModel->count());
        // But the cmd was still captured for inspection.
        $this->assertCount(1, $result->cmds);
    }

    public function testCaptureCmdsIsTheDirectSpellingOfTheBoolFalseArm(): void
    {
        // captureCmds() must be behaviourally identical to
        // withRealCmdRunner(false): the side-effecting init cmd is captured,
        // never executed, and both spellings produce the same run.
        $boolArm = ProgramSimulator::for(new CmdProducingCounterModel(0))
            ->withRealCmdRunner(false)
            ->run();

        $direct = ProgramSimulator::for(new CmdProducingCounterModel(0))
            ->captureCmds()
            ->run();

        $this->assertSame(0, $direct->model->count());
        $this->assertCount(1, $direct->cmds);
        $this->assertSame($boolArm->view, $direct->view);
    }

    public function testWithCmdCaptureSubstitutesWithoutExecutingTheOriginalCmd(): void
    {
        // One-clone spelling of fake-runner + no-execute: the injected '+'
        // reaches update() (count 0 → 1) while the side-effecting init cmd
        // never runs. The execute-mode pair beside it shows the difference:
        // the real cmd runs first (0 → 1) and the same injected '+' then
        // takes it to 2.
        $inject = static fn (): ?Msg => new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        );

        $capture = ProgramSimulator::for(new CmdProducingCounterModel(0))
            ->withCmdCapture($inject)
            ->run();

        $this->assertSame(1, $capture->model->count());
        $this->assertCount(1, $capture->cmds);

        $executeArm = ProgramSimulator::for(new CmdProducingCounterModel(0))
            ->withRealCmdRunner(true)
            ->withFakeCmdRunner($inject)
            ->run();

        $this->assertSame(2, $executeArm->model->count());
    }

    public function testCaptureArmSettersRefuseAnAlreadySentHandle(): void
    {
        // captureCmds()/withCmdCapture() clone too — the configure-before-
        // send contract (testing-02) binds them as well.
        $sent = ProgramSimulator::for(new CounterModel())->addMsgs(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ));

        $calls = [
            'captureCmds' => static fn () => $sent->captureCmds(),
            'withCmdCapture' => static fn () => $sent->withCmdCapture(static fn (): ?Msg => null),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail($name . ' must honour configure-before-send');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('before the first addMsgs()', $e->getMessage());
            }
        }
    }

    public function testEmptyQueueRunReturnsResultWithInitialModel(): void
    {
        $model = new CounterModel(99);
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->run();

        /** @var CounterModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(99, $finalModel->count());
        $this->assertSame("Count: 99\n", $result->view);
    }

    public function testPumpSubscriptionsEnqueuesProducedMessages(): void
    {
        // SubscriptionProducingModel has a subscription that produces KeyMsg('*')
        // on each pump. The pumpSubscriptions() call should add this message to
        // the queue, and it should be processed in the run loop.
        $model = new SubscriptionProducingModel(0);
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->run();

        // The subscription produced KeyMsg('*'), which was processed via update(),
        // incrementing the counter via update() (not via the subscription itself).
        /** @var SubscriptionProducingModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(1, $finalModel->count());
    }

    public function testCmdLoopOverflowThrowsRuntimeException(): void
    {
        // InfiniteCmdLoopModel.update() always returns a cmd that produces
        // the same message, creating an infinite loop. The applyMsg() method
        // has overflow protection that throws after 10,000 cycles.
        //
        // To trigger the overflow, we need:
        // 1. A message in the queue to start applyMsg()
        // 2. Fake cmd runner that injects a message on each cmd execution
        $model = new InfiniteCmdLoopModel(0);
        $program = new Program($model);

        // Send any message to populate the queue and trigger applyMsg()
        $sim = ProgramSimulator::for($program)
            ->withFakeCmdRunner(
                static fn (): \SugarCraft\Core\Msg => new KeyMsg(
                    type: KeyType::Char,
                    rune: '+',
                    alt: false,
                    ctrl: false,
                    shift: false,
                )
            )
            ->addMsgs(new KeyMsg(
                type: KeyType::Char,
                rune: 'x', // Any initial message
                alt: false,
                ctrl: false,
                shift: false,
            ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeded 10000 cycles/');

        $sim->run();
    }

    public function testWithRealCmdRunnerTrueExecutesCmds(): void
    {
        // Explicit execute mode (the default). A fake runner alone does NOT
        // suppress execution — the real Cmd closure still runs for its side
        // effects (docs-fix-1023 cured this test: it used a null-init model
        // and asserted only assertIsArray, pinning nothing). With
        // CmdProducingCounterModel the side effect is observable: init's
        // closure increments count, so count 0 → 1 proves execution.
        $model = new CmdProducingCounterModel(0);
        $program = new Program($model);

        $sim = ProgramSimulator::for($program)
            ->withRealCmdRunner(true)
            ->withFakeCmdRunner(static fn ($cmd) => null);

        // Even with fakeRunner, executeCmds=true executes the cmd; fakeRunner
        // returns null so no extra message is injected.
        $result = $sim->run();

        /** @var CmdProducingCounterModel $finalModel */
        $finalModel = $result->model;
        $this->assertSame(1, $finalModel->count());
        $this->assertCount(1, $result->cmds);
    }

    public function testSelfReferencingTickRequestHitsThePumpIterationCeiling(): void
    {
        // The Batch/Sequence/TickRequest legs continue before the update-leg
        // cycle counter, so a producer that re-enqueues forever never trips
        // MAX_CYCLES — the queue-iteration ceiling is the bound that catches
        // it. A TickRequest whose producer returns itself is the minimal
        // never-reaching-an-update loop.
        $self = null;
        $self = new TickRequest(
            seconds: 0.001,
            produce: static function () use (&$self): ?Msg {
                return $self;
            },
        );

        $sim = ProgramSimulator::for(new CounterModel());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pump() exceeded');
        $sim->addMsgs($self)->run();
    }
}
