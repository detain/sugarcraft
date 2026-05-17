<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtySystemFactory;

final class PtySystemFactoryTest extends TestCase
{
    public function testDefaultReturnsPosixSystemOnPosixHost(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('default() throws on Windows; covered by testWindowsThrowsUnsupportedPlatformException');
        }
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::default());
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

    public function testWindowsThrowsUnsupportedPlatformException(): void
    {
        $this->expectException(UnsupportedPlatformException::class);
        PtySystemFactory::forPlatform('Windows');
    }

    public function testUnknownPlatformThrowsUnsupportedPlatformException(): void
    {
        $this->expectException(UnsupportedPlatformException::class);
        PtySystemFactory::forPlatform('Unknown');
    }

    public function testUnsupportedPlatformExceptionExtendsPtyException(): void
    {
        // Callers catching the generic candy-pty error type must not
        // miss the platform-specific subclass.
        try {
            PtySystemFactory::forPlatform('Windows');
            $this->fail('Expected exception was not thrown');
        } catch (PtyException $e) {
            $this->assertInstanceOf(UnsupportedPlatformException::class, $e);
        }
    }

    public function testUnsupportedPlatformExceptionMessageIsActionable(): void
    {
        try {
            PtySystemFactory::forPlatform('Windows');
            $this->fail('Expected exception was not thrown');
        } catch (UnsupportedPlatformException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('Windows', $msg, 'message should name the platform');
            $this->assertStringContainsString('v2', $msg, 'message should point at the v2 sidecar plan');
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
        $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::default());
    }

    public function testBackendAutoReturnsPosixSystem(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX default is not available on Windows');
        }

        \putenv('SUGARCRAFT_PTY_BACKEND=auto');
        try {
            $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::default());
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
            $this->assertInstanceOf(PosixPtySystem::class, PtySystemFactory::default());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testBackendSidecarThrowsUnsupportedPlatformExceptionWithDeferredMessage(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=sidecar');
        try {
            PtySystemFactory::default();
            $this->fail('Expected UnsupportedPlatformException not thrown');
        } catch (UnsupportedPlatformException $e) {
            $this->assertStringContainsString('phase 12', $e->getMessage());
            $this->assertStringContainsString('sidecar', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testBackendPechThrowsUnsupportedPlatformExceptionWithDeferredMessage(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=pecl');
        try {
            PtySystemFactory::default();
            $this->fail('Expected UnsupportedPlatformException not thrown');
        } catch (UnsupportedPlatformException $e) {
            $this->assertStringContainsString('phase 12', $e->getMessage());
            $this->assertStringContainsString('pecl', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }

    public function testUnrecognisedBackendThrowsInvalidArgumentException(): void
    {
        \putenv('SUGARCRAFT_PTY_BACKEND=junk-value');
        try {
            PtySystemFactory::default();
            $this->fail('Expected InvalidArgumentException not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $e->getMessage());
            $this->assertStringContainsString('posix-ffi', $e->getMessage());
            $this->assertStringContainsString('sidecar', $e->getMessage());
            $this->assertStringContainsString('pecl', $e->getMessage());
            $this->assertStringContainsString('auto', $e->getMessage());
        } finally {
            \putenv('SUGARCRAFT_PTY_BACKEND');
        }
    }
}
