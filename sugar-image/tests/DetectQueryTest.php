<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Capability;
use SugarCraft\Image\CellSize;
use SugarCraft\Image\Detect;
use SugarCraft\Image\TermDb;

/**
 * F-08: hermetic pins for the OSC 10/11 default fg/bg query stage —
 * {@see Detect::probeDefaultColors()}, its parser, the reply-shape
 * terminators, and the finishProbe single-exit wiring that carries the
 * answers onto every probe path.
 *
 * Everything here runs against injected stream pairs (the setProbeStdin
 * seam) or injected statics — no real tty, no tmux, no terminfo. Reply
 * bytes are the shapes chafa models (chafa-term-db.c:248-253, narrowing
 * at chafa-term.c:403-409); the '#rrggbb' spelling some emulators answer
 * with is deliberately NOT modelled (triplet-only parse, chafa parity).
 */
final class DetectQueryTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    /** @var list<array{read: resource, write: resource}> */
    private array $pairs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Same env neutrality as DetectTest: the ladder must never consult
        // a leaked KONSOLE/GHOSTTY/TMUX environment during these pins.
        foreach (TermDb::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::setTerminfoSixelOverride(false);
        Detect::setProbeStdin(null);
        unset($GLOBALS['__candy_non_interactive']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        foreach ($this->pairs as $pair) {
            @fclose($pair['read']);
            @fclose($pair['write']);
        }
        $this->pairs = [];
        Detect::reset();
        Detect::setProbeStdin(null);
        Detect::clearTmuxTermFeaturesOverride();
        Detect::clearTerminfoSixelOverride();
        unset($GLOBALS['__candy_non_interactive']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Terminator polarity (the `?`-guard, static leg)
    // -------------------------------------------------------------------------

    /**
     * ISS-048 polarity applied to the OSC reply space: the terminators match
     * REPLY shapes only — neither the query bytes (echo) nor typed text can
     * ever satisfy them.
     */
    public function testTheOscReplyTerminatorsMatchRepliesNotQueries(): void
    {
        $replies10 = [
            "\x1b]10;rgb:1a/2b/3c\x1b\\",
            "\x1b]10;rgb:1a1a/2b2b/3c3c\x1b\\",
            "\x1b]10;rgb:FF/00/EE\x07",
        ];
        foreach ($replies10 as $reply) {
            $this->assertSame(1, preg_match(Detect::OSC10_REPLY_TERMINATOR, $reply), 'OSC10 rejected ' . $this->escape($reply));
            $this->assertSame(0, preg_match(Detect::OSC11_REPLY_TERMINATOR, $reply), 'OSC10 reply crossed into the OSC11 terminator');
        }

        $replies11 = [
            "\x1b]11;rgb:8080/0000/ffff\x1b\\",
            "\x1b]11;rgb:0/0/0\x07",
        ];
        foreach ($replies11 as $reply) {
            $this->assertSame(1, preg_match(Detect::OSC11_REPLY_TERMINATOR, $reply), 'OSC11 rejected ' . $this->escape($reply));
            $this->assertSame(0, preg_match(Detect::OSC10_REPLY_TERMINATOR, $reply), 'OSC11 reply crossed into the OSC10 terminator');
        }

        // Queries (echo), typed text, and near-miss shapes must never read
        // as replies — the ';?' the queries carry and the 'rgb:' the
        // replies carry are mutually exclusive by construction.
        $notReplies = [
            "\x1b]10;?\x1b\\",
            "\x1b]11;?\x1b\\",
            "\x1b]10;?\x1b\\\x1b]11;?\x1b\\",
            "hello\x1b]10;rgb:1a/2b\x07",       // only two components
            "\x1b]10;RGB:1a/2b/3c\x1b\\",         // prefix is case-strict (chafa sscanf literal)
            "\x1b]10;#ffffff\x1b\\",              // '#rrggbb' shape deliberately unmodelled
            "\x1b]10;rgb:zz/00/00\x1b\\",          // not hex
            'plain text',
        ];
        foreach ($notReplies as $bytes) {
            $this->assertSame(0, preg_match(Detect::OSC10_REPLY_TERMINATOR, $bytes), 'OSC10 accepted non-reply ' . $this->escape($bytes));
            $this->assertSame(0, preg_match(Detect::OSC11_REPLY_TERMINATOR, $bytes), 'OSC11 accepted non-reply ' . $this->escape($bytes));
        }
    }

    // -------------------------------------------------------------------------
    // Reply parsing (behaviour leg of the `?`-guard + chafa narrowing)
    // -------------------------------------------------------------------------

    public function testACompleteReplyPairNarrowsToRrggbb(): void
    {
        [$fg, $bg] = $this->colorsFrom($this->preload(
            "\x1b]10;rgb:1a1a/2b2b/3c3c\x1b\\" . "\x1b]11;rgb:8080/0000/ffff\x1b\\",
        ));

        // 16-bit components divided by 256, exactly chafa's
        // probe_color_to_packed_rgb arithmetic (chafa-term.c:403-409):
        // 0x1a1a/256 = 0x1a, 0x8080/256 = 0x80, 0xffff/256 = 0xff.
        $this->assertSame('1a2b3c', $fg);
        $this->assertSame('8000ff', $bg);
    }

    public function testNarrowComponentsAreTakenAsEightBitsDirectly(): void
    {
        [$fg, $bg] = $this->colorsFrom($this->preload(
            "\x1b]10;rgb:1a/2b/3c\x1b\\" . "\x1b]11;rgb:ff/0/ee\x07",
        ));

        // Narrow replies ride the XTerm/Xcms replication scale: '1a' and
        // 'ff' replicate to '1a1a'/'ffff' whose high bytes read back as the
        // 8-bit answers chafa's /256 would give; '0' pads to '00'.
        $this->assertSame('1a2b3c', $fg);
        $this->assertSame('ff00ee', $bg);
    }

    /**
     * The replication leg the earlier WIP broke: a single digit means
     * full intensity replicated ('f' → 'ff', not '0f') and three digits
     * replicate cyclically ('123' → '1231' → '12', not intdiv 0x123/256
     * = '01'). No real terminal in the chafa evidence replies narrower
     * than 2 digits — this legacy snapshot carries no OSC parser to
     * parity-check the leg — so the XTerm rule is cited as the authority.
     */
    public function testOddDigitComponentsReplicateToTheXcmsScale(): void
    {
        [$fg, $bg] = $this->colorsFrom($this->preload(
            "\x1b]10;rgb:f/0/12\x1b\\" . "\x1b]11;rgb:123/ffff/aa\x1b\\",
        ));

        $this->assertSame('ff0012', $fg);
        $this->assertSame('12ffaa', $bg);
    }

    public function testUppercaseHexReadsToLowercase(): void
    {
        [$fg, $bg] = $this->colorsFrom($this->preload(
            "\x1b]10;rgb:FFFF/0/AB\x1b\\" . "\x1b]11;rgb:01/23/45\x1b\\",
        ));

        $this->assertSame('ff00ab', $fg);
        $this->assertSame('012345', $bg);
    }

    /**
     * Malformed replies read as unknown (null) on their OWN leg: a broken
     * fg answer must never black out a well-formed bg reply, and vice
     * versa. Each row ships the malformed shape in slot 10 next to a valid
     * 11 reply.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedReplies(): iterable
    {
        yield 'not hex'            => ["\x1b]10;rgb:zz/00/00\x1b\\"];
        yield 'two components'     => ["\x1b]10;rgb:1a/2b\x1b\\"];
        yield 'uppercase prefix'   => ["\x1b]10;RGB:1a/2b/3c\x1b\\"];
        yield 'hash form'          => ["\x1b]10;#ffffff\x1b\\"];
        yield 'no terminator'      => ["\x1b]10;rgb:1a/2b/3c"];
        yield 'echo only'          => ["\x1b]10;?\x1b\\"];
        yield 'typed text'         => ["hello\rworld\x07"];
    }

    #[DataProvider('malformedReplies')]
    public function testAMalformedReplyReadsAsUnknownWithoutHidingTheOtherLeg(string $malformed): void
    {
        [$fg, $bg] = $this->colorsFrom($this->preload(
            $malformed . "\x1b]11;rgb:0a/0b/0c\x1b\\",
        ));

        $this->assertNull($fg, 'a malformed OSC 10 answer fabricated a colour: ' . $this->escape($malformed));
        $this->assertSame('0a0b0c', $bg);
    }

    public function testEchoedQueriesNeverFabricateColors(): void
    {
        // The echoed query bytes ARE the ';?' shapes — if echo could ever
        // terminate a read or satisfy a parse, this preload would produce
        // colours out of the query itself (the ISS-048 bug, OSC edition).
        [$fg, $bg] = $this->colorsFrom($this->preload("\x1b]10;?\x1b\\\x1b]11;?\x1b\\"));

        $this->assertNull($fg);
        $this->assertNull($bg);
    }

    /**
     * Behaviour leg of the `?`-guard: a terminal that echoes the queries and
     * answers them LATER must still reach the answers. A terminator loosened
     * to match the echo shape stops the read on chunk one, and the reply
     * bytes that arrive afterwards never make it into the buffer — this pin
     * is what reddens that mutation (the static polarity above cannot,
     * because a whole-buffer parse is blind to where the read broke).
     */
    public function testEchoAheadOfTheReplyStillReadsToTheReply(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);

        @fwrite($pair['write'], "\x1b]10;?\x1b\\\x1b]11;?\x1b\\"); // echo chunk
        @fflush($pair['write']);
        usleep(60_000);                                            // real terminals lag echo
        @fwrite($pair['write'], "\x1b]10;rgb:11/22/33\x1b\\\x1b]11;rgb:44/55/66\x1b\\");
        @fflush($pair['write']);

        [$fg, $bg] = $this->colorsFrom($pair);

        $this->assertSame('112233', $fg, 'the read broke on echoed query bytes instead of the reply');
        $this->assertSame('445566', $bg);
    }

    // -------------------------------------------------------------------------
    // Window budget (the 3-slice / 300 ms total deadline)
    // -------------------------------------------------------------------------

    /**
     * A silent terminal spends the window ONCE: the select waits the
     * deadline's full remaining time, so the answer takes ~300 ms. A
     * mutation that hands every slice a fresh full budget runs 3×300 =
     * 900 ms and dies on the upper bound; one that shrinks the window
     * dies on the lower bound.
     */
    public function testASilentTerminalSpendsTheWholeWindowOnce(): void
    {
        $pair = $this->createStreamPair();   // writer stays OPEN: silent, not EOF
        Detect::setProbeStdin($pair['read']);

        $start    = hrtime(true);
        [$fg, $bg] = $this->colorsFrom($pair);
        $tookMs   = (hrtime(true) - $start) / 1_000_000;

        $this->assertNull($fg);
        $this->assertNull($bg);
        $this->assertGreaterThanOrEqual(250, $tookMs, 'the colour window abandoned its budget early (' . $tookMs . ' ms)');
        $this->assertLessThan(700, $tookMs, 'the colour window re-armed its budget per slice (' . $tookMs . ' ms)');
    }

    /**
     * The complete-pair early break: with both replies preloaded, the read
     * must stop at the first chunk, not ride out the window. Removing the
     * both-terminators break turns this into a ~300 ms read.
     */
    public function testAReplyPairEndsTheWindowEarly(): void
    {
        $pair = $this->preload("\x1b]10;rgb:0d/0e/0f\x1b\\\x1b]11;rgb:fe/dc/ba\x1b\\");

        $start    = hrtime(true);
        [$fg, $bg] = $this->colorsFrom($pair);
        $tookMs   = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame('0d0e0f', $fg);
        $this->assertSame('fedcba', $bg);
        $this->assertLessThan(250, $tookMs, 'the read ignored the complete pair and spent the window (' . $tookMs . ' ms)');
    }

    /**
     * The byte cap: more than OSC_REPLY_MAX_BYTES of non-reply bytes is not
     * a colour answer at any length — the read bails fast and reports
     * unknown. Deleting the cap leg turns "fast" into "full window", which
     * this pin's upper bound catches.
     */
    public function testFloodBeyondTheByteCapBailsWithoutValues(): void
    {
        $pair = $this->preload(str_repeat('A', 5000));

        $start    = hrtime(true);
        [$fg, $bg] = $this->colorsFrom($pair);
        $tookMs   = (hrtime(true) - $start) / 1_000_000;

        $this->assertNull($fg);
        $this->assertNull($bg);
        $this->assertLessThan(250, $tookMs, 'the byte cap did not end the flood read (' . $tookMs . ' ms)');
    }

    // -------------------------------------------------------------------------
    // Wiring: finishProbe is the single exit of every path
    // -------------------------------------------------------------------------

    /**
     * The colour stage reaches ALL five ladder exits (env-definite, feature
     * TRUE, terminfo TRUE, DA1 TRUE, tail) or none of them — that uniformity
     * is the whole of finishProbe, so the call structure is the pin (a pipe
     * harness cannot deliver reply bytes past the earlier stages' reads:
     * probeFontSize/probeDa1 swallow the single preloaded chunk; positive
     * end-to-end colour delivery is therefore pinned at the stage level
     * above and at the wiring here).
     */
    public function testFinishProbeIsTheSingleExitOfEveryProbePath(): void
    {
        $queries = $this->bodyOf('probeQueries');
        $this->assertSame(5, substr_count($queries, 'self::finishProbe('), 'a probe path no longer exits through finishProbe');
        $this->assertSame(0, substr_count($queries, 'drainStdin('), 'a path drains outside finishProbe again');

        $finish = $this->bodyOf('finishProbe');
        $this->assertStringContainsString('self::probeDefaultColors()', $finish);
        $this->assertStringContainsString('withDefaultColors($defaultFg, $defaultBg)', $finish);
        $this->assertStringContainsString('self::drainStdin(50, self::stdinFd())', $finish);
    }

    /**
     * No-regression end-to-end: a silent injected pair still yields the
     * plain ladder answer with both colours unknown — finishProbe must not
     * invent values nor lose the protocol verdict.
     */
    public function testProbeKeepsColoursUnknownWhenTheWindowIsSilent(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertTrue($cap->halfblock);
        $this->assertNull($cap->defaultFg);
        $this->assertNull($cap->defaultBg);
    }

    public function testNonInteractiveProbeNeverWritesOscQueries(): void
    {
        // The gate leg: a daemon-mode process must not emit query bytes at
        // all. __candy_non_interactive forces isInteractiveTty() false while
        // STDERR stays captured-clean; the pin is the value pair, and the
        // near-zero wall proves neither query nor window was spent.
        $GLOBALS['__candy_non_interactive'] = true;
        Detect::reset();

        $start    = hrtime(true);
        $cap      = Detect::probe();
        $tookMs   = (hrtime(true) - $start) / 1_000_000;

        $this->assertNull($cap->defaultFg);
        $this->assertNull($cap->defaultBg);
        $this->assertLessThan(250, $tookMs, 'a non-interactive probe still spent a colour window (' . $tookMs . ' ms)');
    }

    // -------------------------------------------------------------------------
    // Capability value-object carriers
    // -------------------------------------------------------------------------

    public function testWithDefaultColorsIsImmutableAndCarriesEverythingElse(): void
    {
        $original = Capability::universal(new CellSize(8, 16), true);
        $colored  = $original->withDefaultColors('ff0000', '00ff00');

        $this->assertNotSame($original, $colored);
        $this->assertNull($original->defaultFg);
        $this->assertNull($original->defaultBg);
        $this->assertSame('ff0000', $colored->defaultFg);
        $this->assertSame('00ff00', $colored->defaultBg);
        $this->assertTrue($colored->sixel);
        $this->assertTrue($colored->inTmux);
        $this->assertNotNull($colored->cellSize);
        $this->assertSame(8, $colored->cellSize->cellWidth);
    }

    public function testWithCellSizeCarriesDefaultColorsForward(): void
    {
        // The two carriers are orthogonal facets: changing one must never
        // blank the other (the withCellSize rebuild is the regression face).
        $cap   = Capability::sixel()->withDefaultColors('010203', '040506');
        $resized = $cap->withCellSize(new CellSize(10, 20));

        $this->assertSame('010203', $resized->defaultFg);
        $this->assertSame('040506', $resized->defaultBg);
        $this->assertNotNull($resized->cellSize);
        $this->assertSame(20, $resized->cellSize->cellHeight);
        $this->assertTrue($resized->sixel);
    }

    public function testFactoriesLeaveColoursUnknown(): void
    {
        $this->assertNull(Capability::universal()->defaultFg);
        $this->assertNull(Capability::unknown()->defaultBg);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Drive the stage under test through the documented seam.
     *
     * @param array{read: resource, write: resource} $pair
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function colorsFrom(array $pair): array
    {
        Detect::setProbeStdin($pair['read']);
        $stage = new \ReflectionMethod(Detect::class, 'probeDefaultColors');

        return $stage->invoke(null);
    }

    /**
     * A fresh pair preloaded with $bytes in one chunk; the writer stays
     * OPEN so the stage sees real "terminal alive, silent afterwards"
     * topology. tearDown closes both ends.
     */
    private function preload(string $bytes): array
    {
        $pair = $this->createStreamPair();
        @fwrite($pair['write'], $bytes);
        @fflush($pair['write']);

        return $pair;
    }

    /** Method-body text slice for the structural pins (house pattern). */
    private function bodyOf(string $method): string
    {
        $r = new \ReflectionMethod(Detect::class, $method);

        return implode('', array_slice(
            file($r->getFileName()),
            $r->getStartLine() - 1,
            $r->getEndLine() - $r->getStartLine() + 1,
        ));
    }

    /** Escape the control bytes so a failure message names them legibly. */
    private function escape(string $bytes): string
    {
        return str_replace("\x1b", 'ESC', $bytes);
    }

    /**
     * Create a blocking stream pair for simulated stdin I/O.
     *
     * @return array{read: resource, write: resource}
     */
    private function createStreamPair(): array
    {
        $pair = @stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        // Disable buffering so writes appear immediately.
        stream_set_read_buffer($pair[0], 0);
        stream_set_write_buffer($pair[1], 0);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        $this->pairs[] = ['read' => $pair[0], 'write' => $pair[1]];

        return ['read' => $pair[0], 'write' => $pair[1]];
    }
}
