<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Tests\Support\RawWinsizeQuery;

/**
 * Integration tests for raw-fd winsize queries.
 *
 * PTY-40 deleted the `SizeIoctl::query()` front door (zero src
 * consumers, wrong exception type); these tests now go through the
 * package doors the way production code does, via
 * {@see RawWinsizeQuery}.
 */
final class SizeIoctlQueryTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;
    use RawWinsizeQuery;

    public function testQueryReturnsCorrectSizeAfterResize(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $masterFd = $master->fd();

        try {
            $master->resize(132, 40);

            $size = self::queryWinsize($masterFd);

            $this->assertSame(132, $size['cols'], 'cols should be 132');
            $this->assertSame(40, $size['rows'], 'rows should be 40');
        } finally {
            $master->close();
        }
    }

    public function testQueryReturnsDefaultSize(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        $master = $pair->master();
        $masterFd = $master->fd();

        try {
            $size = self::queryWinsize($masterFd);

            $this->assertArrayHasKey('cols', $size);
            $this->assertArrayHasKey('rows', $size);
            $this->assertArrayHasKey('xpix', $size);
            $this->assertArrayHasKey('ypix', $size);
            $this->assertIsInt($size['cols']);
            $this->assertIsInt($size['rows']);
            $this->assertGreaterThanOrEqual(0, $size['cols']);
            $this->assertGreaterThanOrEqual(0, $size['rows']);
        } finally {
            $master->close();
        }
    }

    public function testQueryThrowsOnNonTtyFd(): void
    {
        $this->requireFfiOnlyCapability();

        $libc = \SugarCraft\Pty\Libc::lib();
        $pipeFd = $libc->open('/dev/null', 0x0002);

        if ($pipeFd < 0) {
            $this->markTestSkipped('Could not open /dev/null');
        }

        try {
            $this->expectException(PtyException::class);
            $this->expectExceptionMessage('TIOCGWINSZ');
            self::queryWinsize($pipeFd);
        } finally {
            $libc->close($pipeFd);
        }
    }
}
