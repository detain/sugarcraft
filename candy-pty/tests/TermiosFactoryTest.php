<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Pty\Posix\SttyTermios;
use SugarCraft\Pty\TermiosFactory;

/**
 * Integration tests for TermiosFactory.
 *
 * Verifies FFI-first selection, stty fallback via env var,
 * and behavioral parity between both backends.
 */
final class TermiosFactoryTest extends TestCase
{
    private const O_RDWR = 0x0002;

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    private function requireFfi(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for FFI termios path.');
        }
    }

    public function testOpenReturnsPosixTermiosByDefault(): void
    {
        $this->requirePtySyscalls();
        $this->requireFfi();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $termios = TermiosFactory::open($pair->master()->fd());
            $this->assertInstanceOf(PosixTermios::class, $termios);
        } finally {
            $pair->master()->close();
        }
    }

    public function testOpenReturnsSttyTermiosWhenEnvForced(): void
    {
        $this->requirePtySyscalls();

        $oldEnv = \getenv('SUGARCRAFT_TERMIOS');
        \putenv('SUGARCRAFT_TERMIOS=stty');
        try {
            $system = new PosixPtySystem();
            $pair = $system->open();

            try {
                $termios = TermiosFactory::open($pair->master()->fd());
                $this->assertInstanceOf(SttyTermios::class, $termios);
            } finally {
                $pair->master()->close();
            }
        } finally {
            if ($oldEnv === false) {
                \putenv('SUGARCRAFT_TERMIOS');
            } else {
                \putenv('SUGARCRAFT_TERMIOS=' . $oldEnv);
            }
        }
    }

    public function testWhichReturnsPosixTermiosByDefault(): void
    {
        $this->requirePtySyscalls();
        $this->requireFfi();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $which = TermiosFactory::which($pair->master()->fd());
            $this->assertSame('PosixTermios', $which);
        } finally {
            $pair->master()->close();
        }
    }

    public function testWhichReturnsSttyTermiosWhenEnvForced(): void
    {
        $this->requirePtySyscalls();

        $oldEnv = \getenv('SUGARCRAFT_TERMIOS');
        \putenv('SUGARCRAFT_TERMIOS=stty');
        try {
            $system = new PosixPtySystem();
            $pair = $system->open();

            try {
                $which = TermiosFactory::which($pair->master()->fd());
                $this->assertSame('SttyTermios', $which);
            } finally {
                $pair->master()->close();
            }
        } finally {
            if ($oldEnv === false) {
                \putenv('SUGARCRAFT_TERMIOS');
            } else {
                \putenv('SUGARCRAFT_TERMIOS=' . $oldEnv);
            }
        }
    }

    public function testBothBackendsPassRawModeBehavioralTest(): void
    {
        $this->requirePtySyscalls();

        $libc = \SugarCraft\Pty\Libc::lib();

        foreach (['stty', null] as $variant) {
            $oldEnv = \getenv('SUGARCRAFT_TERMIOS');
            if ($variant !== null) {
                \putenv('SUGARCRAFT_TERMIOS=' . $variant);
            } else {
                \putenv('SUGARCRAFT_TERMIOS');
            }

            try {
                $system = new PosixPtySystem();
                $pair = $system->open();
                $master = $pair->master();
                $slavePath = $pair->slave()->path();

                $slaveFd = $libc->open($slavePath, self::O_RDWR);
                if ($slaveFd < 0) {
                    $pair->master()->close();
                    $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
                }

                try {
                    $termios = TermiosFactory::open($slaveFd);

                    $saved = $termios->current();
                    $termios->restore();

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

                    $backend = $variant ?? 'FFI';
                    $this->assertStringContainsString(
                        'hello',
                        $captured,
                        "Backend {$backend}: cat should have received input"
                    );
                    $this->assertStringNotContainsString(
                        "\r",
                        $captured,
                        "Backend {$backend}: raw mode should have no CR from echo"
                    );
                } finally {
                    $termios->restore();
                    $libc->close($slaveFd);
                    $master->close();
                }
            } finally {
                if ($oldEnv === false) {
                    \putenv('SUGARCRAFT_TERMIOS');
                } else {
                    \putenv('SUGARCRAFT_TERMIOS=' . $oldEnv);
                }
            }
        }
    }
}
