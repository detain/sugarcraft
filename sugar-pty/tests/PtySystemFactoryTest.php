<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\Windows\ConPtySystem;

final class PtySystemFactoryTest extends TestCase
{
    public function testDefaultReturnsPosixSystemOnPosixHost(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the default resolves the ConPTY backend on Windows; pinned by testBackendConptyResolvesTheConPtySystemOnWindows');
        }
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::new());
    }

    /**
     * `::new()` is the root factory per the project convention; the old
     * `::default()` name stays as a deprecated alias that resolves through
     * it, env handling included, so existing callers keep working.
     */
    public function testDeprecatedDefaultAliasDelegatesToNew(): void
    {
        $this->assertTrue(
            \str_contains((string) (new \ReflectionMethod(PtySystemFactory::class, 'default'))->getDocComment(), '@deprecated'),
            'default() must be marked @deprecated in favour of new()',
        );

        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::default());
        }

        \putenv('SUGARCRAFT_PTY_BACKEND=junk-value');
        try {
            PtySystemFactory::default();
            $this->fail('the alias must apply the same SUGARCRAFT_PTY_BACKEND validation as new()');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testForLinuxReturnsPosixSystem(): void
    {
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::forPlatform('Linux'));
    }

    public function testForDarwinReturnsPosixSystem(): void
    {
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::forPlatform('Darwin'));
    }

    public function testForBsdReturnsPosixSystem(): void
    {
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::forPlatform('BSD'));
    }

    /**
     * C-TUI-6.c: Windows resolves the ConPTY adapter instead of throwing.
     * This runs on Linux too — `forPlatform('Windows')` must construct the
     * system without touching FFI, so the dependency-free construction law
     * is pinned off-Windows (before C-TUI-6.c this call threw, which is the
     * red-under-deletion shape for the factory wiring).
     */
    public function testWindowsResolvesTheConPtySystem(): void
    {
        $this->assertInstanceOf(ConPtySystem::class, PtySystemFactory::forPlatform('Windows'));
    }

    public function testUnknownPlatformThrowsUnsupportedPlatformException(): void
    {
        $this->expectException(UnsupportedPlatformException::class);
        PtySystemFactory::forPlatform('Unknown');
    }

    public function testUnsupportedPlatformExceptionExtendsPtyException(): void
    {
        // Callers catching the generic sugar-pty error type must not
        // miss the platform-specific subclass. 'Unknown' is the platform
        // with no backend; Windows now resolves ConPtySystem (C-TUI-6.c).
        try {
            PtySystemFactory::forPlatform('Unknown');
            $this->fail('Expected exception was not thrown');
        } catch (PtyException $e) {
            $this->assertInstanceOf(UnsupportedPlatformException::class, $e);
        }
    }

    public function testUnsupportedPlatformExceptionMessageIsActionable(): void
    {
        try {
            PtySystemFactory::forPlatform('Unknown');
            $this->fail('Expected exception was not thrown');
        } catch (UnsupportedPlatformException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('Unknown', $msg, 'message should name the detected platform');
            $this->assertStringContainsString('ConPTY', $msg, 'message should state which platforms the shipped backends cover');
            $this->assertStringContainsString('http', $msg, 'message should include an upstream URL');
        }
    }

    public function testFactoryHasNoPublicConstructor(): void
    {
        $reflection = new \ReflectionClass(PtySystemFactory::class);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertFalse($ctor->isPublic(), 'PtySystemFactory should be statically callable only');
    }

    // -------------------------------------------------------------------------
    // SUGARCRAFT_PTY_BACKEND env-var tests
    // -------------------------------------------------------------------------

    public function testDefaultWithNoEnvVarReturnsPosixSystem(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX default is not available on Windows');
        }

        // Ensure env var is absent
        \putenv('SUGARCRAFT_PTY_BACKEND');
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::new());
    }

    public function testBackendAutoReturnsPosixSystem(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX default is not available on Windows');
        }

        \putenv('SUGARCRAFT_PTY_BACKEND=auto');
        try {
            $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::new());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testBackendPosixFfiReturnsPosixSystem(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX backend is not available on Windows');
        }

        \putenv('SUGARCRAFT_PTY_BACKEND=posix-ffi');
        try {
            $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::new());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testBackendSidecarThrowsUnsupportedPlatformExceptionWithDeferredMessage(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=sidecar');
        try {
            PtySystemFactory::new();
            $this->fail('Expected UnsupportedPlatformException not thrown');
        } catch (UnsupportedPlatformException $e) {
            $this->assertStringContainsString('reserved for a future release', $e->getMessage());
            $this->assertStringContainsString('sidecar', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testBackendPechThrowsUnsupportedPlatformExceptionWithDeferredMessage(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=pecl');
        try {
            PtySystemFactory::new();
            $this->fail('Expected UnsupportedPlatformException not thrown');
        } catch (UnsupportedPlatformException $e) {
            $this->assertStringContainsString('reserved for a future release', $e->getMessage());
            $this->assertStringContainsString('pecl', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testUnrecognisedBackendThrowsInvalidArgumentException(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=junk-value');
        try {
            PtySystemFactory::new();
            $this->fail('Expected InvalidArgumentException not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $e->getMessage());
            $this->assertStringContainsString('posix-ffi', $e->getMessage());
            $this->assertStringContainsString('conpty', $e->getMessage());
            $this->assertStringContainsString('sidecar', $e->getMessage());
            $this->assertStringContainsString('pecl', $e->getMessage());
            $this->assertStringContainsString('auto', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    /**
     * `SUGARCRAFT_PTY_BACKEND=conpty` selects the ConPTY adapter on Windows
     * — construction is dependency-free, so even a Windows cell below the
     * 8.4 FFI floor resolves here and only refuses later at `open()`.
     * Off Windows the probe's reason must ride in the refusal.
     */
    public function testBackendConptyResolvesTheConPtySystemOnWindows(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=conpty');
        try {
            if (\PHP_OS_FAMILY === 'Windows') {
                $this->assertInstanceOf(ConPtySystem::class, PtySystemFactory::new());

                return;
            }

            try {
                PtySystemFactory::new();
                $this->fail('conpty must refuse on a non-Windows host');
            } catch (UnsupportedPlatformException $e) {
                $msg = $e->getMessage();
                $this->assertStringContainsString('ConPTY', $msg);
                $this->assertStringContainsString('Windows API', $msg, 'the probe reason must ride in the message');
                $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $msg, 'the message must show how to select the backend');
                $this->assertStringNotContainsString('github.com', $msg, 'a host-binding refusal is not a feature request');
            }
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    /**
     * The factory may name Kernel32ConPty only through the static
     * availability probe (which gates on the OS family before any FFI
     * surface). Loading FFI here — at factory time — would break the
     * dependency-free construction law the whole class docblock sells.
     */
    public function testFactorySourceKeepsFfiBehindTheAvailabilityProbe(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/PtySystemFactory.php');
        $this->assertNotFalse($source);
        // Strip comments so docblock prose cannot forge the census.
        $code = \php_strip_whitespace(__DIR__ . '/../src/PtySystemFactory.php');
        $this->assertStringContainsString('Kernel32ConPty::supported()', $code);
        $this->assertStringNotContainsString('Kernel32ConPty::self()', $code);
        $this->assertStringNotContainsString('new Kernel32ConPty', $code);
        $this->assertStringNotContainsString('FFI::cdef', $code);
        $this->assertStringNotContainsString('$system->open()', $code, 'the factory itself must never open a console');
    }
}
