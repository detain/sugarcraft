<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;

/**
 * The sink's own behaviour, backend by backend.
 *
 * {@see RuntimeNoticeSinkDeliveryTest} is the one that matters — it drives the
 * whole path from a real parser in a forked child to a row in a real Chat's
 * `view()`. This file is the unit half: the clip, the caps, the de-duplication
 * window, and the two things a static must not do (leak between cases, leak
 * file descriptors).
 */
final class RuntimeNoticeSinkTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        // BOTH ENDS. A static sink that survives a test case makes one test's
        // warning another test's assertion, and — on the transport backend —
        // two fds per case across a suite this size is an fd table.
        RuntimeNoticeSink::reset();
    }

    public function testAnEmptySinkHasNothingPendingAndDrainsToNothing(): void
    {
        RuntimeNoticeSink::arm(false);

        self::assertFalse(RuntimeNoticeSink::hasPending());
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testTheInProcessBackendRoundTripsANotice(): void
    {
        RuntimeNoticeSink::arm(false);

        self::assertTrue(RuntimeNoticeSink::record('a parser refused a call'));
        self::assertTrue(RuntimeNoticeSink::hasPending());
        self::assertSame(['a parser refused a call'], RuntimeNoticeSink::drain());
        self::assertFalse(RuntimeNoticeSink::hasPending());
    }

    public function testAnEmptyOrWhitespaceNoticeIsRefusedRatherThanQueuedBlank(): void
    {
        RuntimeNoticeSink::arm(false);

        self::assertFalse(RuntimeNoticeSink::record(''));
        self::assertFalse(RuntimeNoticeSink::record("  \n\t "));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testDrainDeDuplicatesWithinTheBatchAndKeepsTheFirst(): void
    {
        RuntimeNoticeSink::arm(false);

        RuntimeNoticeSink::record('duplicate parameter "path"');
        RuntimeNoticeSink::record('an invoke was refused');
        RuntimeNoticeSink::record('duplicate parameter "path"');

        self::assertSame(
            ['duplicate parameter "path"', 'an invoke was refused'],
            RuntimeNoticeSink::drain(),
        );
    }

    public function testDrainDoesNotDeDuplicateAcrossBatches(): void
    {
        RuntimeNoticeSink::arm(false);

        // The same warning on turn 1 and on turn 50 is two events; collapsing
        // them would tell the user the second turn was clean.
        RuntimeNoticeSink::record('the same complaint');
        self::assertSame(['the same complaint'], RuntimeNoticeSink::drain());
        RuntimeNoticeSink::record('the same complaint');
        self::assertSame(['the same complaint'], RuntimeNoticeSink::drain());
    }

    public function testTheInProcessBackendCapsAtTheLimitAndReportsTheOverflowAsARow(): void
    {
        RuntimeNoticeSink::arm(false);

        for ($i = 0; $i < RuntimeNoticeSink::NOTICE_LIMIT + 3; $i++) {
            $accepted = RuntimeNoticeSink::record("notice {$i}");
            self::assertSame($i < RuntimeNoticeSink::NOTICE_LIMIT, $accepted, "notice {$i}");
        }

        $drained = RuntimeNoticeSink::drain();

        self::assertCount(RuntimeNoticeSink::NOTICE_LIMIT + 1, $drained);
        self::assertSame(
            sprintf(RuntimeNoticeSink::OVERFLOW_FORMAT, 3, 's'),
            $drained[RuntimeNoticeSink::NOTICE_LIMIT],
        );
    }

    public function testASingleOverflowUsesTheSingularForm(): void
    {
        RuntimeNoticeSink::arm(false);

        for ($i = 0; $i < RuntimeNoticeSink::NOTICE_LIMIT + 1; $i++) {
            RuntimeNoticeSink::record("notice {$i}");
        }

        $drained = RuntimeNoticeSink::drain();

        self::assertSame(
            sprintf(RuntimeNoticeSink::OVERFLOW_FORMAT, 1, ''),
            $drained[RuntimeNoticeSink::NOTICE_LIMIT],
        );
    }

    /**
     * THE NAME THIS CARRIED WAS THE OPPOSITE OF WHAT IT ASSERTS, and the body
     * was the half that was right. WHAT IT SAID: "an overflow alone is enough
     * to make the sink pending". WHAT IS TRUE NOW, derived rather than argued:
     * that state is UNREACHABLE through the public API. `record()` increments
     * the dropped counter only once the queue has reached
     * {@see RuntimeNoticeSink::NOTICE_LIMIT}, and the only thing that empties
     * the queue is `drain()`, which zeroes the counter in the same breath — so
     * `dropped > 0 && queue === []` cannot occur, and `hasPending()`'s
     * `|| self::$dropped > 0` clause is defensive rather than live. MEASURED by
     * deleting that clause: this whole file stays green. It is kept under rule
     * 6 and documented here rather than removed, because it is the clause that
     * starts mattering the moment anything other than `drain()` clears the
     * queue.
     *
     * WHAT THIS TEST ACTUALLY PINS, which is worth pinning: the overflow row is
     * SYNTHESISED at drain and stored nowhere, so a second drain must not
     * repeat it. A counter left standing would put "… and N more" into the
     * transcript on every tick for the rest of the session, and every one of
     * those rows is sent to the model again on the next turn.
     */
    public function testDrainClearsTheOverflowCounterSoTheRowIsNotRepeated(): void
    {
        RuntimeNoticeSink::arm(false);

        for ($i = 0; $i < RuntimeNoticeSink::NOTICE_LIMIT + 1; $i++) {
            RuntimeNoticeSink::record("notice {$i}");
        }

        $first = RuntimeNoticeSink::drain();
        self::assertSame(
            sprintf(RuntimeNoticeSink::OVERFLOW_FORMAT, 1, ''),
            $first[RuntimeNoticeSink::NOTICE_LIMIT],
        );

        self::assertFalse(RuntimeNoticeSink::hasPending());
        self::assertSame([], RuntimeNoticeSink::drain());

        // And a later, honest notice arrives with no tail glued to it.
        RuntimeNoticeSink::record('a later, unrelated warning');
        self::assertSame(['a later, unrelated warning'], RuntimeNoticeSink::drain());
    }

    public function testALongNoticeIsClippedToTheBudgetWithTheSuffixCountedIn(): void
    {
        RuntimeNoticeSink::arm(false);

        RuntimeNoticeSink::record(str_repeat('x', RuntimeNoticeSink::MAX_CHARS * 2));

        $drained = RuntimeNoticeSink::drain();

        self::assertCount(1, $drained);
        self::assertSame(RuntimeNoticeSink::MAX_CHARS, mb_strlen($drained[0], 'UTF-8'));
        self::assertStringEndsWith(RuntimeNoticeSink::CLIP_SUFFIX, $drained[0]);
    }

    public function testTheClipNeverCutsACodepointInHalf(): void
    {
        RuntimeNoticeSink::arm(false);

        // json_encode() — the session store, the `-p` document — refuses
        // invalid UTF-8 outright rather than degrading, so a byte-wise cut
        // would take the transcript down rather than shorten a row.
        RuntimeNoticeSink::record(str_repeat('é', RuntimeNoticeSink::MAX_CHARS * 2));

        $drained = RuntimeNoticeSink::drain();

        self::assertNotFalse(mb_check_encoding($drained[0], 'UTF-8'));
        self::assertNotFalse(json_encode($drained[0]));
    }

    public function testAMessageExactlyAtTheBudgetIsNotClipped(): void
    {
        RuntimeNoticeSink::arm(false);

        $exact = str_repeat('y', RuntimeNoticeSink::MAX_CHARS);
        RuntimeNoticeSink::record($exact);

        self::assertSame([$exact], RuntimeNoticeSink::drain());
    }

    public function testWarnPutsTheMessageOnBothChannels(): void
    {
        RuntimeNoticeSink::arm(false);

        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_notice_sink_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            RuntimeNoticeSink::warn('a provider degraded to echo');

            self::assertSame(['a provider degraded to echo'], RuntimeNoticeSink::drain());
            self::assertStringContainsString(
                'a provider degraded to echo',
                (string) file_get_contents($log),
            );
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            @unlink($log);
        }
    }

    public function testTheStderrCopyIsUnclippedEvenWhenTheTranscriptRowIsNot(): void
    {
        RuntimeNoticeSink::arm(false);

        // The whole argument for keeping both channels: stderr is the COMPLETE
        // record and costs no model tokens, which is what makes the clip safe
        // to advertise in CLIP_SUFFIX.
        $long = str_repeat('z', RuntimeNoticeSink::MAX_CHARS * 2);
        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_notice_sink_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            RuntimeNoticeSink::warn($long);

            $drained = RuntimeNoticeSink::drain();
            self::assertSame(RuntimeNoticeSink::MAX_CHARS, mb_strlen($drained[0], 'UTF-8'));
            self::assertStringContainsString($long, (string) file_get_contents($log));
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            @unlink($log);
        }
    }

    public function testArmingIsIdempotentAndKeepsTheFirstTransport(): void
    {
        self::assertFalse(RuntimeNoticeSink::isArmed());
        self::assertFalse(RuntimeNoticeSink::hasTransport());
        self::assertTrue(RuntimeNoticeSink::arm());
        self::assertTrue(RuntimeNoticeSink::isArmed());
        self::assertTrue(RuntimeNoticeSink::hasTransport());
        // A second chat() in one process must keep the first pair rather than
        // orphan two fds.
        self::assertTrue(RuntimeNoticeSink::arm());
        self::assertTrue(RuntimeNoticeSink::hasTransport());
    }

    /**
     * ARMING FOR THE IN-PROCESS BACKEND IS A DECISION, NOT A FAILURE: the
     * return value says WHICH BACKEND you got, not whether the call worked.
     *
     * This row is what keeps the array backend from being dormant code (rule
     * 6). Every real interactive launch takes the transport, so without a
     * caller that asks for the other one nothing would ever walk it.
     */
    public function testArmingWithoutTheCrossForkTransportStillOpensTheInbox(): void
    {
        self::assertFalse(RuntimeNoticeSink::arm(false), 'arm(false) claimed to have made a transport');
        self::assertTrue(RuntimeNoticeSink::isArmed());
        self::assertFalse(RuntimeNoticeSink::hasTransport());

        self::assertTrue(RuntimeNoticeSink::record('through the array backend'));
        self::assertSame(['through the array backend'], RuntimeNoticeSink::drain());

        // And it is sticky: a later arm() must not upgrade an armed sink to a
        // transport behind the caller's back, because the fork that transport
        // would have to precede may already have happened.
        self::assertFalse(RuntimeNoticeSink::arm());
        self::assertFalse(RuntimeNoticeSink::hasTransport());
    }

    /**
     * AN UNARMED SINK DROPS, AND {@see RuntimeNoticeSink::warn()} STILL WRITES
     * ITS STDERR HALF.
     *
     * The `-p` one-shot's shape, and the reason the gate exists: nothing in
     * that process will ever build a {@see \SugarCraft\Crush\Chat}, so a
     * queued row is a row lost with extra steps. See the class doc-block for
     * the measurement of what the ungated version did to this very suite.
     */
    public function testAnUnarmedSinkDropsTheNoticeButKeepsTheStderrCopy(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_notice_unarmed_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            self::assertFalse(RuntimeNoticeSink::isArmed());
            self::assertFalse(RuntimeNoticeSink::record('nobody armed the inbox'));
            RuntimeNoticeSink::warn('and this one went out through warn()');

            self::assertFalse(RuntimeNoticeSink::hasPending());
            self::assertSame([], RuntimeNoticeSink::drain());

            self::assertStringContainsString(
                'and this one went out through warn()',
                (string) file_get_contents($log),
                'the forensic half of warn() stopped working when the transcript half was gated',
            );
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            @unlink($log);
        }
    }

    /** Reset disarms, so a torn-down sink drops until something arms it again. */
    public function testResetDisarms(): void
    {
        RuntimeNoticeSink::arm(false);
        self::assertTrue(RuntimeNoticeSink::isArmed());

        RuntimeNoticeSink::reset();

        self::assertFalse(RuntimeNoticeSink::isArmed());
        self::assertFalse(RuntimeNoticeSink::record('after the teardown'));
    }

    public function testTheTransportBackendRoundTripsWithinOneProcess(): void
    {
        RuntimeNoticeSink::arm();

        self::assertFalse(RuntimeNoticeSink::hasPending());
        self::assertTrue(RuntimeNoticeSink::record('recorded through the socket'));
        self::assertTrue(RuntimeNoticeSink::hasPending());
        self::assertSame(['recorded through the socket'], RuntimeNoticeSink::drain());
        self::assertFalse(RuntimeNoticeSink::hasPending());
    }

    public function testTheTransportBackendPreservesOrderAndDeDuplicatesTheBatch(): void
    {
        RuntimeNoticeSink::arm();

        RuntimeNoticeSink::record('first');
        RuntimeNoticeSink::record('second');
        RuntimeNoticeSink::record('first');

        self::assertSame(['first', 'second'], RuntimeNoticeSink::drain());
    }

    public function testResetDropsTheTransportSoTheNextInstallIsFresh(): void
    {
        RuntimeNoticeSink::arm();
        RuntimeNoticeSink::record('will not survive');
        RuntimeNoticeSink::reset();

        self::assertFalse(RuntimeNoticeSink::hasTransport());
        self::assertFalse(RuntimeNoticeSink::hasPending());
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testTheTransportRefusesRatherThanBlockingWhenNothingDrainsIt(): void
    {
        // MEASURED on PHP 8.3.6 / Linux 6.8: the kernel send buffer took 167
        // datagrams of 500 bytes and then refused, with no diagnostic and no
        // block. The exact number is a kernel tunable and is NOT asserted; what
        // is asserted is the only property the design depends on — that the
        // refusal is a return value and the call returns.
        RuntimeNoticeSink::arm();

        $accepted = 0;
        $started = microtime(true);
        for ($i = 0; $i < 100000; $i++) {
            if (!RuntimeNoticeSink::record(str_repeat('q', 500))) {
                break;
            }
            $accepted++;
        }
        $elapsed = microtime(true) - $started;

        self::assertGreaterThan(0, $accepted, 'the transport accepted nothing at all');
        self::assertLessThan(100000, $accepted, 'the transport never refused, so it is not bounded');
        self::assertLessThan(10.0, $elapsed, 'a refused write blocked instead of returning');
    }

    public function testAWriteAfterTheReaderIsGoneRaisesNothingPhpWouldPrint(): void
    {
        // MEASURED, PHP 8.3.6 / Linux 6.8: an fwrite() to a dgram pair whose
        // read end is closed raises `Send of N bytes failed with errno=111
        // Connection refused`. That diagnostic goes to fd 2 — the precise
        // corruption this class exists to stop — so the write is `@fwrite()`.
        //
        // WHAT `@` ACTUALLY DOES, because the first draft of this test asserted
        // the wrong thing and went red. It does NOT stop a custom
        // `set_error_handler()` from being CALLED — PHPUnit installs one, so
        // does any application error reporter — it narrows `error_reporting()`
        // for the duration of the expression. A conforming handler (and PHP's
        // default one, which is what a production launch has) therefore prints
        // nothing. So the property to assert is SUPPRESSION, not silence.
        //
        // TWO FIGURES IN THIS PARAGRAPH WERE WRONG AND ARE CORRECTED HERE,
        // both re-measured on PHP 8.3.6 / Linux 6.8 by the generator below.
        // WHAT IT SAID: "inside the handler `error_reporting()` is 4437 under
        // `@` and 22527 without, and `4437 & E_WARNING === 0`."
        // WHAT IS TRUE NOW, and what was already true when it was written:
        //   - under `@` it is 4437. That half stands.
        //   - WITHOUT `@` it is 32767, i.e. `E_ALL`, NOT 22527. 22527 is
        //     `ini_get('error_reporting')` on this box — the AMBIENT mask, the
        //     very thing the `error_reporting(E_ALL)` pin four lines below
        //     exists to take out of the comparison. The old figure was measured
        //     before that pin, so it described a test that no longer runs.
        //   - the diagnostic a dead-datagram `fwrite()` raises is `E_NOTICE`
        //     (8), NOT `E_WARNING` (2). The conclusion is unchanged —
        //     `4437 & 8 === 0` as well — but naming the wrong level is how the
        //     next reader "fixes" the mask and takes the guard with it.
        // WHY THE PARAGRAPH STILL EARNS ITS PLACE: the mechanism it explains is
        // the reason the assertion is on `suppressed` and not on an absence of
        // output, and getting that backwards is what reddened the first draft.
        // GENERATOR (no PHPUnit, so nothing narrows the mask for you): open a
        // dgram pair, `fclose()` the read end, install a handler that records
        // `$errno` and `error_reporting()`, then do one `@fwrite()` and one
        // bare `fwrite()` on the write end.
        //
        // THE CONTROL IS IN THE SAME TEST, on purpose: an unsuppressed write is
        // driven through the identical handler and must come back UNsuppressed.
        // Without it, a handler that never fired would read as a pass.
        RuntimeNoticeSink::arm();

        $seen = [];
        // PINNED TO E_ALL FOR THE DURATION, and the control below is what
        // found the need: PHPUnit runs the suite with `error_reporting()`
        // already narrowed, so BOTH writes came back "suppressed" and the test
        // would have passed with the `@` deleted. Comparing against an ambient
        // mask you do not control is not a comparison.
        $ambient = error_reporting(E_ALL);
        set_error_handler(static function (int $no, string $message) use (&$seen): bool {
            $seen[] = ['errno' => $no, 'suppressed' => (error_reporting() & $no) === 0];

            return true;
        });

        try {
            $reader = (new \ReflectionClass(RuntimeNoticeSink::class))->getProperty('transportRead');
            $handle = $reader->getValue();
            self::assertIsResource($handle);
            $writer = (new \ReflectionClass(RuntimeNoticeSink::class))->getProperty('transportWrite');
            $rawWrite = $writer->getValue();
            fclose($handle);

            self::assertFalse(RuntimeNoticeSink::record('nobody is listening'));

            // KNOWN-POSITIVE CONTROL through the same handler and the same fd.
            fwrite($rawWrite, 'unsuppressed control');
        } finally {
            restore_error_handler();
            error_reporting($ambient);
            RuntimeNoticeSink::reset();
        }

        self::assertCount(2, $seen, 'the handler did not see both writes; the probe is broken');
        self::assertTrue($seen[0]['suppressed'], "record()'s write was NOT suppressed — the `@` is gone");
        self::assertFalse($seen[1]['suppressed'], 'the control was suppressed too; this test proves nothing');
    }

    public function testTheLongestNoticeThisParserActuallyEmitsIsMeasuredByEmittingIt(): void
    {
        // MAX_CHARS' doc-block claims DsmlToolCallParser's no-positioned-
        // envelope diagnostic is the longest message routed here and that it IS
        // clipped. Both halves are DERIVED — by running the parser and reading
        // what it wrote — rather than written into prose that a rewording would
        // leave stale (rule 18). An earlier draft scraped single-quoted
        // literals out of the source instead and reported 2018, because a
        // parser file is full of long literals that are not messages: a scan
        // whose alphabet cannot tell those apart is not a measurement.
        $log = tempnam(sys_get_temp_dir(), 'sc_lane_a_notice_len_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $token = "\u{FF5C}DSML\u{FF5C}";
            $parser = \SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser::new();
            // The no-positioned-envelope path: the prefilter matches, and the
            // positional scan judges the one occurrence to be inside a fence.
            $parser->parse(['content' => "here is the format:\n```\n<{$token}tool_calls>\n</{$token}tool_calls>\n```\n"]);
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        @unlink($log);

        self::assertNotSame([], $lines, 'the parser emitted nothing — the fixture no longer triggers it');

        $longest = 0;
        foreach ($lines as $line) {
            // Strip error_log()'s own `[date] ` envelope; it is not part of the
            // message the sink would clip.
            $message = (string) preg_replace('/^\[[^\]]+\] /', '', $line);
            $longest = max($longest, mb_strlen($message, 'UTF-8'));
        }

        self::assertGreaterThan(
            RuntimeNoticeSink::MAX_CHARS,
            $longest,
            "MAX_CHARS' doc-block says this message IS clipped; it now fits, so rewrite that paragraph",
        );
        self::assertLessThan(
            RuntimeNoticeSink::MAX_CHARS * 3,
            $longest,
            'a routed message has grown past three times the budget; re-read MAX_CHARS',
        );
    }
}
