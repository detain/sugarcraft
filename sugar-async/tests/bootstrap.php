<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// AsyncOpsTest bounds nearly every one of its waits with a 0.05s–0.5s timer
// armed on the shared Loop::get() — which is ExtUvLoop wherever ext-uv is
// installed, and so computes deadlines against a clock refreshed only once per
// loop iteration. Every timer armed while that clock is N seconds stale is
// short by N, so a batch of them comes due together the moment the loop finally
// runs.
//
// Uniform staleness is mostly survivable, and it is worth being exact about
// why, because "the safety net fires before the work" is NOT what happens to
// most of this suite: the batch still fires in DEADLINE order, so a test whose
// under-test timer was armed pre-run alongside its own bound still does its
// work first. Measured per-test in isolation against a loop created at
// bootstrap and left idle before the arm (probe: `Loop::get(); usleep(N)`), at
// N = 0.6 / 1 / 3 / 10s: testDebounceOnlyLastCallFires and
// testThrottleLimitsCallFrequency PASS at every one of those, up to and
// including a ten-second stale clock. An arm log confirms the reason rather
// than luck — at N=10s debounce's live 0.05s timer and its 0.1s bound both go
// in before run() and both fire within 3.6ms of it, in that order, so the
// assertion is already satisfied when the bound stops the loop.
//
// The real exposure is narrower: a test whose remaining work depends on a timer
// armed AFTER the loop starts running. Running each of AsyncOpsTest's 22 tests
// alone against a 3s-stale loop, exactly two fail — testRetryRetriesOnFailure
// and testRetryFailsAfterMaxAttempts, the only two that need a SECOND retry
// attempt. Retry's first attempt rejects synchronously, so backoff #1 (0.01s)
// and the 0.5s bound both go in pre-run and both are short by N; backoff #1
// wins the batch, but the attempt it triggers arms backoff #2 (0.02s) from
// inside that callback — against a clock the poll has since refreshed, so #2
// gets a genuinely future deadline while the bound is still on the stale
// reckoning. The bound fires ~5ms later, run() returns, and the third attempt
// never happens: the first fails "null is identical to 'success'"
// (AsyncOpsTest.php:221), the second "null is an instance of class
// RuntimeException" (:250) — the pair in 0.039s of wall time.
//
// Today the suite runs in well under a second and the ten gaps between a run()
// exit and the next arm are all under 4ms (0.0004-0.0034s across three runs
// here), i.e. three orders of magnitude below the threshold below — quote the
// margin rather than a band, because a band this tight is a one-run artifact.
// So nothing comes near the threshold and the flake stays latent: an unpinned
// run is green, which is why this pin is preventive rather than a repair.
//
// One slow test away from waking up is measurable, though, not asserted:
// injecting idle before testRetryRetriesOnFailure's arm in isolation, it passes
// at 0.35 / 0.40 / 0.42s and fails at 0.45 / 0.48s, three runs each, no
// straddling. Injected idle is not the quantity that matters — PHPUnit start-up
// sits between the bootstrap and the arm, so the arm-timing log puts the real
// staleness at 0.4605s (passing) and 0.4916s (failing). The flip lands where
// staleness reaches the 0.5s bound LESS the delay of the backoff armed after
// run() enters, i.e. 0.5 - 0.02 = 0.48s here, which is the general rule: a
// retry-shaped test is exposed once the idle before its arm approaches its own
// bound. Under this pin the same test passes at every idle from 0.05s to 10s.
//
// See \SugarCraft\Testing\LoopPin for the mechanism.
\SugarCraft\Testing\LoopPin::pinStableClock();
