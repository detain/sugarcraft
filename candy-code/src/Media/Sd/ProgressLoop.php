<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Sd;

use SugarCraft\Async\Backoff;
use SugarCraft\Async\Poll;
use SugarCraft\Async\Retry;
use SugarCraft\Async\SynchronousLoop;

/**
 * Heartbeat progress loop (plan W1.5) — mystage fact (a) made executable.
 *
 * EngineBackend's 120 s COMPLETE_TIMEOUT_SECONDS is an IDLE ceiling re-armed
 * per streamed frame, so any long-running generation tool MUST beat it while
 * waiting. This loop is the reusable beat source: it polls
 * `/sdapi/v1/progress?skip_current_image=<bool>` and fires `$heartbeat()`
 * on EVERY poll BEFORE sleeping — the per-frame re-arm law, so a silent 120 s
 * render never dies inside its first interval. W2.1/W5.3 wire the shape into
 * AcceptsHeartbeat::executeWithHeartbeat(array $args, \Closure $heartbeat).
 *
 * Bounding doctrine (E646): there is NO wall-clock deadline here. The loop
 * ends on the caller's done-sentinel ($isCancelled doubles as it), on a
 * server-reported finished frame, or after MAX_CONSECUTIVE_POLL_FAILURES
 * consecutive poll failures — bounded fail-fast with a give-up reason naming
 * the last error, never a hang and never an unbounded retry storm.
 *
 * Co-tenant honesty (§4.6): /progress reads the SHARED Gradio UI-state
 * singleton. Frames may interleave other jobs — monotonicity is NOT assumed
 * and progress is reported raw; a server-side finished flag is ADVISORY (it
 * may reflect another job). Callers awaiting their OWN generation keep the
 * done-sentinel wired and read the termination from the summary.
 */
final class ProgressLoop
{
    public const MAX_CONSECUTIVE_POLL_FAILURES = 3;

    public const DEFAULT_INTERVAL_SECONDS = 0.5;

    private function __construct()
    {
    }

    /**
     * @param callable():void            $heartbeat     fired once per poll, before sleeping
     * @param callable():bool            $isCancelled   done-sentinel checked between polls
     * @param (callable(string):void)|null $onPreviewFrame receives current_image b64 when the server sent one
     * @param (callable(ProgressState):void)|null $onFrame every accepted frame — the MediaJob feed:
     *        `fn ($s) => $job = $job->withProgressFrame($s->raw)` (ring-bounded inside MediaJob, W1.5 seam)
     * @param (callable(float):void)|null $sleeper pacing seam (sugar-readline E713 pattern): tests
     *        inject a zero-real-time fake; production defaults to usleep of the interval. This paces
     *        POLL CADENCE — it is not, and may never become, a request deadline (E646).
     *
     * @throws SdException when the interval is non-positive or above Retry::MAX_BACKOFF_SECONDS
     */
    public static function run(
        Client $c,
        callable $heartbeat,
        callable $isCancelled,
        ?callable $onPreviewFrame = null,
        float $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        ?callable $sleeper = null,
        ?callable $onFrame = null,
    ): ProgressSummary {
        if ($intervalSeconds <= 0.0) {
            throw SdException::protocol('progress-loop', 'poll interval must be positive');
        }
        // Poll::until clamps every pause at Retry::MAX_BACKOFF_SECONDS, so a
        // longer interval would silently sleep the ceiling instead; refuse it.
        if ($intervalSeconds > Retry::MAX_BACKOFF_SECONDS) {
            throw SdException::protocol(
                'progress-loop',
                'poll interval must not exceed ' . Retry::MAX_BACKOFF_SECONDS . ' seconds',
            );
        }
        $sleep = $sleeper ?? static function (float $seconds): void {
            usleep(max(1, (int) round($seconds * 1_000_000)));
        };

        $polls = 0;
        $beats = 0;
        $previews = 0;
        $failures = 0;
        $last = null;
        $wantsPreview = $onPreviewFrame !== null;

        // One poll per attempt; Poll::until paces the attempts on the
        // synchronous loop, so each pause is the same interval sleep as before.
        $attempt = static function () use (
            $c,
            $heartbeat,
            $isCancelled,
            $onPreviewFrame,
            $onFrame,
            $wantsPreview,
            &$polls,
            &$beats,
            &$previews,
            &$failures,
            &$last,
        ): ?ProgressSummary {
            try {
                $payload = $c->progress($wantsPreview);
                $failures = 0;
            } catch (SdException $e) {
                $failures++;
                if ($failures >= self::MAX_CONSECUTIVE_POLL_FAILURES) {
                    throw SdException::protocol(
                        'progress-loop',
                        "giving up after {$failures} consecutive /sdapi/v1/progress poll failures; last error: {$e->getMessage()}",
                    );
                }
                // Keep the watchdog fed even while the progress route itself
                // is flapping — a missing readout must not kill the waited-on
                // generation. Cancel still wins before the retry.
                $heartbeat();
                $beats++;
                if ($isCancelled()) {
                    return ProgressSummary::new($polls, $beats, $previews, $last, ProgressSummary::TERMINATION_DONE);
                }

                return null;
            }

            $polls++;
            $state = ProgressState::fromArray($payload);
            $last = $state;

            $heartbeat();
            $beats++;
            if ($onFrame !== null) {
                $onFrame($state);
            }
            if ($wantsPreview && $state->currentImage !== null) {
                $onPreviewFrame($state->currentImage);
                $previews++;
            }

            if ($isCancelled()) {
                return ProgressSummary::new($polls, $beats, $previews, $last, ProgressSummary::TERMINATION_DONE);
            }
            if ($state->finished()) {
                return ProgressSummary::new($polls, $beats, $previews, $last, ProgressSummary::TERMINATION_SERVER_FINISHED);
            }

            return null;
        };

        $loop = SynchronousLoop::new($sleep);
        $summary = null;
        $error = null;
        Poll::until(
            $attempt,
            Backoff::new((float) max(1, (int) round($intervalSeconds * 1000)), 1.0),
            null,
            $loop,
        )->then(
            static function (ProgressSummary $settled) use (&$summary): void {
                $summary = $settled;
            },
            static function (\Throwable $failure) use (&$error): void {
                $error = $failure;
            },
        );

        // Pauses run here, on the calling thread, one interval at a time.
        $loop->run();

        if ($error !== null) {
            throw $error;
        }

        return $summary ?? throw SdException::protocol('progress-loop', 'poll ended without a terminal summary');
    }

    /**
     * Interrupt passthrough (progress-control surface lives beside the loop
     * that watches it). A1111's interrupt is GLOBAL server state, not scoped
     * to our task (§4.6). Two-stage: $afterCurrent rides the
     * interrupt_after_current header so the CURRENT image finishes first
     * (§4.7); immediate aborts the job in flight.
     */
    public static function requestInterrupt(Client $c, bool $afterCurrent = false): void
    {
        $c->interrupt($afterCurrent);
    }

    /** Skip the current batch item — likewise GLOBAL state (§4.6). */
    public static function requestSkip(Client $c): void
    {
        $c->skip();
    }
}
