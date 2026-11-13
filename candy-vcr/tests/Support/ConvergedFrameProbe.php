<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use React\EventLoop\LoopInterface;
use SugarCraft\Core\Program;

/**
 * Event-driven recording stop for the round-trip tests (finding 013).
 *
 * The old pattern — `addTimer(0.020, quit)` — raced the input read and
 * the render: under CI load the wall-clock quit could fire BEFORE the
 * final frame was rendered, so the cassette recorded the `quit` event
 * ahead of the last `output` frame. Replay stops accumulating expected
 * output at the quit event, then compared a blank recorded screen
 * against the replay's converged frame — a flaky mismatch.
 *
 * The probe instead polls the in-memory output stream on the loop's
 * futureTick chain and quits only once the converged frame has actually
 * been rendered (and therefore recorded), regardless of host speed.
 * Callers still arm their own hard-stop safety timer.
 */
final class ConvergedFrameProbe
{
    private function __construct()
    {
    }

    /**
     * Arm the probe: quit $program from $loop as soon as $finalFrame
     * appears in $output. Reads the stream without disturbing its
     * write pointer.
     *
     * @param resource $output in-memory stream the program renders into
     */
    public static function arm(LoopInterface $loop, Program $program, mixed $output, string $finalFrame): void
    {
        $probe = null;
        $probe = static function () use (&$probe, $program, $loop, $output, $finalFrame): void {
            $pos = ftell($output);
            rewind($output);
            $written = stream_get_contents($output);
            if ($pos !== false) {
                fseek($output, $pos);
            }
            if (is_string($written) && str_contains($written, $finalFrame)) {
                $program->quit();
                return;
            }
            $loop->futureTick($probe);
        };
        $loop->futureTick($probe);
    }
}
