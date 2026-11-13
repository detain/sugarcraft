<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;

/**
 * Integration tests for PosixTermios against a real PTY.
 *
 * Exercises the full FFI termios path: tcgetattr, cfmakeraw, tcsetattr.
 * Verifies that raw mode actually suppresses echo and canonical line buffering.
 */
final class PosixTermiosTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private const O_RDWR = 0x0002;

    public function testMakeRawDisablesEchoAndCanonicalMode(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);

            $saved = $termios->current();
            $saved->restore();

            $raw = $termios->makeRaw();
            $raw->apply();

            $child = $pair->slave()->spawn(['/bin/cat']);
            $master->write("hello\n");
            $captured = '';
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline) {
                $chunk = $master->read(4096, 0.1);
                if ($chunk === null || $chunk === '') {
                    \usleep(10_000);
                    continue;
                }
                $captured .= $chunk;
                if (\str_contains($captured, "hello\n")) {
                    break;
                }
            }
            $child->kill(\SIGTERM);
            $child->wait();

            $this->assertStringContainsString('hello', $captured, 'cat should have echoed input');
            $this->assertStringNotContainsString("\r", $captured, 'raw mode should have no CR from echo');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testRestoreReAppliesOriginalTermios(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        try {
            $termios = new PosixTermios($slaveFd);
            $original = $termios->current();

            $raw = $termios->makeRaw();
            $raw->apply();

            $original->restore();

            $this->assertTrue($termios->isAtty(), 'PTY slave should be a tty');
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    /**
     * A fresh instance never captured a snapshot via current(), so
     * restore() must fail loudly instead of silently no-op'ing (which
     * would strand the terminal in raw mode). This branch short-circuits
     * before any libc call, so it needs ext-ffi (constructor) but NOT a
     * real PTY — run it unconditionally on any FFI-capable host.
     */
    public function testRestoreWithoutSnapshotThrows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\SugarCraft\Pty\Tests\Support\PtyCapability::ffiUsable()) {
            $this->markTestSkipped('ext-ffi is required to construct PosixTermios.');
        }

        // fd value is irrelevant: restore() throws on the null-snapshot
        // guard before touching the descriptor.
        $termios = new PosixTermios(0);

        $this->expectException(\LogicException::class);
        $termios->restore();
    }

    public function testCurrentReturnsImmutableCopy(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        try {
            $termios = new PosixTermios($slaveFd);
            $current = $termios->current();

            $this->assertNotSame($termios, $current, 'current() must return a new instance');
            $this->assertSame($slaveFd, $current->fd());
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testIsAttyReturnsFalseForNonTtyFd(): void
    {
        $this->requirePtySyscalls();

        $libc = Libc::lib();

        $pipe = $libc->open('/dev/null', self::O_RDWR);
        if ($pipe < 0) {
            $this->markTestSkipped('Could not open /dev/null');
        }

        try {
            $termios = new PosixTermios($pipe);
            $this->assertFalse($termios->isAtty(), '/dev/null is not a tty');
        } finally {
            $libc->close($pipe);
        }
    }

    public function testApplyWithTcsadrainConst(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSADRAIN);

            $this->assertTrue(true, 'apply(TCSADRAIN) must not throw');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testApplyWithTcsaflushConst(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $termios = new PosixTermios($slaveFd);
            $saved = $termios->current();
            $raw = $termios->makeRaw();
            $raw->apply(PosixTermios::TCSAFLUSH);

            $this->assertTrue(true, 'apply(TCSAFLUSH) must not throw');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testContractTypedCallerCanExpressDrain(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $saved = null;
        try {
            $applyDrain = static function (Termios $t): void {
                $t->apply(Termios::TCSADRAIN);
            };

            $termios = new PosixTermios($slaveFd);
            $saved = $termios->current();
            $applyDrain($termios->makeRaw());

            $this->assertTrue(true, 'a contract-typed caller can apply the drain action');
        } finally {
            $saved?->restore();
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testRestoreWithoutCaptureThrows(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();

        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        try {
            // PTY-12 restore() truth: the contract promises the
            // attributes a prior current() captured — a fresh instance
            // read nothing, so it must refuse rather than pretend.
            $this->expectException(\LogicException::class);
            (new PosixTermios($slaveFd))->restore();
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testLibcCdefDeclaresIsattyForTheFallbackArm(): void
    {
        // PTY-29: the ext-posix-less isAtty arm of PosixTermios calls
        // isatty(3) through the cached libc handle — the symbol must be
        // declared in the cdef or the arm fatals at first use.
        $this->assertMatchesRegularExpression('/\bint\s+isatty\s*\(\s*int/', Libc::cdef());
    }

    public function testIsattyArmAnswersWithoutExtPosix(): void
    {
        // Honest skip-gate: with ext-posix loaded the FFI arm is
        // unreachable in-process — on such hosts the public isAtty
        // pins above already prove the primary path.
        if (\function_exists('posix_isatty')) {
            $this->markTestSkipped('ext-posix present; FFI isatty arm unreachable in-process');
        }
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $libc = Libc::lib();
        $slaveFd = $libc->open($pair->slave()->path(), self::O_RDWR);
        $this->assertGreaterThan(0, $slaveFd, 'slave fd must open for the FFI arm');
        try {
            $this->assertTrue((new PosixTermios($slaveFd))->isAtty(), 'FFI arm must report a PTY slave as tty');
        } finally {
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testIsattyAnswersThroughFfiOnRealDescriptors(): void
    {
        // PTY-29 live proof of the arm's mechanism: isatty(3) over the
        // cached libc handle resolves and discriminates a real pty
        // slave (1) from /dev/null (0) without ext-posix involvement.
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();
        $master = $pair->master();
        $libc = Libc::lib();
        $slaveFd = $libc->open($pair->slave()->path(), self::O_RDWR);
        $nullFd = $libc->open('/dev/null', 0);
        try {
            $this->assertSame(1, $libc->isatty($slaveFd), 'pty slave must answer isatty=1 through FFI');
            $this->assertSame(0, $libc->isatty($nullFd), '/dev/null must answer isatty=0 through FFI');
        } finally {
            $libc->close($nullFd);
            $libc->close($slaveFd);
            $master->close();
        }
    }

    public function testTermiosIoctlFailuresCarryTheErrnoDetail(): void
    {
        $this->requirePtySyscalls();

        // A number guaranteed closed: open /dev/null through a raw
        // syscall and release it again (PTY-38 field fact: PHP fopen
        // would not reuse the low number; raw open gives us a closed,
        // deterministic fd to fail against).
        $ffi = \FFI::cdef('int open(const char *path, int flags); int close(int fd);');
        $fd = $ffi->open('/dev/null', 2);
        $this->assertGreaterThan(-1, $fd);
        $this->assertSame(0, $ffi->close($fd));

        $termios = new PosixTermios($fd);

        foreach ([
            ['current', 'tcgetattr failed on fd ' . $fd],
            ['apply', 'tcsetattr failed on fd ' . $fd . ' with when=0'],
        ] as [$method, $head]) {
            try {
                $termios->{$method}();
                $this->fail($method . '() on a closed fd must throw');
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                $this->assertStringStartsWith($head, $msg);
                // errno 9 = EBADF; ext-posix renders the string too.
                $this->assertStringContainsString(
                    \function_exists('posix_strerror') ? '9 (Bad file descriptor)' : ': 9',
                    $msg,
                    'PTY-32 errno appendix missing from ' . $method . '() failure',
                );
            }
        }
    }
}
