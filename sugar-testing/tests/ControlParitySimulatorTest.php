<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\EnvMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\PrintMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\SequenceMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\TestResult;

/**
 * Control-layer dispatch parity with {@see \SugarCraft\Core\Program::dispatch()}.
 *
 * Before this suite the simulator fed EVERY queued message straight to
 * update() — batches, ticks, raw writes, and QuitMsg included — so a test
 * could green-light a model that production would never show those
 * messages, and a scripted QuitMsg kept the drain running. These pins
 * prove the interception ladder: control messages are handled the way the
 * runtime handles them (executed, written, or halting the drain), never
 * delivered to the model, with {@see \SugarCraft\Testing\TestResult::$droppedAfterStop}
 * counting what the halt discarded.
 */
#[Group('control-parity')]
final class ControlParitySimulatorTest extends TestCase
{
    private function key(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, rune: $rune);
    }

    public function testBatchMsgRunsMembersWithoutReachingModel(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new BatchMsg([
                fn (): Msg => $this->key('B'),
                fn (): Msg => $this->key('C'),
            ]))
            ->run();

        self::assertSame('IBC', $result->model->trace);
        self::assertStringNotContainsString('BatchMsg', $result->model->trace);
    }

    public function testSequenceMsgDispatchesMembersInOrder(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new SequenceMsg([
                fn (): Msg => $this->key('1'),
                fn (): Msg => $this->key('2'),
            ]))
            ->run();

        self::assertSame('I12', $result->model->trace);
    }

    public function testNonClosureBatchMemberRefusesLoudly(): void
    {
        $sim = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new BatchMsg(['not-a-cmd']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be Closures here');

        $sim->run();
    }

    public function testQuitMsgInQueueHaltsDrainAndDropsTheRest(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send($this->key('a'), new QuitMsg(), $this->key('z'))
            ->run();

        self::assertSame('Ia', $result->model->trace);
        self::assertSame(1, $result->droppedAfterStop);
        self::assertStringNotContainsString('QuitMsg', $result->model->trace);
    }

    public function testInterruptMsgAlsoHalts(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send($this->key('a'), new InterruptMsg(), $this->key('z'))
            ->run();

        self::assertSame('Ia', $result->model->trace);
        self::assertSame(1, $result->droppedAfterStop);
    }

    public function testCmdQuitFromUpdateStopsTheLoop(): void
    {
        // The 'q' key IS shown to the model (it decides to quit); the QuitMsg
        // the resulting cmd produces halts the drain like production's.
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send($this->key('q'), $this->key('z'))
            ->run();

        self::assertSame('Iq', $result->model->trace);
        self::assertSame(1, $result->droppedAfterStop);
    }

    public function testSuspendMsgIsConsumedWithoutAModelTurn(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new SuspendMsg(), $this->key('s'))
            ->run();

        self::assertSame('Is', $result->model->trace);
        self::assertSame(0, $result->droppedAfterStop);
    }

    public function testRawMsgWritesBytesWithoutAModelTurn(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new RawMsg("\x1b[9mX"))
            ->run();

        self::assertSame('I', $result->model->trace);
        self::assertStringContainsString("\x1b[9mX", $result->output);
    }

    public function testPrintMsgAppendsATerminatedLineToOutput(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new PrintMsg('hello'))
            ->run();

        self::assertSame('I', $result->model->trace);
        self::assertStringContainsString("hello\n", $result->output);
    }

    public function testTickRequestFiresImmediatelyAndThreadsItsResult(): void
    {
        // No virtual clock: the produce() result dispatches on the spot,
        // matching how Program::dispatch recurses from its one-shot timer.
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new TickRequest(5.0, fn (): Msg => $this->key('T')))
            ->run();

        self::assertSame('IT', $result->model->trace);
    }

    public function testExecRequestRefusesLoudly(): void
    {
        $sim = ProgramSimulator::for(new ControlRecorderModel())
            ->send(new ExecRequest('ls'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot dispatch');

        $sim->run();
    }

    public function testStartupTrioIsDeliveredBeforeInitWhenArmed(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->withWindowSize(80, 24)
            ->withEnvironment(['K' => 'V'])
            ->withColorProfile(ColorProfile::detect(['FORCE_COLOR' => '1']))
            ->run();

        // Program::run() dispatches the trio before init(); the trace shows
        // exactly that order, with init()'s produced key landing last.
        self::assertSame('80x24K=VCPI', $result->model->trace);
    }

    public function testStartupTrioIsAbsentByDefault(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())->run();

        self::assertSame('I', $result->model->trace);
    }

    public function testRunIsRepeatable(): void
    {
        $sim = ProgramSimulator::for(new ControlRecorderModel())->send($this->key('a'));

        $first  = $sim->run();
        $second = $sim->run();

        self::assertSame('Ia', $first->model->trace);
        self::assertSame('Ia', $second->model->trace, 'run() leaked state into its replay');
    }

    public function testFramesAccumulateOnePerUpdatePlusFinal(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())
            ->send($this->key('a'), $this->key('b'))
            ->run();

        self::assertSame(['I', 'Ia', 'Iab', 'Iab'], $result->frames);
        self::assertSame('Iab', $result->view);
    }

    public function testQueueIsCopiedAtCloneTimeSoSendBeforeConfiguringIsInvisibleToTheClone(): void
    {
        // The documented clone discipline: with*() returns a configured
        // CLONE carrying the queue as it stood at clone time. Sends that
        // land on the PRE-clone handle afterwards are invisible to the
        // configured handle — this pin freezes that asymmetry so the
        // silent-message-loss trap can never silently flip.
        $handle = ProgramSimulator::for(new ControlRecorderModel());
        $configured = $handle->withRealCmdRunner(true);
        $handle->send($this->key('x'));

        $cloneRun = $configured->run();
        self::assertSame('I', $cloneRun->model->trace, 'a send() on the pre-clone handle reached the clone');

        $originalRun = $handle->run();
        self::assertSame('Ix', $originalRun->model->trace);
    }

    public function testTestResultCarriesTheModelAndOutputShapes(): void
    {
        $result = ProgramSimulator::for(new ControlRecorderModel())->run();

        self::assertInstanceOf(TestResult::class, $result);
        self::assertInstanceOf(ControlRecorderModel::class, $result->model);
        self::assertIsString($result->output);
        self::assertIsArray($result->cmds);
    }
}
