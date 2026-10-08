<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\Mounts;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class MountSelectionTest extends TestCase
{
    /** @var list<string> */
    private array $probed = [];

    private function space(): \Closure
    {
        return function (string $mountpoint): array {
            $this->probed[] = $mountpoint;

            return [1000, 250];
        };
    }

    /** @return list<string> */
    private static function points(array $mounts): array
    {
        return array_map(static fn (Mount $m): string => $m->mountpoint, $mounts);
    }

    public function testDefaultsArePhysicalOnlyWithoutFilter(): void
    {
        $sel = MountSelection::new();
        $this->assertTrue($sel->physicalOnly);
        $this->assertFalse($sel->useFstab);
        $this->assertSame([], $sel->filter);
        $this->assertFalse($sel->exclude);
        $this->assertTrue($sel->accepts('/dev/sda1', '/', 'ext4', ['ext4'], null));
        $this->assertFalse($sel->accepts('tmpfs', '/run', 'tmpfs', ['ext4'], null));
        $this->assertTrue(MountSelection::new(false)->accepts('tmpfs', '/run', 'tmpfs', ['ext4'], null));
    }

    public function testIncludeFilterKeepsOnlyNamedMountPoints(): void
    {
        $sel = MountSelection::new(true, false, ' /boot  /home ');
        $this->assertSame(['/boot', '/home'], $sel->filter);
        $this->assertTrue($sel->accepts('/dev/a', '/home', 'ext4', ['ext4'], null));
        $this->assertFalse($sel->accepts('/dev/a', '/', 'ext4', ['ext4'], null));
        // The filter does not bypass the physical gate.
        $this->assertFalse($sel->accepts('tmpfs', '/boot', 'tmpfs', ['ext4'], null));
    }

    public function testExcludePrefixOnlyOnTheFirstToken(): void
    {
        $sel = MountSelection::new(true, false, 'exclude=/boot /home');
        $this->assertTrue($sel->exclude);
        $this->assertSame(['/boot', '/home'], $sel->filter);
        $this->assertFalse($sel->accepts('/dev/a', '/home', 'ext4', ['ext4'], null));
        $this->assertTrue($sel->accepts('/dev/a', '/', 'ext4', ['ext4'], null));
        // btop strips `exclude=` from token 0 only: a later one is a literal path.
        $later = MountSelection::new(true, false, '/ exclude=/home');
        $this->assertFalse($later->exclude);
        $this->assertSame(['/', 'exclude=/home'], $later->filter);
    }

    public function testZfsDatasetsHiddenOnlyWhenAsked(): void
    {
        $this->assertTrue(MountSelection::new(false)->accepts('rpool/ROOT', '/', 'zfs', null, null));
        $hide = MountSelection::new(false, false, '', true);
        $this->assertFalse($hide->accepts('rpool/ROOT', '/', 'zfs', null, null));
        $this->assertTrue($hide->accepts('rpool', '/rpool', 'zfs', null, null));
        $this->assertTrue($hide->accepts('/dev/a/b', '/x', 'ext4', null, null), 'only zfs devices');
    }

    public function testFstabOverridesOnlyPhysicalAndFallsBackWhenUnreadable(): void
    {
        $sel = MountSelection::new(true, true);
        $this->assertTrue($sel->accepts('tmpfs', '/tmp', 'tmpfs', null, ['/tmp']));
        $this->assertFalse($sel->accepts('/dev/a', '/', 'ext4', ['ext4'], ['/tmp']));
        // Deviation: no fstab -> only_physical decides (btop shows nothing).
        $this->assertTrue($sel->accepts('/dev/a', '/', 'ext4', ['ext4'], null));
        $this->assertFalse($sel->accepts('tmpfs', '/tmp', 'tmpfs', ['ext4'], null));
    }

    public function testFstabIsCachedByMtime(): void
    {
        $tree = FixtureTree::copy();
        try {
            $tree->write('etc/fstab', "x / ext4 defaults 0 1\n");
            $path = $tree->root . '/etc/fstab';
            touch($path, 1_000_000);
            $mounts = Mounts::new($tree->paths(), $this->space())->withSelection(MountSelection::new(true, true));
            [$snap, $mounts] = $mounts->sample();
            $this->assertSame(['/'], self::points($snap->mounts));

            // Same mtime: the cached list is used, the new text is not read.
            $tree->write('etc/fstab', "x /boot/efi vfat defaults 0 1\n");
            touch($path, 1_000_000);
            [$snap, $mounts] = $mounts->sample();
            $this->assertSame(['/'], self::points($snap->mounts));

            // A changed mtime re-reads it.
            touch($path, 1_000_100);
            [$snap, $mounts] = $mounts->sample();
            $this->assertSame(['/boot/efi'], self::points($snap->mounts));

            // Removed: physical fallback; the cache survives a use_fstab=off round trip.
            $tree->remove('etc/fstab');
            [$snap] = $mounts->sample();
            $this->assertContains('/', self::points($snap->mounts));
        } finally {
            $tree->destroy();
        }
    }

    public function testFstabParsing(): void
    {
        $text = "# comment\n\nUUID=1 / ext4 defaults 0 1\nUUID=2 none swap sw 0 0\n/swapfile swap swap sw 0 0\n"
            . "//nas/share /mnt/my\\040nas cifs rw 0 0\n  # indented comment\n";
        $this->assertSame(['/', '/mnt/my nas'], MountSelection::fstab($text));
    }

    public function testMountsHonourSelectionAndFstab(): void
    {
        $tree = FixtureTree::copy();
        try {
            $mounts = Mounts::new($tree->paths(), $this->space());
            $this->assertTrue($mounts->selection()->physicalOnly);

            $this->probed = [];
            [$snap] = $mounts->withSelection(MountSelection::new(true, false, 'exclude=/boot/efi'))->sample();
            $this->assertSame(['/', '/mnt/my disk', '/mnt/stale'], self::points($snap->mounts));
            $this->assertNotContains('/boot/efi', $this->probed, 'filtered before statvfs');

            $tree->write('etc/fstab', "/dev/mapper/root / ext4 defaults 0 1\ntmpfs /run tmpfs defaults 0 0\n");
            [$snap] = $mounts->withSelection(MountSelection::new(true, true))->sample();
            $this->assertSame(['/', '/run'], self::points($snap->mounts), 'fstab lists /run; only_physical ignored');

            $tree->remove('etc/fstab');
            [$snap] = $mounts->withSelection(MountSelection::new(true, true))->sample();
            $this->assertContains('/boot/efi', self::points($snap->mounts), 'no fstab: physical fallback');
            $this->assertNotContains('/run', self::points($snap->mounts));
        } finally {
            $tree->destroy();
        }
    }
}
