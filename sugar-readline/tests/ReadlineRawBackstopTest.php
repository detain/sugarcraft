<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Readline;

/**
 * The raw-mode shutdown backstop must not restore the terminal from a forked
 * child: the child inherits the parent's shutdown queue, and a restore there
 * would switch the parent's live tty back to cooked mode.
 */
final class ReadlineRawBackstopTest extends TestCase
{
    public function testAForeignPidLeavesTheOwnedRawModeInPlace(): void
    {
        $owned = new \ReflectionProperty(Readline::class, 'rawOwned');
        $saved = new \ReflectionProperty(Readline::class, 'rawSaved');
        $owned->setValue(null, true);
        $saved->setValue(null, 'probe-snapshot');
        try {
            $restore = new \ReflectionMethod(Readline::class, 'restoreAtShutdown');
            $restore->invoke(null, \getmypid() + 1);
            self::assertTrue($owned->getValue(), 'a forked child must not restore the parent raw mode');
            self::assertSame('probe-snapshot', $saved->getValue());
        } finally {
            $owned->setValue(null, false);
            $saved->setValue(null, '');
        }
    }
}
