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
}
