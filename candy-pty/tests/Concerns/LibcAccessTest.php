<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Concerns;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Concerns\LibcAccess;
use SugarCraft\Pty\ControllingTerminal;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\ChildPollTrait;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\SizeIoctl;

final class LibcAccessTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
    }

    /**
     * Structural guard: every FFI-touching class funnels libc access
     * through the shared trait instead of inlining `Libc::lib()`.
     *
     * @return list<class-string>
     */
    private static function ffiUsingClasses(): array
    {
        return [
            Pty::class,
            SizeIoctl::class,
            ControllingTerminal::class,
            SignalForwarder::class,
            PosixPtySystem::class,
            PosixMasterPty::class,
            PosixTermios::class,
        ];
    }

    public function testFfiUsingClassesUseTheTrait(): void
    {
        foreach (self::ffiUsingClasses() as $class) {
            $this->assertContains(
                LibcAccess::class,
                \class_uses($class),
                "{$class} must use LibcAccess instead of inlining Libc::lib()",
            );
        }
    }

    public function testChildPollTraitComposesLibcAccess(): void
    {
        // PosixChild / PosixProcess get libc() transitively via
        // ChildPollTrait, so assert on the trait itself.
        $this->assertContains(LibcAccess::class, \class_uses(ChildPollTrait::class));
    }

    public function testLibcAccessorReturnsTheSharedFfiHandle(): void
    {
        $this->requirePtySyscalls();

        $accessor = new \ReflectionMethod(SizeIoctl::class, 'libc');
        $ffi = $accessor->invoke(null);

        $this->assertInstanceOf(\FFI::class, $ffi);
        // Same instance as the process-wide singleton — the trait is
        // pure sugar, not a second FFI handle.
        $this->assertSame(Libc::lib(), $ffi);
    }
}
