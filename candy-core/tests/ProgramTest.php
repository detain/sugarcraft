<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\Msg\WindowSizeMsg;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

/**
 * Recording model: appends each Msg it sees, optionally returns a Cmd, and
 * quits when {@see $quitAfter} messages have been processed.
 */
final class RecordingModel implements Model
{
    /** @var list<Msg> */
    public array $log = [];

    public function __construct(
        public readonly int $quitAfter = PHP_INT_MAX,
        public readonly ?\Closure $initCmd = null,
    ) {}

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->log = [...$this->log, $msg];
        $cmd = count($next->log) >= $this->quitAfter ? Cmd::quit() : null;
        return [$next, $cmd];
    }

    public function view(): string
    {
        return 'frames: ' . count($this->log);
    }
}

final class ProgramTest extends TestCase
{
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

    public function testInitialWindowSizeAndQuit(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: 2);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->send(new KeyMsg(\CandyCore\Core\KeyType::Char, 'q'));

        // Safety net: stop the loop after 2s if our quit logic is broken.
        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();
        $this->assertInstanceOf(RecordingModel::class, $final);
        $this->assertCount(2, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);
        $this->assertInstanceOf(KeyMsg::class,        $final->log[1]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testInputStreamFeedsKeyMsg(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: 3);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(0.01, static function () use ($writer) {
            fwrite($writer, "ab");
            fclose($writer);
        });
        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();

        $this->assertCount(3, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);
        $this->assertSame('a', $final->log[1]->rune);
        $this->assertSame('b', $final->log[2]->rune);

        fclose($in);
        fclose($out);
    }

    public function testInitCmdRuns(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $marker = new class implements Msg {};
        $model = new RecordingModel(
            quitAfter: 2,
            initCmd: static fn() => $marker,
        );
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();

        $this->assertCount(2, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);
        $this->assertSame($marker, $final->log[1]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testQuitMsgStopsLoopBeforeRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->quit();

        $final = $program->run();
        // QuitMsg short-circuits before the loop, so only WindowSizeMsg
        // hit update().
        $this->assertCount(1, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
