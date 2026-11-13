<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Visibility;

/**
 * @covers \SugarCraft\GitServer\Repo
 */
final class RepoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/repo-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function createRepoPath(string $name = 'test'): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Builder method tests
    // -------------------------------------------------------------------------

    public function testWithDescription(): void
    {
        $r = Repo::new('test', '/tmp/test')->withDescription('My Description');
        $this->assertSame('My Description', $r->description);
    }

    public function testWithHighlightLanguage(): void
    {
        $r = Repo::new('test', '/tmp/test')->withHighlightLanguage('php');
        $this->assertSame('php', $r->highlightLanguage);
    }

    public function testWithAllowPush(): void
    {
        $r = Repo::new('test', '/tmp/test')->withAllowPush(true);
        $this->assertTrue($r->allowPush);
    }

    // -------------------------------------------------------------------------
    // Git operations tests
    // -------------------------------------------------------------------------

    /**
     * Fix . : init() used to make $path itself the
     * bare repo while exists() and every discovery gate looked for
     * $path/.git — a freshly created repository was invisible to its own
     * creator. This roundtrip pins the layout agreement.
     */
    public function testInitProducesLayoutVisibleToExistsAndDiscovery(): void
    {
        $path = $this->tmpDir . '/fresh-created';
        $r = Repo::new('fresh', $path)->withDescription('roundtrip')->init();

        $this->assertTrue($r->exists(), 'init() must satisfy exists(): bare Git at $path/.git');
        $this->assertTrue(is_dir($path . '/.git'));
        // The metadata writes land INSIDE the Git dir now, not beside it.
        $this->assertTrue(is_file($path . '/.git/description'));
        $this->assertStringContainsString('roundtrip', (string) file_get_contents($path . '/.git/description'));
        // init() must NOT drop a git-daemon-export-ok marker: that file
        // opts the repo into anonymous read-export for any co-hosted stock
        // git-daemon, so it is an operator decision, never automatic.
        $this->assertFalse(is_file($path . '/.git/git-daemon-export-ok'));
        $this->assertTrue(is_dir($path . '/.git/hooks'));
        // Real git commands run against $path (git -C path works on this layout).
        $this->assertSame([], $r->branches());
    }

    public function testExistsReturnsFalseWhenNotInitialized(): void
    {
        $path = $this->createRepoPath('not-init');
        $r = Repo::new('not-init', $path);

        $this->assertFalse($r->exists());
    }

    /**
     * n15: writeVisibilityMarker creates the marker directory fresh; the
     * mkdir must state owner-only intent, not inherit a permissive umask.
     */
    public function testAFreshlyCreatedMarkerDirectoryIsOwnerOnly(): void
    {
        $bare = $this->tmpDir . '/fresh-cap.git';

        Repo::new('fresh-cap', $bare)->withPublic(true)->writeVisibilityMarker();

        $this->assertSame(0700, \fileperms($bare . '/.git') & 0777, 'the fresh marker directory is owner-only regardless of process umask');
        $this->assertSame(Visibility::Public, Repo::markerVisibility($bare . '/.git'), 'the marker still round-trips through the reader');
    }

    /**
     * S18/009: the ctor default was Visibility::Public, which made every
     * "created PRIVATE by default" comment and every registerRepo marker
     * gate a lie — and let a fresh handle leak a world-visible posture it
     * was never given. A bare Repo::new() must NOT be public.
     */
    public function testAFreshHandleDefaultsToPrivatePosture(): void
    {
        $r = Repo::new('posture', $this->createRepoPath('posture'));

        $this->assertSame(Visibility::Private, $r->visibility);
        $this->assertFalse($r->isVisiblePublic(), 'a handle that was never published reads as public');
    }

    // -------------------------------------------------------------------------
    // S16: readFile byte fidelity + fault distinction, tags symmetry
    // -------------------------------------------------------------------------

    /**
     * S16 (finding 031): the blob's bytes are the answer. A CRLF file
     * round-trips as CRLF (the old line-split+implode turned it into LF),
     * and a trailing newline is content, not noise to be trimmed.
     */
    public function testReadFileDeliversTheBlobsExactCRLFBytes(): void
    {
        $repo = $this->committedFixtureRepo('crlf');
        $hash = $repo->refs()['refs/heads/master'];

        $this->assertSame("a\r\nb\r\n", $repo->readFile($hash, 'crlf.txt'));
    }

    /**
     * S16 (finding 031): the ordinary browse miss — "that path is not in
     * this rev" — answers the promised null SILENTLY. A missing object
     * is not a fault worth a log line.
     */
    public function testReadingAMissingPathIsNullWithoutALogLine(): void
    {
        $repo = $this->committedFixtureRepo('quiet');
        $hash = $repo->refs()['refs/heads/master'];

        $log = $this->captureErrorLog(function () use ($repo, $hash): void {
            $this->assertNull($repo->readFile($hash, 'ghost.txt'));
        });

        $this->assertStringNotContainsString('git show', $log, 'a not-found miss must not masquerade as a fault');
    }

    /**
     * S16 (finding 031): but a REPOSITORY fault (here: the path holds no
     * repo at all) is no longer conflated with the miss — the child's
     * own verdict reaches the log before the honest null.
     */
    public function testARepositoryFaultIsLoggedBeforeTheNull(): void
    {
        $repo = Repo::new('broken', $this->createRepoPath('not-a-repo'));

        $log = $this->captureErrorLog(function () use ($repo): void {
            $this->assertNull($repo->readFile('HEAD', 'anything.txt'));
        });

        $this->assertStringContainsString('git show', $log);
        $this->assertStringContainsString('not a git repository', $log, 'the child verdict must be in the log');
    }

    /**
     * S16 (finding 030): a blob past the bounded output cap is REFUSED —
     * partial bytes served as file content are a lie, so the answer is
     * null plus a log line naming the cap, never a silent prefix.
     */
    public function testABlobPastTheOutputCapIsRefusedNotServedPrefixTruncated(): void
    {
        $path = $this->createRepoPath('flood');
        \exec('git -c init.defaultBranch=master init ' . \escapeshellarg($path) . ' 2>/dev/null');
        \file_put_contents($path . '/big.bin', \str_repeat('Z', 16 * 1024 * 1024));
        \exec(
            'git -C ' . \escapeshellarg($path) . ' add big.bin && ' .
            'git -C ' . \escapeshellarg($path) . ' -c user.email=t@example.com -c user.name=T commit -m big 2>/dev/null'
        );
        $repo = Repo::new('flood', $path);
        $hash = $repo->refs()['refs/heads/master'];

        $log = $this->captureErrorLog(function () use ($repo, $hash): void {
            $this->assertNull($repo->readFile($hash, 'big.bin'), 'overflowed content must be refused');
        });

        $this->assertStringContainsString('exceeds the bounded output cap', $log);
    }

    /**
     * S16 (finding 088): tags() handles the same stream shape with the
     * same trim/filter legs as branches() — behavior plus a structural
     * symmetry pin so a future edit cannot silently re-stray the \r.
     */
    public function testTagsTrimAndFilterExactlyLikeBranches(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/Repo.php');
        $symmetryLeg = \substr_count($src, "\\array_values(\\array_filter(\\array_map('trim', \$result['output'])");
        $this->assertSame(2, $symmetryLeg, 'branches() and tags() must share the identical trim/filter shape');

        $repo = $this->committedFixtureRepo('tagged');
        \exec('git -C ' . \escapeshellarg($repo->path()) . ' tag v1.0 && ' .
              'git -C ' . \escapeshellarg($repo->path()) . ' tag v2.0 2>/dev/null');

        $this->assertSame(['v1.0', 'v2.0'], $repo->tags(), 'clean entries, no stray CR, no empty line');
    }

    /** A repo holding one master commit with crlf.txt + file.txt inside. */
    private function committedFixtureRepo(string $name): Repo
    {
        $path = $this->createRepoPath($name);
        \exec('git -c init.defaultBranch=master init ' . \escapeshellarg($path) . ' 2>/dev/null');
        \file_put_contents($path . '/crlf.txt', "a\r\nb\r\n");
        \file_put_contents($path . '/file.txt', "hello\n");
        \exec(
            'git -C ' . \escapeshellarg($path) . ' add . && ' .
            'git -C ' . \escapeshellarg($path) . ' -c user.email=t@example.com -c user.name=T commit -m initial 2>/dev/null'
        );

        return Repo::new($name, $path);
    }

    /** Route error_log() to a private file for the call and hand back what landed. */
    private function captureErrorLog(callable $fn): string
    {
        $log = $this->tmpDir . '/errlog-' . \uniqid('', true);
        $previous = \ini_get('error_log');
        \ini_set('error_log', $log);
        try {
            $fn();
        } finally {
            \ini_set('error_log', $previous === false ? '' : $previous);
        }

        return \is_file($log) ? (string) \file_get_contents($log) : '';
    }
}
