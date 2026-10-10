<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Video;

use SugarCraft\Crush\Media\Sd\SdException;

/**
 * The minutes-long video analogue of
 * {@see \SugarCraft\Crush\Media\Sd\ProgressLoop}.
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
     * @throws SdException after MAX consecutive status failures, or on an unrecognized state word (via JobSnapshot)
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

        $sleep = $sleeper ?? static function (float $seconds): void {
            usleep(max(1, (int) round($seconds * 1_000_000)));
        };

        $polls = 0;
        $beats = 0;
        $failures = 0;
        $last = null;

        while (true) {
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
                // retry sleep.
                $heartbeat();
                $beats++;

                if ($isCancelled()) {
                    return new JobPollSummary($polls, $beats, $last, JobPollSummary::TERMINATION_DONE);
                }

                $sleep($intervalSeconds);

                continue;
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

            $sleep($intervalSeconds);
        }
    }
}
