<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use React\Promise\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

// Test-specific message classes
final class FirstMsg implements Msg {}
final class SecondMsg implements Msg {}

/**
 * Simple model that records received messages.
 */
final class MessageRecordingModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    /** @var list<Msg> */
    public array $log = [];

    public function __construct(
        public readonly ?\Closure $initCmd = null,
    ) {}

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    public function update(Msg $msg): array
    {
        $this->log[] = $msg;
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }
}

final class AsyncCmdTest extends TestCase
{
    public function testAsyncCmdWrapsPromise(): void
    {
        $promise = new Promise(function (callable $resolve): void {
            $resolve(null);
        });
        $asyncCmd = new AsyncCmd($promise);
        $this->assertSame($promise, $asyncCmd->promise);
    }

    public function testCmdPromiseFactory(): void
    {
        $innerPromise = new Promise(function (callable $resolve): void {
            $resolve(null);
        });
        $factory = Cmd::promise(static fn() => $innerPromise);
        $this->assertInstanceOf(\Closure::class, $factory);
        $result = $factory();
        $this->assertInstanceOf(AsyncCmd::class, $result);
        $this->assertSame($innerPromise, $result->promise);
    }

    public function testProgramHandlesAsyncCmd(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $resolvedMsg = new class implements Msg {};

        $initCmd = Cmd::promise(static fn() => new Promise(
            static fn(callable $resolve) => $resolve($resolvedMsg)
        ));

        $model = new MessageRecordingModel($initCmd);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(1.0, static fn() => $loop->stop());
        $program->run();

        $this->assertContains($resolvedMsg, $model->log);
        fclose($writer);
    }

    public function testProgramHandlesAsyncCmdRejection(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $exception = new \RuntimeException('async error');

        $initCmd = Cmd::promise(static fn() => new Promise(
            static fn(callable $resolve, callable $reject) => $reject($exception)
        ));

        $model = new MessageRecordingModel($initCmd);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(1.0, static fn() => $loop->stop());
        $program->run();

        $exceptionMsgs = array_filter(
            $model->log,
            static fn(Msg $m) => $m instanceof ExceptionMsg
        );
        $this->assertCount(1, $exceptionMsgs);
        $exceptionMsg = array_values($exceptionMsgs)[0];
        $this->assertSame($exception, $exceptionMsg->exception);
    }

    public function testAsyncCmdWithNullResult(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $initCmd = Cmd::promise(static fn() => new Promise(
            static fn(callable $resolve) => $resolve(null)
        ));

        $model = new MessageRecordingModel($initCmd);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(1.0, static fn() => $loop->stop());
        $program->run();

        // Should only have WindowSizeMsg, EnvMsg, ColorProfileMsg from startup
        // The null resolve should NOT add any new messages
        $nonStartupMsgs = array_filter(
            $model->log,
            static fn(Msg $m) => !($m instanceof WindowSizeMsg)
        );
        $this->assertCount(2, $nonStartupMsgs); // EnvMsg + ColorProfileMsg
    }

    public function testPromiseChain(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $firstMsg = new FirstMsg();
        $secondMsg = new SecondMsg();

        $firstResolve = null;
        $secondResolve = null;

        // First promise resolves with firstMsg, second with secondMsg
        $initCmd = Cmd::promise(static function () use (&$firstResolve, $firstMsg): PromiseInterface {
            return new Promise(static function (callable $resolve) use (&$firstResolve, $firstMsg): void {
                $firstResolve = $resolve;
                $resolve($firstMsg);
            });
        });

        $model = new class($initCmd) implements Model {
            use \SugarCraft\Core\SubscriptionCapable;

            /** @var list<Msg> */
            public array $log = [];
            private int $phase = 0;

            public function __construct(private readonly ?\Closure $initCmd) {}

            public function init(): ?\Closure
            {
                return $this->initCmd;
            }

            public function update(Msg $msg): array
            {
                $this->log[] = $msg;

                // After first async msg, schedule second async
                if ($msg instanceof FirstMsg && $this->phase === 0) {
                    $this->phase = 1;
                    $secondMsg = new SecondMsg();
                    return [$this, Cmd::promise(static fn() => new Promise(
                        static function (callable $resolve) use ($secondMsg): void {
                            $resolve($secondMsg);
                        }
                    ))];
                }

                return [$this, null];
            }

            public function view(): string
            {
                return '';
            }
        };

        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(2.0, static fn() => $loop->stop());
        $program->run();

        $firstMsgs = array_filter($model->log, static fn(Msg $m) => $m instanceof FirstMsg);
        $secondMsgs = array_filter($model->log, static fn(Msg $m) => $m instanceof SecondMsg);
        $this->assertCount(1, $firstMsgs);
        $this->assertCount(1, $secondMsgs);
        fclose($writer);
    }

    /** @return array{0:resource, 1:resource, 2:resource} input, output, inputWriter */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function makeOptions($in, $out, $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }
}
