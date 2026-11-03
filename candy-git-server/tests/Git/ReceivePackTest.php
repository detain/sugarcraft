<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\{Repo, User};
use SugarCraft\GitServer\Git\{PktLine, ReceivePack};

/**
 * The SSH receive service delegates to the real `git receive-pack` binary
 * through the bounded stdio pump. The retired hand-roll read plain
 * command lines, never unpacked the packfile, and moved refs to whatever
 * object IDs the client claimed — a push of nonexistent objects corrupted
 * repositories by construction. These pins prove the delegated service
 * applies real pushes (objects present, refs moved) and refuses forged
 * ones (ref stays absent).
 *
 * @covers \SugarCraft\GitServer\Git\ReceivePack
 */
final class ReceivePackTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/receive-pack-test-' . \uniqid();
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

    /**
     * Fresh EMPTY bare target (no refs at all) so any ref found after a
     * push was definitively created by that push.
     */
    private function emptyBareRepo(): string
    {
        $path = $this->tmpDir . '/target.git';
        \mkdir($path, 0755, true);
        \exec('git -c init.defaultBranch=master init --bare ' . \escapeshellarg($path) . ' 2>/dev/null');

        return $path;
    }

    /**
     * @return array{0: string, 1: string} [repoPath, commitSha]
     */
    private function workRepoWithCommit(): array
    {
        $work = $this->tmpDir . '/work';
        \mkdir($work, 0755, true);
        $esc = \escapeshellarg($work);
        \file_put_contents($work . '/file.txt', "hello push\n");
        \exec("git -c init.defaultBranch=master init {$esc} 2>/dev/null && git -C {$esc} add . && git -C {$esc} -c user.email=test@example.com -c user.name=Test commit -m 'initial' 2>/dev/null");
        $sha = \trim((string) \shell_exec('git -C ' . \escapeshellarg($work) . ' rev-parse HEAD 2>/dev/null'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);

        return [$work, $sha];
    }

    private function packBytesFor(string $workPath, string $sha): string
    {
        $proc = \proc_open(
            ['git', '-C', $workPath, 'pack-objects', '--stdout', '--revs'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertNotFalse($proc);
        \fwrite($pipes[0], $sha . "\n");
        \fclose($pipes[0]);
        $pack = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
        $this->assertStringStartsWith('PACK', $pack);

        return $pack;
    }

    public function testRepoAccessor(): void
    {
        $repo = Repo::new('test', $this->emptyBareRepo());

        $rp = new ReceivePack($repo);
        $this->assertSame($repo, $rp->repo());
    }

    public function testServeRefusesWithoutWriteAccess(): void
    {
        $repo = Repo::new('test', $this->emptyBareRepo())->withPublic(true);
        // Null user: the anonymous daemon posture has no push rights, and
        // the gate must fire before any git child is spawned.
        $rp = new ReceivePack($repo, null);

        $this->assertSame(1, $rp->serve($this->memoryStream(), $this->memoryStream()));
    }

    public function testServeRefusesNonAdminOnPrivateRepo(): void
    {
        $repo = Repo::new('test', $this->emptyBareRepo())->withPrivate(true);
        $rp = new ReceivePack($repo, User::new('bob'));

        $this->assertSame(1, $rp->serve($this->memoryStream(), $this->memoryStream()));
    }

    /**
     * The C-04 corruption-killer pin: a real pkt-framed push with a real
     * packfile is applied by git itself — report-status says ok, the ref
     * moves, and the pushed objects actually exist in the target.
     */
    public function testServeAppliesARealPushAndMovesTheRef(): void
    {
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }

        $target = $this->emptyBareRepo();
        [$work, $sha] = $this->workRepoWithCommit();
        $zero = \str_repeat('0', 40);
        $request = PktLine::encode("{$zero} {$sha} refs/heads/pushed\x00report-status\n")
            . PktLine::FLUSH
            . $this->packBytesFor($work, $sha)
            . PktLine::FLUSH;

        $inPath = $this->tmpDir . '/push-in.bin';
        $outPath = $this->tmpDir . '/push-out.bin';
        \file_put_contents($inPath, $request);

        $rp = new ReceivePack(Repo::new('test', $target), User::new('pusher')->withAdmin(true));
        $in = \fopen($inPath, 'rb');
        $out = \fopen($outPath, 'wb');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        $this->assertSame(0, $rp->serve($in, $out));

        $bytes = (string) \file_get_contents($outPath);
        $this->assertStringContainsString('unpack ok', $bytes, 'git must unpack the pack');
        $this->assertStringContainsString('ok refs/heads/pushed', $bytes);

        $moved = \trim((string) \shell_exec('git --git-dir=' . \escapeshellarg($target) . ' rev-parse --verify refs/heads/pushed 2>/dev/null'));
        $this->assertSame($sha, $moved, 'the ref must point at the pushed commit');

        \exec('git --git-dir=' . \escapeshellarg($target) . ' cat-file -e ' . \escapeshellarg($sha) . ' 2>/dev/null', $ignored, $catRc);
        $this->assertSame(0, $catRc, 'the pushed objects must exist in the target');
    }

    /**
     * Anti-corruption pin: the retired hand-roll moved refs to whatever
     * the client claimed without ever seeing the objects. Real
     * receive-pack refuses a command whose objects are missing — the ref
     * must NOT exist afterwards.
     */
    public function testServeRefusesAForgedPushThatCarriesNoObjects(): void
    {
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }

        $target = $this->emptyBareRepo();
        // A well-formed commit object exists only in a separate work repo;
        // its sha is claimed here with NO packfile at all.
        [$work, $sha] = $this->workRepoWithCommit();
        $zero = \str_repeat('0', 40);
        $inPath = $this->tmpDir . '/forged-in.bin';
        $outPath = $this->tmpDir . '/forged-out.bin';
        \file_put_contents(
            $inPath,
            PktLine::encode("{$zero} {$sha} refs/heads/forged\x00report-status\n") . PktLine::FLUSH
        );

        $rp = new ReceivePack(Repo::new('test', $target), User::new('pusher')->withAdmin(true));
        $in = \fopen($inPath, 'rb');
        $out = \fopen($outPath, 'wb');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        $rp->serve($in, $out);

        $this->assertSame('', \trim((string) \shell_exec('git --git-dir=' . \escapeshellarg($target) . ' rev-parse --verify -q refs/heads/forged 2>/dev/null')), 'a forged command must never move a ref');
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
}
