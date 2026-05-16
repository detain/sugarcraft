<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\Posix\PosixPtySystem;

final class PosixPtySystemTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host (sandbox or container restriction).');
        }
    }

    public function testOpenReturnsPtyPairExposingMasterAndSlave(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $this->assertInstanceOf(PtyPair::class, $pair);
            $this->assertInstanceOf(MasterPty::class, $pair->master());
            $this->assertInstanceOf(SlavePty::class, $pair->slave());
        } finally {
            $pair->master()->close();
        }
    }

    public function testOpenReturnsMasterWithValidFdAndSlavePath(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $master = $pair->master();
            $this->assertGreaterThanOrEqual(0, $master instanceof \SugarCraft\Pty\Posix\PosixMasterPty ? $master->fd() : 0, 'master fd should be non-negative');
            $this->assertNotEmpty($master instanceof \SugarCraft\Pty\Posix\PosixMasterPty ? $pair->slave()->path() : '', 'slave path should be populated');
        } finally {
            $pair->master()->close();
        }
    }

    public function testSlavePathMatchesPlatformLayout(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $slavePath = $pair->slave()->path();
            $expected = PHP_OS_FAMILY === 'Darwin'
                ? '#^/dev/ttys\d+$#'
                : '#^/dev/pts/\d+$#';
            $this->assertMatchesRegularExpression(
                $expected,
                $slavePath,
                "slave path '{$slavePath}' did not match {$expected} on " . PHP_OS_FAMILY,
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testCapabilitiesReturnsExpectedArray(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $caps = $system->capabilities();

        $this->assertIsArray($caps);
        $this->assertArrayHasKey('pty', $caps);
        $this->assertArrayHasKey('termios', $caps);
        $this->assertArrayHasKey('signal', $caps);
        $this->assertSame(true, $caps['pty']);
        $this->assertSame(true, $caps['termios']);
        $this->assertSame(true, $caps['signal']);
    }

    public function testOpenWithCustomColsRowsDoesNotThrow(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(132, 40);

        try {
            $this->assertInstanceOf(PtyPair::class, $pair);
        } finally {
            $pair->master()->close();
        }
    }

    public function testTwoOpensReturnDistinctPairs(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $a = $system->open();
        $b = $system->open();

        try {
            $this->assertNotSame(
                $a->master() instanceof \SugarCraft\Pty\Posix\PosixMasterPty ? $a->master()->fd() : 0,
                $b->master() instanceof \SugarCraft\Pty\Posix\PosixMasterPty ? $b->master()->fd() : -1,
                'master fds must be distinct',
            );
            $this->assertNotSame(
                $a->slave()->path(),
                $b->slave()->path(),
                'kernel must assign distinct slave paths for concurrent masters',
            );
        } finally {
            $a->master()->close();
            $b->master()->close();
        }
    }
}
