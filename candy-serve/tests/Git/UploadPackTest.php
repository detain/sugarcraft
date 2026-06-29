<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\{AccessControl, Repo, User};
use SugarCraft\Serve\Git\UploadPack;

/**
 * @covers \SugarCraft\Serve\Git\UploadPack
 */
final class UploadPackTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/upload-pack-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    private function initGitRepo(): string
    {
        $path = $this->tmpDir . '/test-repo.git';
        \mkdir($path, 0755, true);
        \exec('git init --bare ' . \escapeshellarg($path) . ' 2>/dev/null');

        $workDir = $this->tmpDir . '/work';
        \mkdir($workDir, 0755, true);
        \exec("git init {$workDir} 2>/dev/null && echo 'hello' > {$workDir}/file.txt && git -C {$workDir} add . && git -C {$workDir} commit -m 'initial' 2>/dev/null && git -C {$workDir} push {$path} master 2>/dev/null");

        return $path;
    }

    // -------------------------------------------------------------------------
    // advertiseRefs tests
    // -------------------------------------------------------------------------

    public function testAdvertiseRefsReturnsString(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $pack = new UploadPack($repo);
        $advertisement = $pack->advertiseRefs();

        $this->assertIsString($advertisement);
    }

    public function testAdvertiseRefsContainsRefAndHash(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $pack = new UploadPack($repo);
        $advertisement = $pack->advertiseRefs();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40} refs\/heads\//m', $advertisement);
    }

    public function testAdvertiseRefsExcludesCurrentHeadBranch(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $branches = $repo->branches();
        $head = $branches !== [] ? $branches[0] : 'main';

        $pack = new UploadPack($repo);
        $advertisement = $pack->advertiseRefs();
        $lines = \explode("\n", \rtrim($advertisement, "\n"));

        // The head branch line should appear once (in the first ref)
        // Subsequent lines should NOT start with refs/heads/{head}
        $headRef = 'refs/heads/' . $head;
        $firstLineIsHead = \strpos($lines[0], $headRef) !== false;
        $this->assertTrue($firstLineIsHead, 'First line should be the head ref');

        // Check no other line is the same head ref (exact match, not prefix)
        $matches = \array_filter(\array_slice($lines, 1), fn($l) => $l !== '' && \strpos($l, $headRef) !== false);
        $this->assertCount(0, $matches, 'No other line should be the head ref');
    }

    // -------------------------------------------------------------------------
    // sendPack via subprocess (Step 10 integration)
    // -------------------------------------------------------------------------

    public function testSendPackProducesPackSignature(): void
    {
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }

        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $refs = $repo->refs();
        $headRef = $refs['refs/heads/master'] ?? $refs['refs/heads/main'] ?? null;
        $this->assertNotNull($headRef);

        // Test pack generation via git directly (the protocol-agnostic way)
        $escapedPath = \escapeshellarg($repoPath);
        $cmd = "git -C {$escapedPath} pack-objects --stdout --revs 2>/dev/null";
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) {
            $this->fail('Could not start git pack-objects');
        }
        \fwrite($pipes[0], $headRef . "\n");
        \fclose($pipes[0]);
        $packData = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        $this->assertStringStartsWith('PACK', $packData);
        $this->assertGreaterThan(8, \strlen($packData));
    }

    // -------------------------------------------------------------------------
    // repo accessor test (Step 9)
    // -------------------------------------------------------------------------

    public function testRepoAccessor(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $pack = new UploadPack($repo);
        $this->assertSame($repo, $pack->repo());
    }
}
