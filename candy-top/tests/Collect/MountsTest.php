<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\Mounts;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class MountsTest extends TestCase
{
    /** @var list<string> */
    private array $probed = [];
    private float $now = 0.0;
    private bool $staleRecovers = false;

    private function clock(): \Closure
    {
        return fn (): float => $this->now;
    }

    private function space(): \Closure
    {
        return function (string $mountpoint): ?array {
            $this->probed[] = $mountpoint;

            return $mountpoint === '/mnt/stale' && !$this->staleRecovers ? null : [1000, 250];
        };
    }

    public function testPhysicalFilterDecodeDedupeAndRootFirst(): void
    {
        [$snap] = Mounts::new(FixtureTree::committed(), $this->space())->sample();

        $points = array_map(static fn (Mount $m): string => $m->mountpoint, $snap->mounts);
        // tmpfs/sysfs are nodev, squashfs is never physical, the stale mount failed statvfs.
        $this->assertSame(['/', '/boot/efi', '/mnt/my disk'], $points);
        $this->assertSame('root', $snap->mounts[0]->name);
        $this->assertSame('my disk', $snap->mounts[2]->name);
        $this->assertSame(750, $snap->mounts[0]->used);
        $this->assertSame(75.0, $snap->mounts[0]->usedPercent());
        $this->assertSame(25.0, $snap->mounts[0]->freePercent());
    }

    public function testFailedStatvfsIsIgnoredThenRetriedAfterInterval(): void
    {
        [, $mounts] = Mounts::new(FixtureTree::committed(), $this->space(), clock: $this->clock())->sample();
        $this->probed = [];
        $this->now = Mounts::RETRY_AFTER - 1.0;
        [, $mounts] = $mounts->sample();
        $this->assertNotContains('/mnt/stale', $this->probed, 'still inside the ignore window');

        $this->staleRecovers = true;
        $this->now = Mounts::RETRY_AFTER + 1.0;
        [$snap] = $mounts->sample();
        $this->assertContains('/mnt/stale', $this->probed);
        $this->assertContains('/mnt/stale', array_map(static fn (Mount $m): string => $m->mountpoint, $snap->mounts));
    }

    public function testRemountLiftsTheIgnoreImmediately(): void
    {
        $tree = FixtureTree::copy();
        try {
            [, $mounts] = Mounts::new($tree->paths(), $this->space(), clock: $this->clock())->sample();
            $tree->write('proc/mounts', "/dev/sdd1 /mnt/stale ext4 rw 0 0\n");
            $this->probed = [];
            $this->staleRecovers = true;
            [$snap] = $mounts->sample();

            $this->assertSame(['/mnt/stale'], $this->probed, 'new device under the same mount point');
            $this->assertSame('/dev/sdd1', $snap->mounts[0]->device);
        } finally {
            $tree->destroy();
        }
    }

    public function testUnfilteredIncludesVirtualFilesystems(): void
    {
        [$snap] = Mounts::new(FixtureTree::committed(), $this->space(), physicalOnly: false)->sample();
        $points = array_map(static fn (Mount $m): string => $m->mountpoint, $snap->mounts);

        $this->assertContains('/run', $points);
        $this->assertContains('/snap/core/1', $points);
    }

    public function testZeroSizeFilesystem(): void
    {
        $mount = new Mount('d', '/x', 'ext4', 'x', 0, 0, 0);

        $this->assertSame(0.0, $mount->usedPercent());
        $this->assertSame(0.0, $mount->freePercent());
    }

    public function testMissingMountsIsEmpty(): void
    {
        $tree = FixtureTree::empty();
        try {
            [$snap] = Mounts::new($tree->paths(), $this->space())->sample();
            $this->assertSame([], $snap->mounts);
        } finally {
            $tree->destroy();
        }
    }
}
