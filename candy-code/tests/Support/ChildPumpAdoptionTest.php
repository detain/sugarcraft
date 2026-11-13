<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * S-L02 (G-11, R4): the pure drain-to-deadline readers ride sugar-async
 * ChildPump::drain() instead of a private stream_select loop. Sites that keep
 * their own pump (interactive bridges, exit-polling loops, hooks with per-chunk
 * callbacks, the stdin-feeding DrainsStreamedChild) are disclosed in the commit
 * and are NOT in this roster.
 */
final class ChildPumpAdoptionTest extends TestCase
{
    private const ADOPTERS = [
        'src/Support/Keychain.php',
        'src/Support/WindowsTaskKill.php',
        'src/Support/ProcessContainment.php',
    ];

    public function testAdoptersDrainThroughChildPumpAndCarryNoPrivateSelect(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ADOPTERS as $file) {
            $src = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('ChildPump::drain(', $src, $file);
            self::assertStringNotContainsString('stream_select(', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src) ?? '', $file . ' grew its own pump again');
        }
    }
}
