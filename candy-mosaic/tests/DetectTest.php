<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Detect;
use SugarCraft\Mosaic\Capability;

final class DetectTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot and clear relevant env vars so each test starts clean.
        $keys = ['KITTY_WINDOW_ID', 'TERM_PROGRAM', 'LC_TERMINAL', 'TERM', 'XTERM_VERSION'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
    }

    protected function tearDown(): void
    {
        // Restore env vars to pre-test state.
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        Detect::reset();
        Detect::setProbeStdin(null);
        if (isset($GLOBALS['__candy_non_interactive'])) {
            unset($GLOBALS['__candy_non_interactive']);
        }
        parent::tearDown();
    }

    public function testKittyViaKittyWindowId(): void
    {
        putenv('KITTY_WINDOW_ID=12345');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testKittyViaTermProgramGhostty(): void
    {
        putenv('TERM_PROGRAM=ghostty');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testKittyViaTermProgramWezTerm(): void
    {
        putenv('TERM_PROGRAM=WezTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testKittyViaXtermKittyTerm(): void
    {
        putenv('TERM=xterm-kitty');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
    }

    public function testIterm2ViaTermProgramItermApp(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        $cap = Detect::probe();
        $this->assertFalse($cap->kitty);
        $this->assertTrue($cap->iterm2);
    }

    public function testIterm2ViaTermProgramWezTerm(): void
    {
        // WezTerm supports both Kitty and iTerm2 protocols; per our precedence
        // Kitty wins, so TERM_PROGRAM=WezTerm returns kitty capability.
        putenv('TERM_PROGRAM=WezTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testIterm2ViaTermProgramMintty(): void
    {
        putenv('TERM_PROGRAM=mintty');
        $cap = Detect::probe();
        $this->assertTrue($cap->iterm2);
    }

    public function testIterm2ViaLcTerminal(): void
    {
        putenv('LC_TERMINAL=iTerm2');
        $cap = Detect::probe();
        $this->assertTrue($cap->iterm2);
    }

    /**
     * WezTerm supports both Kitty and iTerm2 protocols but we exclusively
     * classify it as Kitty (Kitty protocol family wins the precedence).
     */
    public function testWezTermIsKittyOnlyNotIterm2(): void
    {
        putenv('TERM_PROGRAM=WezTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->kitty, 'WezTerm must be detected as Kitty');
        $this->assertFalse($cap->iterm2, 'WezTerm must NOT be detected as iTerm2');
    }

    /**
     * When probeStdin is a socket pair (non-TTY), the isInteractiveTty() check
     * inside Detect returns false without invoking posix_isatty(0)/posix_isatty(1).
     * We verify this indirectly: with a mock non-TTY stdin and no TTY env vars,
     * the result should fall through to the unknown/halfblock path.
     */
    public function testNonTtyIsInteractiveIsFalse(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        Detect::reset();
        // Explicitly re-enable interactive checks.
        unset($GLOBALS['__candy_non_interactive']);

        // With no env hints and a non-TTY stdin, DA1 probing runs but
        // falls back to unknown (halfblock only).
        $cap = Detect::probe();

        @fclose($pair['write']);

        // No protocol family detected; only halfblock is available.
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testSixelViaXtermVersionAndMlterm(): void
    {
        putenv('TERM=mlterm');
        putenv('XTERM_VERSION=Mlterm');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testSixelViaXtermVersionAndFoot(): void
    {
        putenv('TERM=foot');
        putenv('XTERM_VERSION=Foot');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
    }

    public function testSixelViaXtermVersionAndXterm256color(): void
    {
        putenv('TERM=xterm-256color');
        putenv('XTERM_VERSION=XTerm');
        $cap = Detect::probe();
        $this->assertTrue($cap->sixel);
    }

    public function testSixelRequiresXtermVersion(): void
    {
        putenv('TERM=mlterm');
        $cap = Detect::probe();
        // No sixel without XTERM_VERSION.
        $this->assertFalse($cap->sixel);
    }

    public function testHalfBlockAlwaysAvailable(): void
    {
        $cap = Detect::probe();
        $this->assertTrue($cap->halfblock);
        // No other protocol detected.
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertFalse($cap->sixel);
    }

    public function testCachedReturnsSameInstance(): void
    {
        putenv('TERM_PROGRAM=iTerm.app');
        $first  = Detect::cached();
        $second = Detect::cached();
        $this->assertSame($first, $second);
    }

    public function testCachedReturnsCapabilityInstance(): void
    {
        $cap = Detect::cached();
        $this->assertInstanceOf(Capability::class, $cap);
    }

    // -------------------------------------------------------------------------
    // DA1 probe tests
    // -------------------------------------------------------------------------

    public function testDaemonNonInteractiveReturnsNullFromDa1(): void
    {
        // Force non-interactive: use posix_isatty on a closed/null fd.
        // We achieve this by pointing setProbeStdin at a non-tty stream.
        // Note: real non-TTY CI environments naturally bypass DA1 because
        // posix_isatty(0) returns false — this test simulates that path
        // by ensuring sixel env hints (xterm-256color) WITHOUT a TTY
        // still allow the fallback to unknown.
        $GLOBALS['__candy_non_interactive'] = true;

        putenv('TERM=xterm-256color');
        Detect::reset();
        Detect::setProbeStdin(null); // reset probe stdin override

        $cap = Detect::probe();

        // No sixel env hints (XTERM_VERSION missing), no TTY, so unknown.
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testSixelDetectedViaDa1Reply(): void
    {
        // Disable font-size TTY probing — it would consume the DA1 reply
        // bytes from the socket before probeDa1() gets to read them.
        $GLOBALS['__candy_non_interactive'] = false;

        // Create a socket pair and preload the reply before calling probe().
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Preload the socket with the DA1 reply — probe() will drain it.
        $reply = "\x1b[?62;4;0c";
        $written = @fwrite($pair['write'], $reply);
        $this->assertNotFalse($written, 'fwrite to mock stdin failed');
        @fflush($pair['write']);

        // Now probe — it will write DA1 query to STDOUT (silently ignored)
        // then read the reply from our preloaded socket.
        $cap = Detect::probe();

        @fclose($pair['write']);

        $this->assertTrue($cap->sixel);
        $this->assertFalse($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testDa1ReplyWithoutSixelReturnsFalse(): void
    {
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // DA1 reply with no sixel bit: ESC [ ?62 ; 0 c
        $reply = "\x1b[?62;0c";
        @fwrite($pair['write'], $reply);
        @fflush($pair['write']);

        $cap = Detect::probe();

        @fclose($pair['write']);

        // No sixel env hints, no sixel in DA1 reply → unknown.
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testDa1TimeoutReturnsNull(): void
    {
        // Create a pair with write end closed immediately — Detect will time out
        // because there's no data when it first reads.
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Write nothing; close write end immediately.
        @fclose($pair['write']);

        $cap = Detect::probe();

        // Without sixel env hints and after a DA1 timeout, we fall back to unknown.
        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    public function testKittyEnvVarPreventsDa1Query(): void
    {
        // When Kitty is detected via env vars, DA1 should not be called.
        // We verify this by checking that lastDa1Reply() is null.
        putenv('KITTY_WINDOW_ID=99');
        Detect::reset();

        $cap = Detect::probe();

        $this->assertTrue($cap->kitty);
        $this->assertNull(Detect::lastDa1Reply());
    }

    public function testLastDa1ReplyRecordsRawResponse(): void
    {
        $GLOBALS['__candy_non_interactive'] = false;

        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        $reply = "\x1b[?62;4;0c";
        @fwrite($pair['write'], $reply);
        @fflush($pair['write']);

        Detect::probe();

        @fclose($pair['write']);

        $this->assertSame($reply, Detect::lastDa1Reply());
    }

    public function testProbeDa1AcceptsSixelAnywhereInReply(): void
    {
        // Sixel bit (4) can appear in different positions depending on
        // the terminal's DA1 response format:
        //   \x1b[?62;4;0c   - 4 as secondary attr  (contains ";4;")
        //   \x1b[?62;0;4c   - 4 as tertiary attr   (contains ";4c")
        //   \x1b[0;4;0c     - abbreviated           (contains ";4;")
        //   \x1b[?4c        - sixel-only (mlterm)   (contains "?4c")
        $cases = [
            "\x1b[?62;4;0c" => true,
            "\x1b[?62;0;4c" => true,
            "\x1b[0;4;0c"   => true,
            "\x1b[?4c"      => true,
            "\x1b[?62;0c"   => false, // no sixel bit
        ];

        foreach ($cases as $reply => $expectSixel) {
            $GLOBALS['__candy_non_interactive'] = false;

            $pair = $this->createStreamPair();
            stream_set_blocking($pair['write'], false);
            stream_set_blocking($pair['read'], false);

            Detect::setProbeStdin($pair['read']);
            Detect::reset();

            @fwrite($pair['write'], $reply);
            @fflush($pair['write']);

            $cap = Detect::probe();

            @fclose($pair['write']);

            $this->assertSame(
                $expectSixel,
                $cap->sixel,
                "Expected sixel=" . ($expectSixel ? 'true' : 'false')
                . ' for reply: ' . addcslashes($reply, "\x1b\0..\37\177")
            );
        }
    }

    /**
     * @group tty
     * Font-size probing requires a real interactive terminal (STDOUT write +
     * STDIN read round-trip).  Socket-pair tests cannot simulate this because
     * probeXtwino() writes XTWINOPS queries to STDOUT and reads replies from
     * STDIN — the socket pair only mocks the read side.  In a TTY environment
     * these tests run end-to-end; in CI / non-interactive environments they
     * are skipped and the parse logic is covered by unit tests instead.
     */
    public function testFontSizeDetectedViaXtwino16(): void
    {
        if (!posix_isatty(1) || !posix_isatty(0)) {
            $this->markTestSkipped('Font-size probe requires an interactive TTY.');
        }

        // Terminal responds to XTWINOPS 16 with cell pixel size.
        // Reply format: ESC [ 6 ; <cellHeight> ; <cellWidth> t
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Write reply AFTER setProbeStdin so the data sits in the kernel
        // socket buffer until stream_select is called inside probeFontSize().
        @fwrite($pair['write'], "\x1b[6;20;10t");
        @fflush($pair['write']);
        usleep(20_000);

        $cap = Detect::probe();

        @fclose($pair['write']);

        $this->assertNotNull($cap->cellSize);
        $this->assertSame(10, $cap->cellSize->cellWidth);
        $this->assertSame(20, $cap->cellSize->cellHeight);
    }

    /**
     * @group tty  — see testFontSizeDetectedViaXtwino16 for rationale.
     */
    public function testFontSizeDerivedFromXtwino14Plus18(): void
    {
        if (!posix_isatty(1) || !posix_isatty(0)) {
            $this->markTestSkipped('Font-size probe requires an interactive TTY.');
        }

        // Terminal does NOT support 16t, but responds to 14t + 18t.
        // We load all three replies so the reader processes them in order:
        //   1. 16t times out (empty) → probe tries 14t
        //   2. 14t: window 800×480  → probe tries 18t
        //   3. 18t: 24 rows × 80 cols
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Write AFTER setProbeStdin (see testFontSizeDetectedViaXtwino16 note).
        @fwrite($pair['write'], "\x1b[4;480;800t\x1b[8;24;80t");
        @fflush($pair['write']);
        usleep(20_000);

        $cap = Detect::probe();

        @fclose($pair['write']);

        // window 800×480 ÷ 80 cols × 24 rows = 10×20 cell pixels
        $this->assertNotNull($cap->cellSize);
        $this->assertSame(10, $cap->cellSize->cellWidth);
        $this->assertSame(20, $cap->cellSize->cellHeight);
    }

    public function testFontSizeReturnsNullOnTimeout(): void
    {
        // No font-size replies → cellSize stays null.
        Detect::reset();
        Detect::setProbeStdin(null);

        $cap = Detect::probe();

        // cellSize is null when font-size probing returns no reply.
        $this->assertNull($cap->cellSize);
    }

    /**
     * Unit test for parseXtwinoReply: verifies 16t (direct cell size) parsing.
     * Uses reflection to access the private method.
     */
    public function testParseXtwinoReply16tDirectCellSize(): void
    {
        $reply = "\x1b[6;20;10t";  // cellHeight=20, cellWidth=10
        $method = new \ReflectionMethod(Detect::class, 'parseXtwinoReply');
        $method->setAccessible(true);
        $result = $method->invoke(null, $reply, "\x1b[16t");

        $this->assertNotNull($result);
        $this->assertSame(10, $result->cellWidth);
        $this->assertSame(20, $result->cellHeight);
    }

    /**
     * Unit test for parseXtwinoReply: verifies 14t+18t (window÷grid cell size).
     * Uses reflection to access the private method.
     */
    public function testParseXtwinoReplyComputesCellSizeFrom14Plus18(): void
    {
        // 14t reply: window 800×480 px
        $reply14 = "\x1b[4;480;800t";
        $method = new \ReflectionMethod(Detect::class, 'parseXtwinoReply');
        $method->setAccessible(true);
        $windowSize = $method->invoke(null, $reply14, "\x1b[14t");

        // 18t reply: 80 cols × 24 rows
        $reply18 = "\x1b[8;24;80t";
        $gridSize = $method->invoke(null, $reply18, "\x1b[18t");

        // 14t: window pixel dimensions  (800 cols × 480 rows)
        // 18t: terminal grid dimensions (80 cols × 24 rows)
        // Derived cell size: 800/80=10 px/cell, 480/24=20 px/cell
        $this->assertNotNull($windowSize);
        $this->assertNotNull($gridSize);
        $this->assertSame(800, $windowSize->cellWidth);   // window px
        $this->assertSame(480, $windowSize->cellHeight);  // window px
        $this->assertSame(80, $gridSize->cellWidth);      // grid cols
        $this->assertSame(24, $gridSize->cellHeight);     // grid rows
    }

    /**
     * Without a real TTY the font-size probe returns null (no reply to read),
     * but Kitty detection from env vars still works correctly.  The cellSize
     * assertion mirrors the real-world behaviour: env-driven detection bypasses
     * the DA1 + font-size I/O round-trip, so cellSize stays null.
     *
     * @group tty  — see testFontSizeDetectedViaXtwino16 for rationale.
     */
    public function testFontSizeAttachedToKittyCapability(): void
    {
        if (!posix_isatty(1) || !posix_isatty(0)) {
            $this->markTestSkipped('Font-size probe requires an interactive TTY.');
        }

        // KITTY_WINDOW_ID triggers kitty detection; font-size probe still runs.
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();
        putenv('KITTY_WINDOW_ID=1');

        @fwrite($pair['write'], "\x1b[6;16;8t");
        @fflush($pair['write']);

        $cap = Detect::probe();

        @fclose($pair['write']);
        putenv('KITTY_WINDOW_ID');

        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
        $this->assertNotNull($cap->cellSize);
        $this->assertSame(8, $cap->cellSize->cellWidth);
        $this->assertSame(16, $cap->cellSize->cellHeight);
    }

    /**
     * @group tty  — see testFontSizeDetectedViaXtwino16 for rationale.
     */
    public function testFontSizeAttachedToSixelCapability(): void
    {
        if (!posix_isatty(1) || !posix_isatty(0)) {
            $this->markTestSkipped('Font-size probe requires an interactive TTY.');
        }

        // DA1 confirms sixel; font-size probe still runs.
        // No env hints → probeEnv returns unknown → DA1 is issued.
        $pair = $this->createStreamPair();
        stream_set_blocking($pair['write'], false);
        stream_set_blocking($pair['read'], false);

        Detect::setProbeStdin($pair['read']);
        Detect::reset();

        // Font-size reply first (consumed by probeFontSize), then DA1
        // reply (consumed by probeDa1).  Write AFTER setProbeStdin.
        @fwrite($pair['write'], "\x1b[6;12;6t\x1b[?62;4;0c");
        @fflush($pair['write']);
        usleep(20_000);

        $cap = Detect::probe();

        @fclose($pair['write']);

        $this->assertTrue($cap->sixel);
        $this->assertNotNull($cap->cellSize);
        $this->assertSame(6, $cap->cellSize->cellWidth);
        $this->assertSame(12, $cap->cellSize->cellHeight);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

        return ['read' => $pair[0], 'write' => $pair[1]];
    }
}
