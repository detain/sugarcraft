<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\PtyException;

final class UnsupportedPlatformExceptionTest extends TestCase
{
    public function testExtendsPtyException(): void
    {
        $e = new UnsupportedPlatformException('POSIX only');
        $this->assertInstanceOf(PtyException::class, $e);
    }

    public function testForPosixOnlyIncludesDetectedPlatform(): void
    {
        $e = UnsupportedPlatformException::forPosixOnly('Windows');
        $this->assertStringContainsString('Windows', $e->getMessage());
        $this->assertStringContainsString('POSIX', $e->getMessage());
        $this->assertStringContainsString('v1', $e->getMessage());
    }

    public function testForPosixOnlyIncludesV2Reference(): void
    {
        $e = UnsupportedPlatformException::forPosixOnly('Windows');
        $this->assertStringContainsString('v2', $e->getMessage());
        $this->assertStringContainsString('ConPTY', $e->getMessage());
    }

    public function testForPosixOnlyIncludesIssueTrackerUrl(): void
    {
        $e = UnsupportedPlatformException::forPosixOnly('Windows');
        $this->assertStringContainsString('github.com', $e->getMessage());
    }

    public function testForPosixOnlyLinux(): void
    {
        $e = UnsupportedPlatformException::forPosixOnly('Linux');
        $this->assertStringContainsString('Linux', $e->getMessage());
    }

    public function testForPosixOnlyDarwin(): void
    {
        $e = UnsupportedPlatformException::forPosixOnly('Darwin');
        $this->assertStringContainsString('Darwin', $e->getMessage());
    }

    public function testForDeferredBackend(): void
    {
        $e = UnsupportedPlatformException::forDeferredBackend('conpty');
        $this->assertStringContainsString('conpty', $e->getMessage());
        $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $e->getMessage());
        $this->assertStringContainsString('phase 12', $e->getMessage());
        $this->assertStringContainsString('posix-ffi', $e->getMessage());
    }

    public function testForDeferredBackendSidecar(): void
    {
        $e = UnsupportedPlatformException::forDeferredBackend('sidecar');
        $this->assertStringContainsString('sidecar', $e->getMessage());
    }

    public function testForDeferredBackendPech(): void
    {
        $e = UnsupportedPlatformException::forDeferredBackend('pecl');
        $this->assertStringContainsString('pecl', $e->getMessage());
    }

    public function testCanBeCaughtAsPtyException(): void
    {
        $thrown = false;
        try {
            throw UnsupportedPlatformException::forPosixOnly('Windows');
        } catch (PtyException $e) {
            $thrown = true;
            $this->assertInstanceOf(UnsupportedPlatformException::class, $e);
        }
        $this->assertTrue($thrown);
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        $thrown = false;
        try {
            throw UnsupportedPlatformException::forPosixOnly('Windows');
        } catch (\RuntimeException $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }
}
