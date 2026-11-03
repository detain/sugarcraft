<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * M21/m17 (round lib-fixes-1103): spawn geometry must never restamp a
 * default window over a size the caller already chose. The contract
 * declares `?int = null` (keep-current), and the facade honours it the
 * same way PosixSlavePty does.
 */
final class FacadeSpawnGeometryTest extends TestCase
{
    public function testContractTeachesKeepCurrentGeometry(): void
    {
        $params = (new \ReflectionMethod(SlavePty::class, 'spawn'))->getParameters();

        $cols = $params[2];
        $rows = $params[3];

        $this->assertTrue($cols->getType()?->allowsNull(), 'SlavePty::spawn $cols must be nullable');
        $this->assertNull($cols->getDefaultValue(), 'SlavePty::spawn $cols must default to null (keep current)');
        $this->assertTrue($rows->getType()?->allowsNull(), 'SlavePty::spawn $rows must be nullable');
        $this->assertNull($rows->getDefaultValue(), 'SlavePty::spawn $rows must default to null (keep current)');
    }

    public function testFacadeSpawnKeepsOpenGeometryWhenGeometryOmitted(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('Requires usable FFI PTY syscalls.');
        }

        $pty = Pty::open(cols: 132, rows: 43);
        try {
            $child = $pty->spawn(['/bin/sh', '-c', 'exec sleep 30']);
            try {
                $size = $pty->size();
                $this->assertSame(132, $size['cols'], 'omitted geometry must keep the open() width');
                $this->assertSame(43, $size['rows'], 'omitted geometry must keep the open() height');
            } finally {
                $child->kill(\SugarCraft\Pty\Contract\Child::SIGKILL);
                $child->wait();
            }
        } finally {
            $pty->close();
        }
    }

    public function testFacadeSpawnPartialGeometryKeepsTheOtherDimension(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('Requires usable FFI PTY syscalls.');
        }

        $pty = Pty::open(cols: 120, rows: 50);
        try {
            $child = $pty->spawn(['/bin/sh', '-c', 'exec sleep 30'], cols: 90);
            try {
                $size = $pty->size();
                $this->assertSame(90, $size['cols']);
                $this->assertSame(50, $size['rows'], 'rows must ride through untouched');
            } finally {
                $child->kill(\SugarCraft\Pty\Contract\Child::SIGKILL);
                $child->wait();
            }
        } finally {
            $pty->close();
        }
    }
}
