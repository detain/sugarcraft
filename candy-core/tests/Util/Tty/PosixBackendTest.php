<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Posix\PosixPtySystem;
use PHPUnit\Framework\TestCase;

final class PosixBackendTest extends TestCase
{
    public function testSizeFallsBackToReasonableDefaults(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS');
        putenv('LINES');

        try {
            $size = $tty->size();
            // Verify structure
            $this->assertIsArray($size);
            $this->assertArrayHasKey('cols', $size);
            $this->assertArrayHasKey('rows', $size);
            // Verify reasonable positive dimensions (>= 80 cols, >= 24 rows)
            $this->assertGreaterThanOrEqual(80, $size['cols']);
            $this->assertGreaterThanOrEqual(24, $size['rows']);
        } finally {
            if ($prevCols !== false) {
                putenv('COLUMNS=' . $prevCols);
            }
            if ($prevRows !== false) {
                putenv('LINES='   . $prevRows);
            }
            fclose($r);
        }
    }

    public function testSizeHonorsEnv(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS=132');
        putenv('LINES=50');

        try {
            $size = $tty->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(50, $size['rows']);
        } finally {
            putenv('COLUMNS' . ($prevCols === false ? '' : '=' . $prevCols));
            putenv('LINES'   . ($prevRows === false ? '' : '=' . $prevRows));
            fclose($r);
        }
    }

    public function testIsTtyFalseForMemoryStream(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testEnableAndRestoreRawModeNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);
        $tty->enableRawMode();
        $tty->restore();
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testOpenTtyReturnsPairOrNull(): void
    {
        $result = PosixBackend::openTty();
        // CI sandboxes may not expose /dev/tty — accept either branch.
        if ($result === null) {
            $this->assertNull($result);
            return;
        }
        $this->assertCount(2, $result);
        [$in, $out] = $result;
        $this->assertIsResource($in);
        $this->assertIsResource($out);
        $this->assertNotSame($in, $out);
        fclose($in);
        fclose($out);
    }

    public function testOnResizeNoOpWithoutPcntl(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->assertFalse(PosixBackend::onResize(static fn () => null));
            return;
        }
        // On posix with pcntl available, the install should succeed.
        $installed = PosixBackend::onResize(static fn () => null);
        $this->assertTrue($installed);
        // Restore default handler so the test doesn't leak a closure.
        if (defined('SIGWINCH')) {
            \pcntl_signal(SIGWINCH, SIG_DFL);
        }
    }

    public function testDrainSignalsReturnsIntOrFalse(): void
    {
        $result = PosixBackend::drainSignals();
        // Returns int (0 or SIGNAL_RESIZE=2) when pcntl is available,
        // or false when pcntl_signal_dispatch does not exist.
        $this->assertTrue(\is_int($result) || $result === false);
    }

    public function testRawModeWithSttyFallbackOnRealPty(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!function_exists('posix_isatty')) {
            $this->markTestSkipped('posix_isatty is not available.');
        }

        // Check that stty can actually use /dev/fd/ for a real fd
        $testFd = fopen('php://memory', 'r+');
        if ($testFd === false) {
            $this->markTestSkipped('Could not open test stream');
        }
        $fd = (int) $testFd;
        fclose($testFd);
        $sttyTestPath = '/dev/fd/' . $fd;
        if (!is_readable($sttyTestPath) && !is_link($sttyTestPath)) {
            $this->markTestSkipped('/dev/fd/<n> is not accessible in this environment.');
        }

        $prevTermios = getenv('SUGARCRAFT_TERMIOS');
        putenv('SUGARCRAFT_TERMIOS=stty');
        try {
            $system = new PosixPtySystem();
            $pair = $system->open();
            $master = $pair->master();
            $slavePath = $pair->slave()->path();

            $slave = fopen($slavePath, 'r+');
            if ($slave === false) {
                $this->markTestSkipped('Could not open PTY slave path: ' . $slavePath);
            }

            try {
                $backend = new PosixBackend($slave);
                $backend->enableRawMode();

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

                $this->assertStringContainsString('hello', $captured, 'cat should have received input');
                $this->assertStringNotContainsString("\r", $captured, 'raw mode should have no CR from echo');
            } finally {
                $backend->restore();
                fclose($slave);
                $master->close();
            }
        } finally {
            if ($prevTermios === false) {
                putenv('SUGARCRAFT_TERMIOS');
            } else {
                putenv('SUGARCRAFT_TERMIOS=' . $prevTermios);
            }
        }
    }
}
