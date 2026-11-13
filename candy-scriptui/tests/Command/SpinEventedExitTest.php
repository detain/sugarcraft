<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Forms\Spinner\Style as SpinStyle;
use SugarCraft\Forms\Spinner\TickMsg;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-10 (finding 096): child-exit detection is evented, not
 * tick-coupled. A child exiting at 50 ms settles the run at watcher
 * granularity (15 ms cadence) — well before the 100 ms animation tick
 * that used to be the ONLY probe. The tick remains an independent belt
 * (pinned at the model directly), never removed.
 */
final class SpinEventedExitTest extends TestCase
{
    public function testExitSettlesBeforeTheFirstAnimationTickCouldArrive(): void
    {
        $fake = new FakeProcess();
        $loop = \React\EventLoop\Loop::get();

        // Same loop, wall-clock ordering: the exit lands at 50 ms, the
        // first spinner tick not before 100 ms; the run must end on the
        // watcher beat (~65 ms), never waiting for the belt.
        $loop->addTimer(0.05, static function () use ($fake): void {
            $fake->finish(7);
        });
        $command = $this->spinWith($fake);

        $start  = \microtime(true);
        $status = $command->run(new ArrayInput(['argv' => ['true']]), new BufferedOutput());
        $elapsed = \microtime(true) - $start;

        self::assertSame(7, $status, 'the child verdict reaches the CLI');
        self::assertLessThan(0.095, $elapsed, 'settled by the 15 ms watcher, not the 100 ms tick');
    }

    public function testWatcherCadenceMatchesTheDocumentedPollInterval(): void
    {
        self::assertSame(0.015, SpinCommand::EXIT_POLL_SECONDS);
    }

    public function testBeltGeometryTheDefaultSpinnerCannotBeatTheWatcher(): void
    {
        // Guards the guard: if the default style ever ticked faster than
        // the watcher settles, the first test would prove nothing.
        $interval = SpinStyle::dot()->interval();

        self::assertGreaterThan(SpinCommand::EXIT_POLL_SECONDS * 2, $interval);
    }

    public function testAnimationTickRemainsTheFallbackBelt(): void
    {
        // The belt is a SECOND, independent path: a model probed straight
        // from an animation tick (no watcher involved) still captures the
        // verdict and ends the run.
        $fake = new FakeProcess();
        $fake->finish(3);
        $model = SpinModel::spawn($fake);

        [$final, $cmd] = $model->update(new TickMsg($model->spinner->id));

        self::assertInstanceOf(SpinModel::class, $final);
        self::assertTrue($final->isDone());
        self::assertSame(3, $final->exitCode());
        self::assertNotNull($cmd);
    }

    private function spinWith(FakeProcess $fake): SpinCommand
    {
        return new SpinCommand(
            processFactory: static fn (array $argv, bool $so, bool $se): Process => $fake,
            programFactory: static function (Model $model, \React\EventLoop\LoopInterface $loop): Program {
                // Socket pair, not php://memory: StreamSelectLoop must always
                // have a real select-able descriptor for the input watcher.
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertIsArray($pair);
                [$in, $writer] = $pair;
                $out = fopen('php://memory', 'w+b');
                self::assertIsResource($out);
                register_shutdown_function(static function () use ($in, $writer, $out): void {
                    foreach ([$in, $writer, $out] as $s) {
                        if (is_resource($s)) {
                            fclose($s);
                        }
                    }
                });

                return new Program($model, new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      false,
                    catchInterrupts: false,
                    input:           $in,
                    output:          $out,
                    loop:            $loop,
                    windowSize:      ['cols' => 80, 'rows' => 24],
                    framerate:       1.0,
                ));
            },
        );
    }
}
