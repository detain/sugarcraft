<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Tests\Support\LoggingModel;

/**
 * crush_libs.md candy-core #5, liveness half: a `CSI 200~` whose `CSI 201~`
 * is lost left the reader in paste mode forever, so every keystroke after it
 * vanished into the paste buffer — a dead keyboard, since a short paste never
 * nears MAX_PASTE_BYTES. Program now closes a paste after
 * InputReader::PASTE_IDLE_TIMEOUT of input silence, re-arming on every read
 * so a paste that is still arriving is never cut.
 */
final class ProgramStalePasteTest extends TestCase
{
    /** @var list<resource> */
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
        $this->streams = [];
    }

    /**
     * Run a Program whose input receives each `[delay, bytes]` write at its
     * delay (seconds after run() starts), quitting at `$quitAt`.
     *
     * @param list<array{0: float, 1: string}> $writes
     * @return array{0: Program, 1: list<Msg>}
     */
    private function runWith(array $writes, float $quitAt): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $this->streams = [$in, $writer, $out];

        $loop = new StreamSelectLoop();
        foreach ($writes as [$at, $bytes]) {
            $loop->addTimer($at, static function () use ($writer, $bytes): void {
                fwrite($writer, $bytes);
            });
        }
        $loop->addTimer($quitAt + 3.0, static fn () => $loop->stop()); // safety net

        $model = new LoggingModel(initCmd: Cmd::tick($quitAt, static fn (): Msg => new QuitMsg()));
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        ));
        $program->run();

        $log = array_values(array_filter(
            $program->model()->log,
            static fn (Msg $m): bool => $m instanceof PasteStartMsg
                || $m instanceof PasteEndMsg
                || $m instanceof PasteMsg
                || $m instanceof KeyMsg,
        ));
        return [$program, $log];
    }

    /**
     * @param list<Msg> $log
     * @return list<string>
     */
    private static function describe(array $log): array
    {
        return array_map(static fn (Msg $m): string => match (true) {
            $m instanceof PasteStartMsg => 'start',
            $m instanceof PasteEndMsg   => 'end',
            $m instanceof PasteMsg      => 'paste:' . $m->content,
            $m instanceof KeyMsg        => 'key:' . $m->rune,
        }, $log);
    }

    public function testUnclosedPasteGivesTheKeyboardBackAfterAReadGap(): void
    {
        $gap = InputReader::PASTE_IDLE_TIMEOUT + 0.3;
        [, $log] = $this->runWith([
            [0.01, "\x1b[200~abc"],   // the terminal drops the CSI 201~
            [$gap, 'x'],              // the user types after a pause
        ], $gap + 0.2);

        $this->assertSame(['start', 'end', 'paste:abc', 'key:x'], self::describe($log));
    }

    public function testAPasteStillArrivingIsNeverCutByTheIdleTimer(): void
    {
        // Each gap is under the timeout but the paste as a whole outlasts it:
        // the deadline must restart on every read, not run from the start.
        $step = InputReader::PASTE_IDLE_TIMEOUT * 0.6;
        [, $log] = $this->runWith([
            [0.01, "\x1b[200~ab"],
            [0.01 + $step, "c\n"],
            [0.01 + 2 * $step, "d\x1b[201~q"],
        ], 0.01 + 2 * $step + 0.2);

        $this->assertSame(['start', 'end', "paste:abc\nd", 'key:q'], self::describe($log));
    }

    public function testPendingStalePasteTimerIsCancelledAtQuit(): void
    {
        [$program, $log] = $this->runWith([[0.01, "\x1b[200~abc"]], 0.1);

        $this->assertSame(['start'], self::describe($log));
        $timers = new \ReflectionProperty(Program::class, 'oneShotTimers');
        $this->assertSame([], $timers->getValue($program), 'run() must cancel the stale-paste timer');
    }
}
