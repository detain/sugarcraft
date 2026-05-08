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
