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
        $this->assertStringContainsString('reserved for a future release', $e->getMessage());
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

    /**
     * The four availability reasons all reach the user through this one factory,
     * so the message carries the reason VERBATIM — a rewrite here is how "install
     * the ffi extension" turns back into "Windows is not supported".
     */
    public function testForConPtyUnavailableCarriesTheProbeReasonVerbatim(): void
    {
        $reason = 'the ffi extension is not loaded, and ConPTY is reached through FFI';
        $e = UnsupportedPlatformException::forConPtyUnavailable($reason);

        $this->assertStringContainsString($reason, $e->getMessage());
        $this->assertStringContainsString('ConPTY', $e->getMessage());
        $this->assertStringContainsString('not available', $e->getMessage());
        $this->assertInstanceOf(PtyException::class, $e);
    }

    /**
     * Each of the four shapes names a different fix, so each must survive into the
     * message intact. The pair that is easy to lose is the version one: a user on
     * 8.3 with ext-ffi present has to be told to upgrade PHP, and a user on 8.4
     * without the extension has to be told to install it.
     */
    public function testForConPtyUnavailableReasonsDistinguishTheFourCauses(): void
    {
        $reasons = [
            'this host is Linux, and ConPTY is a Windows API',
            'this PHP is 8.3.6; FFI binding to Win32 in this package requires 8.4 or newer',
            'the ffi extension is not loaded, and ConPTY is reached through FFI',
            'kernel32.dll does not export CreatePseudoConsole (...); ConPTY needs Windows 10 1809 or newer',
        ];

        $messages = [];

        foreach ($reasons as $reason) {
            $messages[] = UnsupportedPlatformException::forConPtyUnavailable($reason)->getMessage();
        }

        // Four distinct messages, each containing its own cause and not another's.
        $this->assertCount(4, \array_unique($messages));

        foreach ($reasons as $index => $reason) {
            $this->assertStringContainsString($reason, $messages[$index]);

            foreach ($reasons as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    $this->assertStringNotContainsString($other, $messages[$index]);
                }
            }
        }
    }

    public function testForConPtyUnavailablePointsAtTheHostNotTheIssueTracker(): void
    {
        // forPosixOnly() sends the reader to the tracker because the work did not
        // exist. Once the primitives exist the remaining cause is this host, and
        // sending someone to an issue tracker for a missing extension is a
        // dead end — so the two messages are deliberately different in kind.
        $e = UnsupportedPlatformException::forConPtyUnavailable('no ffi');

        $this->assertStringNotContainsString('github.com', $e->getMessage());
        $this->assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $e->getMessage());
    }

    public function testForConPtyUnavailableIsCaughtByTheGenericPtyCatch(): void
    {
        $thrown = false;

        try {
            throw UnsupportedPlatformException::forConPtyUnavailable('no ffi');
        } catch (PtyException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
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
