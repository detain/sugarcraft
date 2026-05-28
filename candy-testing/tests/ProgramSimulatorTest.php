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
