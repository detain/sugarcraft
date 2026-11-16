<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Order law (AGENTS.md): LoopPin must be the FIRST thing to touch Loop::get()
// (an already-created shared loop keeps its handles and its shutdown hook;
// pinning afterwards leaves a discarded loop that can run forever at process
// exit), and the watchdog is installed AFTER it. sugar-core's Program runtime
// defaults to Loop::get() (src/Program.php:230) and the suite drives timed
// Programs, so the ordering is stated here even though CI runs no ext-uv (the
// stale-clock trap only exists where Loop::get() autodetects ExtUvLoop).
//
// The call is availability-guarded because sugar-testing is NOT currently a
// declared dependency of this lib and this lane runs no composer: where the
// class is not loadable there is nothing to pin and pinning is a documented
// no-op for the current CI matrix (Linux and macOS install mbstring, intl,
// json, pcntl, ffi — no ext-uv — and Windows never reaches this path with a
// loop). If a later row adds the dev dependency, the pin activates in place,
// in the required order, with no further bootstrap edit.
if (\class_exists(\SugarCraft\Testing\LoopPin::class)) {
    \SugarCraft\Testing\LoopPin::pinStableClock();
}

// CI-wave-4 lane 3: bound every test's wall clock so an unbounded wait in this
// suite can FAIL with a name instead of stalling the macOS job to its 30-minute
// CI cap with none — three consecutive runs cancelled at exactly 35:00 in step
// 8, test unidentifiable because cancelled-macOS-job log blobs 404 within
// minutes (/tmp/opencode/ciwatch-1116/TRIAGE4.md). This is a port of
// sugar-pty's E490 instrument; the hypothesis it converts into evidence is the
// arm64 TIOCSWINSZ FFI hold-out (scripts/affected-libs.php:35-53) reached via
// PosixBackend's descriptor legs. On a green run the watchdog is dormant by
// construction: heartbeats are written and fresh, and it exits quietly when the
// runner does. Opt out with SUGAR_CORE_HANG_BUDGET=0; the budget and its
// measurement basis live on HangWatchdog::DEFAULT_BUDGET_SEC.
\SugarCraft\Core\Tests\Support\HangWatchdog::install();
