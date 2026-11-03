<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\{Repo, User};
use SugarCraft\GitServer\Git\{PktLine, UploadPack};

/**
 * The SSH upload service delegates to the real `git upload-pack` binary
 * through the bounded stdio pump. These tests pin BOTH sides of that
 * contract: the authorization gates that run before any child is spawned,
 * and a complete fetch negotiation over real protocol bytes — the shape
 * the retired hand-rolled line parser could never answer (it read plain
 * "want" lines the protocol never sends, so every real client died on
 * arrival).
 *
 * @covers \SugarCraft\GitServer\Git\UploadPack
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
        $bare = \escapeshellarg($path);
        \exec("git -c init.defaultBranch=master init --bare {$bare} 2>/dev/null");

        // Hermetic fixture: CI runners have no global user.name/user.email
        // and may default init.defaultBranch to main — pass both inline so
        // the commit + push never depend on ambient git config.
        $workDir = $this->tmpDir . '/work';
        \mkdir($workDir, 0755, true);
        $work = \escapeshellarg($workDir);
        \exec("git -c init.defaultBranch=master init {$work} 2>/dev/null && echo 'hello' > {$workDir}/file.txt && git -C {$work} add . && git -C {$work} -c user.email=test@example.com -c user.name=Test commit -m 'initial' 2>/dev/null && git -C {$work} push {$bare} master 2>/dev/null");

        return $path;
    }

    public function testRepoAccessor(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $pack = new UploadPack($repo);
        $this->assertSame($repo, $pack->repo());
    }

    public function testServeRefusesRepoTheUserCannotRead(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath)->withPrivate(true);
        $pack = new UploadPack($repo, User::new('bob'));

        // The gate must refuse before any child spawns: an unreadable repo
        // never reaches git, so the streams are never read or written.
        $this->assertSame(1, $pack->serve($this->memoryStream(), $this->memoryStream()));
    }

    public function testServeRefusesMissingRepo(): void
    {
        $pack = new UploadPack(
            Repo::new('ghost', $this->tmpDir . '/nope.git'),
            User::new('admin')->withAdmin(true)
        );

        $this->assertSame(1, $pack->serve($this->memoryStream(), $this->memoryStream()));
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $handle = \fopen('php://memory', 'w+b');
        $this->assertNotFalse($handle);

        return $handle;
    }


    /**
     * The C-02 wire proof: a pkt-framed want/done request gets the real
     * advertisement (with the capabilities NUL on the HEAD packet git
     * emits — the retired hand-roll sent no caps at all) followed by an
     * ACK/NAK and an actual packfile.
     */
    public function testServeCompletesARealFetchNegotiation(): void
    {
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }

        $repoPath = $this->initGitRepo();
        $refs = Repo::new('test', $repoPath)->refs();
        $head = $refs['refs/heads/master'] ?? $refs['refs/heads/main'] ?? null;
        $this->assertNotNull($head, 'fixture must advertise a branch head');

        $inPath = $this->tmpDir . '/fetch-in.bin';
        $outPath = $this->tmpDir . '/fetch-out.bin';
        \file_put_contents(
            $inPath,
            PktLine::encode("want {$head}\n") . PktLine::FLUSH . PktLine::encode("done\n")
        );

        $pack = new UploadPack(Repo::new('test', $repoPath), User::new('admin')->withAdmin(true));
        $in = \fopen($inPath, 'rb');
        $out = \fopen($outPath, 'wb');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        $this->assertSame(0, $pack->serve($in, $out));

        $bytes = \file_get_contents($outPath);
        $this->assertIsString($bytes);
        $this->assertStringContainsString('refs/heads/', $bytes, 'advertisement must list refs');
        $this->assertStringContainsString("HEAD\x00", $bytes, 'HEAD packet must NUL-separate its capabilities');
        $this->assertMatchesRegularExpression('/(ACK|NAK)/', $bytes, 'negotiation must answer with ACK or NAK');
        $this->assertMatchesRegularExpression('/PACK\x00\x00\x00[\x02\x03]/', $bytes, 'a real packfile must follow');
    }
}
