<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\TermiosFactory;

/**
 * Extended TermiosFactory tests covering oNoCtty(),
 * the exception-throw path in which(), and the open() exception path.
 */
final class TermiosFactoryExtendedTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi required for this test.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // oNoCtty()
    // ─────────────────────────────────────────────────────────────

    public function testONoCttyReturnsZeroOnDarwin(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Darwin-specific value.');
        }
        $this->assertSame(0x20000, TermiosFactory::oNoCtty());
    }

    public function testONoCttyReturnsOctal400OnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux-specific value.');
        }
        $this->assertSame(0o400, TermiosFactory::oNoCtty());
    }

    // ─────────────────────────────────────────────────────────────
    // O_RDWR constant
    // ─────────────────────────────────────────────────────────────

    public function testORDWRConstant(): void
    {
        $this->assertSame(0x0002, TermiosFactory::O_RDWR);
    }

    // ─────────────────────────────────────────────────────────────
    // which() with exception path (PosixTermios construction failure)
    // ─────────────────────────────────────────────────────────────

    public function testWhichReturnsFallbackWhenPosixTermiosFails(): void
    {
        $this->requirePtySyscalls();

        // Open a real PTY so we have a valid fd.
        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            // Force stty path to test the catch branch in which().
            $oldEnv = \getenv('SUGARCRAFT_TERMIOS');
            \putenv('SUGARCRAFT_TERMIOS=stty');
            try {
                $which = TermiosFactory::which($pair->master()->fd());
                $this->assertSame('SttyTermios', $which);
            } finally {
                if ($oldEnv === false) {
                    \putenv('SUGARCRAFT_TERMIOS');
                } else {
                    \putenv('SUGARCRAFT_TERMIOS=' . $oldEnv);
                }
            }
        } finally {
            $pair->master()->close();
        }
    }

    public function testWhichReturnsPreferredWhenPosixTermiosSucceeds(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $oldEnv = \getenv('SUGARCRAFT_TERMIOS');
            \putenv('SUGARCRAFT_TERMIOS'); // Clear any override.

            try {
                $which = TermiosFactory::which($pair->master()->fd());
                $this->assertSame('PosixTermios', $which);
            } finally {
                if ($oldEnv !== false) {
                    \putenv('SUGARCRAFT_TERMIOS=' . $oldEnv);
                }
            }
        } finally {
            $pair->master()->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private constructor guard
    // ─────────────────────────────────────────────────────────────

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(TermiosFactory::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
