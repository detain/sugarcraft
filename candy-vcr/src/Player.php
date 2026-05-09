<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

use React\EventLoop\LoopInterface;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Vcr\Assert\Assertion;
use SugarCraft\Vcr\Assert\ByteAssertion;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Msg\Registry;

/**
 * Drive a candy-core {@see Program} through a recorded {@see Cassette},
 * then compare the program's actual output against the cassette's
 * recorded output via an {@see Assertion}.
 *
 * Usage:
 * ```php
 * $player = Player::open('session.cas');
 * $result = $player->play(
 *     fn ($input, $output, $loop) => new Program(
 *         new MyModel(),
 *         new ProgramOptions(input: $input, output: $output, loop: $loop, useAltScreen: false, catchInterrupts: false),
 *     ),
 *     assertion: new ByteAssertion(),
 * );
 * if (!$result->ok) {
 *     echo $result->diffSummary();
 *     exit(1);
 * }
 * ```
 *
 * Each `resize` event becomes a `WindowSizeMsg` sent to the program.
 * Each `input` event is converted into one or more candy-core Msgs
 * (via {@see Registry} for `msg`-form payloads, or via
 * {@see InputReader} for `b`-form raw bytes) and sent. The `quit`
 * event triggers `program->quit()`. Recorded `output` events are
 * accumulated into the `expected` byte buffer; the `actual` byte
 * buffer is what the program writes to its output stream during
 * replay. The two are compared in aggregate via the supplied
 * assertion when replay completes.
 *
 * Mirrors charmbracelet/x/vcr Player.
 */
final class Player
{
    public const SPEED_INSTANT = 0;
    public const SPEED_REALTIME = 1;

    /**
     * In SPEED_INSTANT mode the loop yields this many seconds between
     * consecutive events. Without a non-zero gap, the framerate-based
     * render tick never fires — every event would be processed in the
     * same loop iteration. 5 ms is enough for a 1 kHz tickInterval to
     * produce a frame; programs with a slower framerate will still
     * eventually render.
     */
    public const INSTANT_YIELD_SECONDS = 0.005;

    public function __construct(public readonly Cassette $cassette) {}

    public static function open(string $path): self
    {
        return new self((new JsonlFormat())->read($path));
    }

    /**
     * Drive `programFactory` through the cassette and assert the
     * output matches the recording.
     *
     * @param \Closure(resource, resource, LoopInterface): Program $programFactory
     *        Build a fresh Program. Receives an input stream resource
     *        the Player writes recorded inputs to, an output stream
     *        the Player reads from, and the React loop the Player
     *        owns. The factory must wire these into ProgramOptions.
     * @param Assertion|null $assertion        Defaults to {@see ByteAssertion}.
     * @param int            $speed            {@see self::SPEED_INSTANT} (default) or {@see self::SPEED_REALTIME}.
     * @param Registry|null  $serializerRegistry For `input.msg`-form events. Defaults to {@see Registry::default}.
     * @param float          $timeoutSeconds   Hard cap on the loop. Default: cassette duration + 5s.
     */
    public function play(
        \Closure $programFactory,
        ?Assertion $assertion = null,
        int $speed = self::SPEED_INSTANT,
        ?Registry $serializerRegistry = null,
        ?float $timeoutSeconds = null,
        bool $skipFirstResize = true,
    ): ReplayResult {
        $assertion ??= new ByteAssertion();
        $registry = $serializerRegistry ?? Registry::default();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('candy-vcr Player: stream_socket_pair() failed');
        }
        [$inputRead, $inputWrite] = $sockets;

        $output = fopen('php://memory', 'w+b');
        if ($output === false) {
            throw new \RuntimeException('candy-vcr Player: fopen(php://memory) failed');
        }

        $loop = new \React\EventLoop\StreamSelectLoop();
        $program = ($programFactory)($inputRead, $output, $loop);

        $tally = ['input' => 0, 'resize' => 0, 'output' => 0, 'quit' => 0];
        $expectedOutput = '';
        $programQuitCleanly = false;
        // Track whether the first resize has been seen (and possibly
        // skipped). The replay program emits its own startup
        // WindowSizeMsg from its tty.size() lookup, so the cassette's
        // first resize would be a duplicate that throws the model's
        // msg count off by one.
        $firstResizeSkipped = !$skipFirstResize;

        $events = $this->cassette->events;
        $eventCount = count($events);
        $i = 0;
        $step = function () use (
            &$step,
            &$i,
            $events,
            $eventCount,
            $program,
            $inputWrite,
            $registry,
            $loop,
            $speed,
            &$tally,
            &$expectedOutput,
            &$programQuitCleanly,
            &$firstResizeSkipped,
        ): void {
            if ($i >= $eventCount) {
                return;
            }
            $event = $events[$i];
            $i++;
            ($this->dispatchEvent(
                $event,
                $program,
                $inputWrite,
                $registry,
                $tally,
                $expectedOutput,
                $programQuitCleanly,
                $firstResizeSkipped,
            ))();

            if ($i >= $eventCount) {
                return;
            }
            // Schedule the next step. INSTANT mode uses a tiny yield to
            // let the program's render tick fire between events;
            // REALTIME mode uses the recorded delta between consecutive
            // event timestamps, clamped to >= 0.
            if ($speed === self::SPEED_REALTIME) {
                $delta = max(0.0, $events[$i]->t - $event->t);
            } else {
                $delta = self::INSTANT_YIELD_SECONDS;
            }
            $loop->addTimer($delta, $step);
        };

        // Kick off the first event. For REALTIME mode honour its
        // recorded `t`; for INSTANT mode start immediately.
        $firstDelay = ($speed === self::SPEED_REALTIME && $eventCount > 0)
            ? max(0.0, $events[0]->t)
            : 0.0;
        if ($eventCount > 0) {
            $loop->addTimer($firstDelay, $step);
        }

        // Safety net: stop the loop if the program never quits.
        $cap = $timeoutSeconds ?? max(5.0, $this->cassette->duration() + 5.0);
        $loop->addTimer($cap, static fn() => $loop->stop());

        $program->run();

        // Snapshot what the program actually wrote.
        $actualEnd = ftell($output);
        rewind($output);
        $actualOutput = $actualEnd > 0 ? (string) stream_get_contents($output) : '';

        $verdict = $assertion->compare($expectedOutput, $actualOutput);

        @fclose($inputRead);
        @fclose($inputWrite);
        @fclose($output);

        return new ReplayResult(
            ok: $verdict['ok'] && $programQuitCleanly,
            diff: $verdict['ok']
                ? ($programQuitCleanly ? '' : 'program did not exit on cassette quit event')
                : $verdict['diff'],
            eventCount: $tally['input'] + $tally['resize'] + $tally['output'] + $tally['quit'],
            inputCount: $tally['input'],
            resizeCount: $tally['resize'],
            outputCount: $tally['output'],
            quitCount: $tally['quit'],
            programQuitCleanly: $programQuitCleanly,
        );
    }

    /**
     * Build the closure that runs when the loop fires this event's
     * scheduled timer. Tally counters and `expectedOutput` are passed
     * by reference so the closure mutates the outer aggregate state.
     *
     * @param array{input:int,resize:int,output:int,quit:int} $tally
     * @param resource $inputWrite
     */
    private function dispatchEvent(
        Event $event,
        Program $program,
        $inputWrite,
        Registry $registry,
        array &$tally,
        string &$expectedOutput,
        bool &$programQuitCleanly,
        bool &$firstResizeSkipped,
    ): \Closure {
        return function () use ($event, $program, $inputWrite, $registry, &$tally, &$expectedOutput, &$programQuitCleanly, &$firstResizeSkipped): void {
            switch ($event->kind) {
                case EventKind::Resize:
                    $tally['resize']++;
                    if (!$firstResizeSkipped) {
                        // First resize is the cassette-recorded startup
                        // size; the replay program emits its own
                        // startup WindowSizeMsg, so dispatching this
                        // would duplicate it and throw msg counts off.
                        $firstResizeSkipped = true;
                        break;
                    }
                    $cols = (int) ($event->payload['cols'] ?? 0);
                    $rows = (int) ($event->payload['rows'] ?? 0);
                    if ($cols > 0 && $rows > 0) {
                        $program->send(new WindowSizeMsg($cols, $rows));
                    }
                    break;

                case EventKind::Input:
                    $tally['input']++;
                    if (isset($event->payload['msg']) && is_array($event->payload['msg'])) {
                        $msg = $registry->decode($event->payload['msg']);
                        if ($msg !== null) {
                            $program->send($msg);
                        }
                    } elseif (isset($event->payload['b']) && is_string($event->payload['b'])) {
                        // Raw-byte input (PR2 form): re-parse via a fresh
                        // InputReader and send the resulting Msgs directly.
                        // Bypassing the program's stream watcher avoids the
                        // async race between fwrite + fread + parse.
                        $reader = new InputReader();
                        foreach ($reader->parse($event->payload['b']) as $msg) {
                            $program->send($msg);
                        }
                    }
                    break;

                case EventKind::Output:
                    $tally['output']++;
                    $expectedOutput .= (string) ($event->payload['b'] ?? '');
                    break;

                case EventKind::Quit:
                    $tally['quit']++;
                    $programQuitCleanly = true;
                    $program->quit();
                    break;
            }
        };
    }
}
