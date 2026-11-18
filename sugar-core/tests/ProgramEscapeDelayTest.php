<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Tests\Support\LoggingModel;

/**
 * ProgramOptions::$escapeDelay must drive the lone-ESC settle timer in
 * Program::scheduleEscapeFlush(). The option is only validated and stored
 * elsewhere, so a reverted literal (or a timer that ignores the option)
 * would leave those tests green; these runs observe the timer itself.
 */
final class ProgramEscapeDelayTest extends TestCase
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
     * Writes a lone ESC byte at 10 ms and quits at $quitAt. Returns how many
     * Escape KeyMsgs were dispatched before run() returned.
     */
    private function escapesBeforeQuit(float $escapeDelay, float $quitAt): int
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $this->streams = [$in, $writer, $out];

        $loop = new StreamSelectLoop();
        $loop->addTimer(0.01, static function () use ($writer): void {
            fwrite($writer, "\x1b");
        });
        $loop->addTimer($quitAt + 3.0, static fn () => $loop->stop()); // safety net

        $model = new LoggingModel(initCmd: Cmd::tick($quitAt, static fn (): Msg => new QuitMsg()));
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            escapeDelay: $escapeDelay,
        ));
        $program->run();

        return count(array_filter(
            $program->model()->log,
            static fn (Msg $m): bool => $m instanceof KeyMsg && $m->type === KeyType::Escape,
        ));
    }

    public function testLoneEscIsHeldUntilTheConfiguredDelayElapses(): void
    {
        // Quit at 150 ms: the 300 ms settle window has not elapsed, so the
        // ESC must still be buffered and never dispatched.
        $this->assertSame(0, $this->escapesBeforeQuit(0.3, 0.15));
    }

    public function testLoneEscIsDispatchedOnceTheConfiguredDelayElapses(): void
    {
        // Quit at 600 ms: the 300 ms window closed first, so the ESC flushes
        // exactly once before the quit.
        $this->assertSame(1, $this->escapesBeforeQuit(0.3, 0.6));
    }

    public function testDefaultDelayFlushesLoneEscWellBeforeAThreeHundredMsQuit(): void
    {
        // Control for the two cases above: the 50 ms default flushes the ESC
        // before a 150 ms quit, so the 0-count above is due to escapeDelay.
        $this->assertSame(1, $this->escapesBeforeQuit(0.05, 0.15));
    }
}
