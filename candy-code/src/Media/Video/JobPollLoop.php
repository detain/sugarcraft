<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Video;

use SugarCraft\Async\Backoff;
use SugarCraft\Async\Poll;
use SugarCraft\Async\Retry;
use SugarCraft\Async\SynchronousLoop;
use SugarCraft\Code\Media\Sd\SdException;

/**
 * The minutes-long video analogue of
 * {@see \SugarCraft\Code\Media\Sd\ProgressLoop}.
 *
 * Mirrors plan_crush_media.md W5.2 step 4: poll cadence 1 s DEFAULT (video
 * frames cost seconds — the 0.5 s image cadence is wrong here), still
 * heartbeat-per-poll so the 120 s idle watchdog never fires on a healthy
 * render, still NO wall-clock bound (E646 law — the loop is bounded by the
 * job's own lifecycle plus the caller's cancel, never by a stopwatch),
 * still cancel-aware between polls.
 *
 * W5.2 ruling on the plan's "extract a shared loop IF ≥80 % body" test:
 * measured at build time — the shared skeleton (poll → try/catch failure cap
 * → heartbeat → cancel → sleep) is ~35 of ProgressLoop's ~70 loop lines;
 * the remaining mass is preview-frame plumbing, the `wantsPreview`
 * request-shape toggle, ProgressState's co-tenant `finished()` advisory and
 * the Client type itself. JobPollLoop's own mass is the terminal-state
 * machine and the snapshot channel. Forcing a `ProgressSource` interface
 * would widen the IMAGE types (ProgressState gains a job-shaped sibling
 * contract) for no dedup gain under the 80 % bar, so the plan's alternative
 * branch applies: sibling class sharing the doctrine. ProgressLoop's W1.5
 * tests stay green untouched — that is the plan's stated guard for this
 * ruling.
 */
final class JobPollLoop
{
    /** Tolerate a flapping status route exactly like the image loop: 3 consecutive failures give up (bounded). */
    public const MAX_CONSECUTIVE_POLL_FAILURES = 3;

    /**
     * Drive the poll loop to a terminal job state or a caller cancel.
     *
     * @param  callable(int):void  $heartbeat  fired once per poll, BEFORE the sleep (idle-watchdog law)
     * @param  callable():bool  $isCancelled  checked after every heartbeat and after every failure beat
     * @param  (callable(JobSnapshot):void)|null  $onFrame  sees each successfully-decoded snapshot
     * @param  (callable(float):void)|null  $sleeper  injectable for timing-free pins
     *
     * @throws SdException after MAX consecutive status failures, on an unrecognized state word (via JobSnapshot),
     *         or when the interval is non-positive or above Retry::MAX_BACKOFF_SECONDS
     */
    public static function run(
        JobClient $client,
        string $taskId,
        callable $heartbeat,
        callable $isCancelled,
        float $intervalSeconds = JobClient::DEFAULT_POLL_INTERVAL_SECONDS,
        ?callable $sleeper = null,
        ?callable $onFrame = null,
    ): JobPollSummary {
        if ($intervalSeconds <= 0.0) {
            throw SdException::protocol('job-poll-loop', 'poll interval must be positive');
        }
        // Poll::until clamps every pause at Retry::MAX_BACKOFF_SECONDS, so a
        // longer interval would silently sleep the ceiling instead; refuse it.
        if ($intervalSeconds > Retry::MAX_BACKOFF_SECONDS) {
            throw SdException::protocol(
                'job-poll-loop',
                'poll interval must not exceed ' . Retry::MAX_BACKOFF_SECONDS . ' seconds',
            );
        }

        $sleep = $sleeper ?? static function (float $seconds): void {
            usleep(max(1, (int) round($seconds * 1_000_000)));
        };

        $polls = 0;
        $beats = 0;
        $failures = 0;
        $last = null;

        // One poll per attempt; Poll::until paces the attempts on the
        // synchronous loop. Backoff holds whole milliseconds, so each pause is
        // the interval rounded to the nearest ms (floored at 1 ms): a 1.2345 s
        // interval reaches the sleeper as 1.235 s, not the exact float.
        $attempt = static function () use (
            $client,
            $taskId,
            $heartbeat,
            $isCancelled,
            $onFrame,
            &$polls,
            &$beats,
            &$failures,
            &$last,
        ): ?JobPollSummary {
            try {
                $snapshot = $client->status($taskId);
                $failures = 0;
            } catch (SdException $e) {
                $failures++;

                if ($failures >= self::MAX_CONSECUTIVE_POLL_FAILURES) {
                    throw SdException::protocol(
                        'job-poll-loop',
                        "giving up after {$failures} consecutive video job status poll failures; last error: {$e->getMessage()}",
                    );
                }

                // Same doctrine as the image loop: keep the watchdog fed
                // while the status route flaps; cancel still wins before the
                // retry pause.
                $heartbeat();
                $beats++;

                if ($isCancelled()) {
                    return new JobPollSummary($polls, $beats, $last, JobPollSummary::TERMINATION_DONE);
                }

                return null;
            }

            $polls++;
            $last = $snapshot;

            $heartbeat();
            $beats++;

            if ($onFrame !== null) {
                $onFrame($snapshot);
            }

            if ($isCancelled()) {
                return new JobPollSummary($polls, $beats, $last, JobPollSummary::TERMINATION_DONE);
            }

            if ($snapshot->terminal()) {
                return new JobPollSummary($polls, $beats, $last, JobPollSummary::TERMINATION_SERVER_FINISHED);
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
            static function (JobPollSummary $settled) use (&$summary): void {
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

        return $summary ?? throw SdException::protocol('job-poll-loop', 'poll ended without a terminal summary');
    }
}
