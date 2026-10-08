<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Panel\Proc\TreeAncestry;
use SugarCraft\Top\State\TreeState;
use SugarCraft\Top\Tests\Support\ProcRows;

final class TreeAncestryTest extends TestCase
{
    public function testKeysAreRootFirstNameChains(): void
    {
        $keys = TreeAncestry::keys([
            ProcRows::entry(1, ['name' => 'systemd']),
            ProcRows::entry(10, ['ppid' => 1, 'name' => 'chromium']),
            ProcRows::entry(11, ['ppid' => 10, 'name' => 'chromium']),
            ProcRows::entry(99, ['ppid' => 4242, 'name' => 'orphan']),
        ]);
        $this->assertSame([
            1 => 'systemd',
            10 => "systemd\x1fchromium",
            11 => "systemd\x1fchromium\x1fchromium",
            99 => 'orphan',
        ], $keys, 'a parent not in the list ends the chain');
    }

    public function testOnlyRequestedPidsAndUnknownPidsSkipped(): void
    {
        $entries = [ProcRows::entry(1, ['name' => 'init']), ProcRows::entry(5, ['ppid' => 1, 'name' => 'sh'])];
        $this->assertSame([5 => "init\x1fsh"], TreeAncestry::keys($entries, [5, 777]));
    }

    public function testParentLoopsAndSelfParentsTerminate(): void
    {
        $keys = TreeAncestry::keys([
            ProcRows::entry(7, ['ppid' => 7, 'name' => 'self']),
            ProcRows::entry(20, ['ppid' => 21, 'name' => 'a']),
            ProcRows::entry(21, ['ppid' => 20, 'name' => 'b']),
        ]);
        $this->assertSame('self', $keys[7]);
        $this->assertSame("a\x1fb", $keys[21]);
        $this->assertSame("b\x1fa", $keys[20]);
    }

    public function testTooDeepChainsAreNotKeyed(): void
    {
        $entries = [];
        $depth = TreeState::MAX_DEPTH + 2;
        for ($pid = 1; $pid <= $depth; $pid++) {
            $entries[] = ProcRows::entry($pid, ['ppid' => $pid - 1, 'name' => 'n' . $pid]);
        }
        $keys = TreeAncestry::keys($entries);
        $this->assertArrayHasKey(TreeState::MAX_DEPTH, $keys);
        $this->assertArrayNotHasKey(TreeState::MAX_DEPTH + 1, $keys);
        $this->assertArrayNotHasKey($depth, $keys);
        // Memoised top-down walk gives the same answer pid by pid.
        $this->assertSame($keys[5], TreeAncestry::keys($entries, [5])[5]);
    }
}
