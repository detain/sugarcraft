<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Attachments;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\WorkspaceWalk;

/**
 * C-WEB-7.c (source WEB-5-P3): the non-git `fs.search` listing as a memo.
 *
 * The walk moved out of the protocol method into {@see WorkspaceWalk} and
 * gained a 2-second, per-(realpath, showHidden) memo with a small root cap,
 * so keystroke-after-keystroke in a big non-git root re-filters one walk
 * instead of re-walking the tree synchronously on the serve loop. These
 * tests drive the clock seam rather than sleeping on it, and every test
 * forgets the process-global memo around itself — a leaked listing would
 * make the suite order-dependent (the same discipline as
 * {@see MentionIndexTest}).
 */
final class WorkspaceWalkTest extends TestCase
{
    private string $root;

    /** @var list<string> every temp root this test made, for teardown */
    private array $made = [];

    /** The fake clock: one lever shared by every memo key of a test. */
    private float $stamp = 1000.0;

    protected function setUp(): void
    {
        WorkspaceWalk::forget();
        WorkspaceWalk::setClockForTesting(fn (): float => $this->stamp);
        $this->root = $this->freshRoot('walk-a');
    }

    protected function tearDown(): void
    {
        WorkspaceWalk::setClockForTesting(null);
        WorkspaceWalk::forget();
        foreach ($this->made as $dir) {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        }
        $this->made = [];
    }

    public function testTheWalkIsRootRelativeAndUnfiltered(): void
    {
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/src/deep', 0777, true);
        foreach (['index.php', 'src/Chat.php', 'src/deep/note.txt'] as $rel) {
            file_put_contents($this->root . '/' . $rel, '');
        }

        $listing = WorkspaceWalk::listing($this->root, false);

        self::assertContains('src/Chat.php', $listing, 'directories and their files come through unfiltered');
        self::assertContains('src/deep/note.txt', $listing);
        foreach ($listing as $path) {
            self::assertFalse(str_starts_with($path, '/'), $path . ' is root-relative, not absolute');
        }
    }

    public function testTwoListingsInsideTheTtlShareTheMemoSoAFreshFileIsAbsent(): void
    {
        file_put_contents($this->root . '/before.txt', '');
        self::assertSame(['before.txt'], WorkspaceWalk::listing($this->root, false));

        file_put_contents($this->root . '/just-written.txt', '');

        self::assertSame(['before.txt'], WorkspaceWalk::listing($this->root, false), 'the memo answers without touching the tree again');
    }

    public function testPastTheTtlTheFreshFileAppears(): void
    {
        file_put_contents($this->root . '/before.txt', '');
        WorkspaceWalk::listing($this->root, false);
        file_put_contents($this->root . '/just-written.txt', '');

        $this->stamp += WorkspaceWalk::TTL_SECONDS + 0.1;

        self::assertEqualsCanonicalizing(['before.txt', 'just-written.txt'], WorkspaceWalk::listing($this->root, false));
    }

    public function testHiddenIsKeyedSeparately(): void
    {
        file_put_contents($this->root . '/.secret', '');
        file_put_contents($this->root . '/open.txt', '');

        self::assertSame(['open.txt'], WorkspaceWalk::listing($this->root, false));
        self::assertEqualsCanonicalizing(['.secret', 'open.txt'], WorkspaceWalk::listing($this->root, true), 'the hidden mode walks its own memo');
        self::assertSame(['open.txt'], WorkspaceWalk::listing($this->root, false), 'and the plain memo survived it');
    }

    public function testLinksAndDotEntriesAreSkippedAndDepthStaysCapped(): void
    {
        mkdir($this->root . '/keep', 0777, true);
        file_put_contents($this->root . '/keep/real.txt', '');
        $outside = $this->freshRoot('walk-outside');
        file_put_contents($outside . '/elsewhere.txt', '');
        symlink($outside . '/elsewhere.txt', $this->root . '/keep/link.txt');
        symlink($outside, $this->root . '/keep/dirlink');

        $listing = WorkspaceWalk::listing($this->root, true);

        self::assertSame(['keep/real.txt'], $listing, 'links are never files or dirs of this workspace, and . / .. never entries');

        $deep = $this->root;
        for ($level = 0; $level <= WorkspaceWalk::MAX_DEPTH; $level++) {
            $deep .= '/d' . $level;
        }
        mkdir($deep, 0777, true);
        file_put_contents($deep . '/beyond.txt', '');
        file_put_contents(dirname($deep) . '/at-the-cap.txt', '');

        $this->stamp += WorkspaceWalk::TTL_SECONDS + 0.1;
        $again = WorkspaceWalk::listing($this->root, true);

        self::assertContains('d0/d1/d2/d3/d4/d5/d6/d7/d8/d9/d10/d11/d12/d13/d14/d15/d16/d17/d18/d19/d20/d21/d22/d23/at-the-cap.txt', $again, 'files at the deepest descended level still come through');
        self::assertNotContains('d0/d1/d2/d3/d4/d5/d6/d7/d8/d9/d10/d11/d12/d13/d14/d15/d16/d17/d18/d19/d20/d21/d22/d23/d24/beyond.txt', $again, 'one level past the cap is not read');
    }

    public function testTheRootCapEvictsTheOldestListing(): void
    {
        $roots = [];
        for ($n = 1; $n <= WorkspaceWalk::MAX_ROOTS + 1; $n++) {
            $roots[$n] = $this->freshRoot('walk-cap-' . $n);
            file_put_contents($roots[$n] . '/a.txt', '');
            WorkspaceWalk::listing($roots[$n], false);
        }

        // Root 1 was the first memoized; the ninth insert must have evicted
        // it. Prove the eviction is a re-walk (the fresh file appears even
        // though the memo would say it is absent) rather than amnesia.
        file_put_contents($roots[1] . '/b.txt', '');
        self::assertEqualsCanonicalizing(['a.txt', 'b.txt'], WorkspaceWalk::listing($roots[1], false));

        // The newest entries are still inside their TTL: a file added after
        // memoization is absent until the clock moves.
        file_put_contents($roots[WorkspaceWalk::MAX_ROOTS + 1] . '/b.txt', '');
        self::assertSame(['a.txt'], WorkspaceWalk::listing($roots[WorkspaceWalk::MAX_ROOTS + 1], false));
    }

    public function testForgetDropsOneRootAndTheWholeMemo(): void
    {
        file_put_contents($this->root . '/a.txt', '');
        WorkspaceWalk::listing($this->root, false);
        file_put_contents($this->root . '/b.txt', '');

        WorkspaceWalk::forget($this->root);
        self::assertEqualsCanonicalizing(['a.txt', 'b.txt'], WorkspaceWalk::listing($this->root, false), 'a forgotten root re-walks inside the TTL');

        file_put_contents($this->root . '/c.txt', '');
        WorkspaceWalk::listing($this->root, false);
        file_put_contents($this->root . '/d.txt', '');

        WorkspaceWalk::forget();
        self::assertEqualsCanonicalizing(['a.txt', 'b.txt', 'c.txt', 'd.txt'], WorkspaceWalk::listing($this->root, false), 'forget(null) clears every root');
    }

    private function freshRoot(string $tag): string
    {
        $dir = \sys_get_temp_dir() . '/w7c-' . $tag . '-' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->made[] = $dir;

        return $dir;
    }
}
