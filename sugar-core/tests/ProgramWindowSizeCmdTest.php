<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Records every WindowSizeMsg it receives, so a test can read back the size
 * the Program resolved for a Cmd::windowSize() request.
 */
final class WindowSizeRecorderModel implements Model
{
    use SubscriptionCapable;

    public function __construct(public readonly \ArrayObject $seen = new \ArrayObject())
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $this->seen['size'] = [$msg->cols, $msg->rows];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }
}

/**
 * Cmd::windowSize() resolved by the real Program runtime (not the sugar-testing
 * simulator): the request must honour ProgramOptions::$windowSize and reach the
 * model as a WindowSizeMsg.
 */
final class ProgramWindowSizeCmdTest extends TestCase
{
    /** @return array{0: resource, 1: resource, 2: resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function program(WindowSizeRecorderModel $model, $in, $out, ?array $windowSize): Program
    {
        return new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
            windowSize: $windowSize,
        ));
    }

    public function testWindowSizeCmdDispatchesTheOverrideAsAWindowSizeMsg(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $model = new WindowSizeRecorderModel();
        $program = $this->program($model, $in, $out, ['cols' => 132, 'rows' => 43]);
        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');

        $request = (Cmd::windowSize())();
        $dispatch->invoke($program, $request);

        $this->assertSame([132, 43], $model->seen['size'] ?? null,
            'the Cmd must resolve to the ProgramOptions::$windowSize override');

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
