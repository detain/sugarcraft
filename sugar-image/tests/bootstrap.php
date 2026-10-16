<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The async image-source tests bound every fetch with a 5s safety timer armed
// on the shared Loop::get() — ExtUvLoop wherever ext-uv is installed, which
// computes a deadline against a clock refreshed only once per loop iteration.
// A timer's error is the wall time between that last refresh and the arm, so
// what decides this suite is the run of non-loop tests before the first arm.
//
// That is a THRESHOLD, not a certainty, and the pin is here because the margin
// is thin — not because a failure was demonstrated. The SHAPE, measured on this
// host over 21 unpinned runs (PHP 8.3 + ext-uv, a lazily-constructed
// pass-through LoopInterface decorator over ExtUvLoop logging every arm and
// every run(); "gap" = the loop's last run() exit to the first arm of a 5s
// cap):
//
//   last loop iteration  AsyncRendererTest::testWithAsyncAcceptsCustom
//                        Renderer, test #35 of 449, returning from run()
//                        under a second in
//   first arm            ImageSourceAsyncSsrfTest::testAsyncBlocksRedirectHop
//                        ToMetadataIp, test #173, arming ~4.6-5.5s in
//   gap                  ~4s, leaving ~1s of headroom under the 5s cap
//
// Deliberately no per-run timestamps: the gap is the wall time of the 137 tests
// in between, and it moves. Over those 21 runs the headroom spanned 0.26-1.06s,
// i.e. 5-21% of the cap — 20 runs landed in 0.74-1.06s and one collapsed to
// 0.26s with no code change at all. A second, independent 21-run sample on this
// same host spanned 0.86-1.15s (17-23%) and never reproduced the 0.26s outlier
// while overshooting the first sample's ceiling five times. Two samples on one
// quiet machine disagreeing at BOTH ends is the point: every earlier revision of
// this comment quoted a tight four-run band that a fresh run here misses most of
// the time, so the durable form is the shape plus the recipe to re-derive it,
// never the band.
//
// Both endpoints have to be read off the loop, not off the test list, because
// neither is the test you would guess. The three SSRF tests BEFORE #173 (#170
// -#172) reject in the pre-flight guard, so their promises settle before the
// loop is ever entered and ImageSourceAsyncSsrfTest::await()'s `if (!$settled)`
// check skips both the addTimer() and the run() — they arm nothing. That guard
// is load-bearing for the suite's wall time as well: the same three cases run
// through ImageSourceUrlTest::await(), which has no such check, would each sit
// on the loop for the full cap (measured here: 0.001s for three guarded awaits
// of an already-rejected promise against 5.02s for one unguarded one).
// At the other end, #36 (testWithAsyncCreatesNewInstance) builds a Mosaic,
// loads a PNG fixture, constructs an AdaptiveImage and asserts withAsync()
// returned a copy — five statements, none of which touch the loop; #35 is the
// last test that actually iterates it.
//
// All 21 unpinned runs were green and no unpinned failure has been reproduced
// here, so the claim is only this: the slack is around a second, one of the 21
// runs already spent three quarters of it for free, and the first cap past the
// threshold fires on its own first tick — failing its test with "did not settle
// within timeout" having consumed no wall time. Re-measure the gap rather than
// trusting any figure here, and re-derive the two endpoint tests with it, since
// inserting one test that runs the loop moves them.
// See \SugarCraft\Testing\LoopPin for the mechanism and
// \SugarCraft\Core\Program::run() for the timer measurements.
\SugarCraft\Testing\LoopPin::pinStableClock();
