<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\CellSize;
use SugarCraft\Image\Detect;

/**
 * F-19: implausible XTWINOPS replies are rejected, not adopted.
 *
 * 4/6/8;t replies are trusted data once they match the terminator shape —
 * until something answers a well-formed LIE. Chafa issue #62 is the caution
 * tale: a hostile (or broken) emulator answering 16t with absurd pixel
 * extents drove every downstream pixel budget into gigabyte territory.
 * Chafa's cure (chafa-term.c:54-55) is per-TYPE — PIXEL_EXTENT_MAX
 * 8192*3=24576 on both axes of a pixel reply, CELL_EXTENT_PX_MAX 8192 on
 * both axes of a cell reply — rejecting any extent outside `0 < c < MAX`
 * — exclusive on both ends — so the zero sizes a non-answering terminal
 * prints ("I do not know my rows: 0") are rejected exactly as the 2^31
 * fabrications are. Detect ships one per-axis pair (x < 8192 / y < 24576)
 * for all reply types; the divergences are disclosed at the MAX_REPLY_*
 * docblock and pinned by the boundary rows below.
 * Detect now applies the same ceiling at its single reply-parsing
 * chokepoint, parseXtwinoReply(), which all three queries (16t direct,
 * 14t window, 18t grid) flow through: a rejected reply is indistinguishable
 * from "no reply" and the ladder falls back to font-estimated sizes.
 *
 * ## Zero is a behavior change, pinned here
 *
 * Before this clamp, "\x1b[6;0;0t" parsed to CellSize(0,0) and rode into
 * division arithmetic. The exclusive lower bound turns every zero-extent
 * reply into a fallthrough; the rows below pin both the rejection AND the
 * just-inside control values so a future "make it a range check with <="
 * edit dies loudly on the boundary rows.
 */
final class DetectReplyClampTest extends TestCase
{
    // Mirror of Detect's private XTWINOPS query constants (the reply-id
    // match arm needs the exact query bytes; they are not public surface).
    private const Q14 = "\x1b[14t";
    private const Q16 = "\x1b[16t";
    private const Q18 = "\x1b[18t";

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    /** @var list<resource> */
    private array $open = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = ['TMUX', 'TERM', 'TERM_PROGRAM', 'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::setTerminfoSixelOverride(false); // no exec from this file
        Detect::setAutoPassthrough(false);
    }

    protected function tearDown(): void
    {
        Detect::reset();
        Detect::setProbeStdin(null);
        Detect::clearTmuxTermFeaturesOverride();
        Detect::clearTerminfoSixelOverride();
        Detect::setAutoPassthrough(true);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->savedEnv = [];
        foreach ($this->open as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->open = [];
        parent::tearDown();
    }

    /**
     * Every reply whose x or y escapes `0 < v < MAX` (per axis) must parse
     * to null; the just-inside controls must keep parsing. The 8192/24576
     * values are the BOUNDARY rows: chafa's comparison is strict (`<`), so
     * exactly-at-max is a reject, exactly-one-below is the widest reply
     * Detect will still believe.
     *
     * @return array<string, array{string, string, CellSize|null}>
     */
    public static function replyProvider(): array
    {
        return [
            '16t control'              => ["\x1b[6;20;10t", self::Q16, new CellSize(10, 20)],
            '16t widest believed'      => ["\x1b[6;24575;8191t", self::Q16, new CellSize(8191, 24575)],
            '16t x at ceiling'         => ["\x1b[6;20;8192t", self::Q16, null],
            '16t x beyond ceiling'     => ["\x1b[6;20;9000t", self::Q16, null],
            '16t y at ceiling'         => ["\x1b[6;24576;10t", self::Q16, null],
            '16t y gigabyte lie'       => ["\x1b[6;99999999;10t", self::Q16, null],
            '16t zero height'          => ["\x1b[6;0;10t", self::Q16, null],
            '16t zero width'           => ["\x1b[6;20;0t", self::Q16, null],
            '16t negative'             => ["\x1b[6;-5;10t", self::Q16, null],
            '14t window zero row'      => ["\x1b[4;0;800t", self::Q14, null],
            '14t control'              => ["\x1b[4;480;800t", self::Q14, new CellSize(800, 480)],
            '18t cols beyond ceiling'  => ["\x1b[8;24;9000t", self::Q18, null],
            '18t rows zero'            => ["\x1b[8;0;80t", self::Q18, null],
            '18t control'              => ["\x1b[8;24;80t", self::Q18, new CellSize(80, 24)],
        ];
    }

    /**
     * @dataProvider replyProvider
     */
    public function testParsedExtentsOutsideThePlausibilityBoxBecomeNoReply(
        string $reply,
        string $query,
        ?CellSize $expected,
    ): void {
        $parse = new \ReflectionMethod(Detect::class, 'parseXtwinoReply');

        $actual = $parse->invoke(null, $reply, $query);

        if ($expected === null) {
            $this->assertNull(
                $actual,
                'reply ' . var_export($reply, true) . ' escaped the F-19 clamp',
            );

            return;
        }
        $this->assertInstanceOf(CellSize::class, $actual);
        $this->assertSame($expected->cellWidth, $actual->cellWidth);
        $this->assertSame($expected->cellHeight, $actual->cellHeight);
    }

    /**
     * End-to-end: a hostile 16t answer on the probe stream must leave the
     * capability WITHOUT a cell size (the font-estimate fallback), where
     * pre-F-19 the fabricated extents were adopted verbatim. The control
     * half of this row preloads an in-range answer and must see it adopted
     * — otherwise "no cell size" here could just mean "the read never
     * happened".
     */
    public function testHostileLiveReplyYieldsNoCellSizeWhereAHonestOneIsAdopted(): void
    {
        // House idiom (see DetectTest::testFontSizeDetectedViaXtwino16): the
        // live XTWINOPS exchange writes queries to STDOUT, so it only runs
        // under an interactive TTY; on pipes the reflection provider above
        // carries the clamp proof and this e2e is skipped.
        if (!posix_isatty(1) || !posix_isatty(0)) {
            $this->markTestSkipped('Font-size probe requires an interactive TTY.');
        }

        $hostile = $this->createStreamPair();
        Detect::setProbeStdin($hostile['read']);
        $written = @fwrite($hostile['write'], "\x1b[6;20;99999t");
        $this->assertIsInt($written);
        @fflush($hostile['write']);
        Detect::reset();

        $cap = Detect::probe();

        $this->assertNull(
            $cap->cellSize,
            'a reply beyond the plausibility box was adopted into the capability',
        );
        $this->assertTrue($cap->halfblock, 'the probe still completed');
        Detect::setProbeStdin(null);
        fclose($hostile['read']);
        fclose($hostile['write']);

        $honest = $this->createStreamPair();
        Detect::setProbeStdin($honest['read']);
        $written = @fwrite($honest['write'], "\x1b[6;20;10t");
        $this->assertIsInt($written);
        @fflush($honest['write']);
        Detect::reset();

        $cap = Detect::probe();

        $this->assertNotNull($cap->cellSize, 'the control: an in-range 16t reply must still be adopted');
        $this->assertSame(10, $cap->cellSize->cellWidth);
        $this->assertSame(20, $cap->cellSize->cellHeight);
        fclose($honest['read']);
        fclose($honest['write']);
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
        $this->open[] = $pair[0];
        $this->open[] = $pair[1];

        return ['read' => $pair[0], 'write' => $pair[1]];
    }
}
