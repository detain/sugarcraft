<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
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

    public function testSendReturnsSelfForChaining(): void
    {
        $model = new CounterModel();
        $program = new Program($model);
        $sim = ProgramSimulator::for($program);

        $result = $sim->send(new KeyMsg(
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

        $sim->send(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ))->send(new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        ))->send(new KeyMsg(
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

        $sim->send(new KeyMsg(
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
}
