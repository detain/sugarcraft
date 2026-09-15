<?php

declare(strict_types=1);

/*
 * E722 (round 82) child harness for FfmpegDecoderReadinessTest.
 *
 * Drives ONE FfmpegDecoder against a black-hole HTTP listener (an accepted
 * TCP connection that never answers), so ffmpeg sits alive-and-silent inside
 * its input read — the exact "live but produces nothing" wedge the decoder's
 * pump contract says the CALLER must bound. The harness proves, in order:
 *
 *   1. the child is ALIVE (and stays alive) while emitting nothing;
 *   2. the stdout pipe reports NOT ready under a zero-timeout stream_select
 *      (the hazard is real and, crucially, OBSERVABLE without parking);
 *   3. close() returns within the BoundedReaper ceiling and the child ends
 *      dead — the escalation reaches even a wedged ffmpeg.
 *
 * It is run under `timeout -s KILL` by the parent so a mutation that strips
 * the ladder turns this script's DONE marker missing (red-fast), never a
 * suite-wide hang (r69 law: loops that can only hang must be leashed).
 * The wedged ffmpeg's pid is published to a pidfile FIRST so the parent can
 * force-kill it even when this process itself gets KILLed — hermetic under
 * every mutation.
 *
 * argv: <autoload> <url> <pidfile>
 */

$autoload = $argv[1] ?? exit("usage: harness <autoload> <url> <pidfile>\n");
$url = $argv[2] ?? exit("usage: harness <autoload> <url> <pidfile>\n");
$pidFile = $argv[3] ?? exit("usage: harness <autoload> <url> <pidfile>\n");

require $autoload;

use SugarCraft\Reel\Decode\FfmpegDecoder;

$decoder = new FfmpegDecoder();
$decoder->open($url, 8, 6, 10.0);

// Publish the child pid before anything that can die, so the parent's
// teardown always has a handle on the wedged ffmpeg.
$processProperty = new ReflectionProperty(FfmpegDecoder::class, 'process');
$processProperty->setAccessible(true);
$process = $processProperty->getValue($decoder);
file_put_contents($pidFile, (string) proc_get_status($process)['pid']);

// Read the decoder's stdout pipe through reflection — this is the fd next()
// would park on. We only ever ask select about it; we never fread it here.
$stdoutProperty = new ReflectionProperty(FfmpegDecoder::class, 'stdout');
$stdoutProperty->setAccessible(true);
$stdout = $stdoutProperty->getValue($decoder);

// Let ffmpeg establish the connection and settle into its input read. A
// child that dies during this window reports CHILD_DIED=1 so the parent
// fails loudly instead of mis-reading an EOF-selectable pipe as "ready".
usleep(250_000);
$alive = (bool) proc_get_status($process)['running'];
echo 'CHILD_RUNNING=' . (int) $alive . "\n";
if (!$alive) {
    echo "CHILD_DIED=1\n";
    exit(3);
}

$read = [$stdout];
$write = null;
$except = null;
$ready = @stream_select($read, $write, $except, 0, 0);
echo 'SELECT_READY=' . (int) ($ready === 1) . "\n";

$start = microtime(true);
$decoder->close();
$elapsed = microtime(true) - $start;

// After a reaped proc_close the kernel pid is gone; /proc/<pid> is the
// ground truth that close() took the child all the way DOWN, not just
// past its own bookkeeping.
echo 'CLOSE_SECONDS=' . number_format($elapsed, 3, '.', '') . "\n";
echo 'EXIT_CODE=' . var_export($decoder->getExitCode(), true) . "\n";
echo 'CHILD_ALIVE_AFTER_CLOSE=' . (int) is_dir('/proc/' . (int) file_get_contents($pidFile)) . "\n";
echo "DONE\n";
