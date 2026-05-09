<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Recorder;

/**
 * End-to-end: run a candy-core Program with a Recorder attached, then
 * decode the cassette and assert it captured the expected lifecycle.
 */
final class ProgramRecordingTest extends TestCase
{
    public function testRecordsResizeOutputInputAndQuit(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $cassette = tempnam(sys_get_temp_dir(), 'candy-vcr-prog-');
        $this->assertNotFalse($cassette);

        try {
            $model = new CountingModel(quitAfter: 4);
            $program = new Program($model, $this->makeOptions($in, $out, $loop));
            $program->withRecorder(Recorder::open($cassette, Recorder::defaultHeader(120, 40)));
            $program->send(new KeyMsg(KeyType::Char, 'q'));

            $loop->addTimer(2.0, static fn() => $loop->stop());
            $program->run();

            $tape = (new JsonlFormat())->read($cassette);

            $this->assertSame(1, $tape->header->version);
            $this->assertSame(120, $tape->header->cols);
            $this->assertSame(40, $tape->header->rows);

            $kinds = array_map(fn($e) => $e->kind, $tape->events);
            $this->assertContains(EventKind::Resize, $kinds, 'startup WindowSizeMsg should be recorded as resize');
            $this->assertContains(EventKind::Output, $kinds, 'renderer frames should produce output events');
            $this->assertContains(EventKind::Quit, $kinds, 'QuitMsg should produce a quit event');

            // The startup resize event records the program's inferred TTY
            // size. setupTerminal may emit a few output bytes (e.g.
            // unicodeOn) before the WindowSizeMsg dispatch, so the resize
            // is among the first few events but not necessarily at index 0.
            $resize = null;
            foreach ($tape->events as $e) {
                if ($e->kind === EventKind::Resize) {
                    $resize = $e;
                    break;
                }
            }
            $this->assertNotNull($resize);
            $this->assertGreaterThan(0, $resize->payload['cols']);
            $this->assertGreaterThan(0, $resize->payload['rows']);

            // Quit event is last.
            $last = $tape->events[count($tape->events) - 1];
            $this->assertSame(EventKind::Quit, $last->kind);
        } finally {
            @unlink($cassette);
            fclose($writer);
            fclose($in);
            fclose($out);
        }
    }

    public function testStreamInputBytesAreRecorded(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $cassette = tempnam(sys_get_temp_dir(), 'candy-vcr-prog-');
        $this->assertNotFalse($cassette);

        try {
            $model = new CountingModel(quitAfter: 4);
            $program = new Program($model, $this->makeOptions($in, $out, $loop));
            $program->withRecorder(Recorder::open($cassette));

            // Pipe a "hello" byte sequence into the program. The
            // input-stream watcher fires on the next loop iteration and
            // records the bytes verbatim.
            $loop->futureTick(static function () use ($writer): void {
                fwrite($writer, "hello");
            });
            $loop->addTimer(2.0, static fn() => $loop->stop());

            $program->run();

            $tape = (new JsonlFormat())->read($cassette);
            $inputs = array_filter($tape->events, fn($e) => $e->kind === EventKind::Input);
            $this->assertNotEmpty($inputs, 'piped input bytes should be recorded');
            $bytes = '';
            foreach ($inputs as $e) {
                $bytes .= $e->payload['b'];
            }
            $this->assertStringContainsString('hello', $bytes);
        } finally {
            @unlink($cassette);
            fclose($writer);
            fclose($in);
            fclose($out);
        }
    }

    public function testWithRecorderNullDetaches(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $cassette = tempnam(sys_get_temp_dir(), 'candy-vcr-prog-');
        $this->assertNotFalse($cassette);

        try {
            $recorder = Recorder::open($cassette);
            $model = new CountingModel(quitAfter: 4);
            $program = new Program($model, $this->makeOptions($in, $out, $loop));
            $program->withRecorder($recorder);
            $program->withRecorder(null);
            $recorder->close();

            $program->send(new KeyMsg(KeyType::Char, 'q'));
            $loop->addTimer(2.0, static fn() => $loop->stop());
            $program->run();

            // Cassette only has the header line (and nothing else, since
            // the recorder was detached before run() began emitting).
            $tape = (new JsonlFormat())->read($cassette);
            $this->assertSame(0, $tape->eventCount());
        } finally {
            @unlink($cassette);
            fclose($writer);
            fclose($in);
            fclose($out);
        }
    }

    public function testWithRecorderReturnsSelfForChaining(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        try {
            $program = new Program(new CountingModel(quitAfter: 5), $this->makeOptions($in, $out, $loop));
            $cassette = tempnam(sys_get_temp_dir(), 'candy-vcr-prog-');
            $this->assertNotFalse($cassette);
            try {
                $returned = $program->withRecorder(Recorder::open($cassette));
                $this->assertSame($program, $returned);
                $program->withRecorder(null);
            } finally {
                @unlink($cassette);
            }
        } finally {
            fclose($writer);
            fclose($in);
            fclose($out);
        }
    }

    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    private function makeOptions($in, $out, StreamSelectLoop $loop): ProgramOptions
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

/**
 * Test model: counts the Msgs it sees and quits after `quitAfter`.
 * Renders a non-empty body so the renderer emits frame bytes.
 */
final class CountingModel implements Model
{
    public int $count = 0;

    public function __construct(public readonly int $quitAfter = 5) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->count = $this->count + 1;
        $cmd = $next->count >= $this->quitAfter ? Cmd::quit() : null;
        return [$next, $cmd];
    }

    public function view(): string
    {
        return 'tick: ' . $this->count;
    }
}
