<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Git\GitDaemon;
use SugarCraft\Serve\Git\ReceivePack;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\User;

/**
 * Regression coverage for the git-daemon receive-pack corruption fix: the
 * daemon now index-packs the pushed objects into the repo BEFORE moving any
 * ref, so refs can never point at objects the repo lacks.
 *
 * These tests drive handleReceivePack() directly with a real packfile built
 * by `git pack-objects`, then assert the pushed OBJECTS are actually
 * retrievable — the assertion the audit says was missing and that would have
 * caught the "updates refs without unpacking" bug.
 *
 * @covers \SugarCraft\Serve\Git\GitDaemon
 */
final class GitDaemonReceivePackTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/git-daemon-recv-test-' . \uniqid();
        \mkdir($this->tmpDir . '/repositories', 0755, true);
        $this->config = Config::fromDefaults();
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
     * Build a source repo with one commit and return its object shas plus a
     * self-contained packfile of everything reachable from the commit — the
     * bytes a client would send after the ref-update command.
     *
     * @return array{commit: string, tree: string, blob: string, pack: string}
     */
    private function buildPush(): array
    {
        $work = $this->tmpDir . '/work';
        \mkdir($work, 0755, true);
        $w = \escapeshellarg($work);
        \exec("git -c init.defaultBranch=master init {$w} 2>/dev/null");
        \file_put_contents($work . '/f.txt', "hello\n");
        \exec("git -C {$w} add f.txt 2>/dev/null");
        \exec("git -C {$w} -c user.email=t@e.com -c user.name=T commit -qm init 2>/dev/null");

        $commit = \trim((string) \shell_exec("git -C {$w} rev-parse HEAD"));
        $tree = \trim((string) \shell_exec("git -C {$w} rev-parse HEAD^{tree}"));
        $blob = \trim((string) \shell_exec("git -C {$w} rev-parse HEAD:f.txt"));

        // Self-contained (non-thin) pack of the commit's whole graph.
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open("git -C {$w} pack-objects --stdout --revs 2>/dev/null", $desc, $pipes);
        \fwrite($pipes[0], $commit . "\n");
        \fclose($pipes[0]);
        $pack = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        return ['commit' => $commit, 'tree' => $tree, 'blob' => $blob, 'pack' => $pack];
    }

    private function initBareRepo(string $name): Repo
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path, 0755, true);
        \exec('git -c init.defaultBranch=master init --bare ' . \escapeshellarg($path) . ' 2>/dev/null');

        return Repo::new($name, $path);
    }

    /**
     * Invoke the private handleReceivePack() with a request buffer written to
     * a temp stdin file and a php://temp stand-in for the client socket.
     */
    private function driveReceivePack(GitDaemon $daemon, Repo $repo, string $buffer): void
    {
        $stdinFile = $this->tmpDir . '/recv-stdin-' . \uniqid();
        \file_put_contents($stdinFile, $buffer);

        $socket = \fopen('php://temp', 'r+');
        $this->assertIsResource($socket);

        try {
            $handler = new ReceivePack($repo, User::new('alice')->withAdmin(true));
            $m = new \ReflectionMethod($daemon, 'handleReceivePack');
            $m->setAccessible(true);
            $m->invoke($daemon, $socket, $handler, $stdinFile);
        } finally {
            \fclose($socket);
            @\unlink($stdinFile);
        }
    }

    private function gitRc(string $repoPath, string $args): int
    {
        $out = [];
        $rc = 0;
        \exec('git -C ' . \escapeshellarg($repoPath) . ' ' . $args . ' 2>/dev/null', $out, $rc);
        return $rc;
    }

    // -------------------------------------------------------------------------
    // Objects must land in the repo (the corruption regression)
    // -------------------------------------------------------------------------

    public function testReceivePackUnpacksObjectsSoTheyAreRetrievable(): void
    {
        $push = $this->buildPush();
        $repo = $this->initBareRepo('dest');
        $daemon = new GitDaemon($this->config);
        $daemon->registerRepo($repo);

        // Sanity: the freshly-created dest repo does not have the object yet.
        $this->assertNotSame(0, $this->gitRc($repo->path(), 'cat-file -e ' . $push['commit']));

        // create command (old = zeros) + flush + real packfile
        $buffer = "git-receive-pack /dest\n"
            . \str_repeat('0', 40) . ' ' . $push['commit'] . " refs/heads/master\n"
            . "0000"
            . $push['pack'];

        $this->driveReceivePack($daemon, $repo, $buffer);

        // The pushed objects are now actually retrievable — this is the
        // assertion that fails against the pre-fix "update refs without
        // unpacking" code.
        $this->assertSame(0, $this->gitRc($repo->path(), 'cat-file -e ' . $push['commit']), 'commit object missing');
        $this->assertSame(0, $this->gitRc($repo->path(), 'cat-file -e ' . $push['tree']), 'tree object missing');
        $this->assertSame(0, $this->gitRc($repo->path(), 'cat-file -e ' . $push['blob']), 'blob object missing');

        // The full object graph is present and connected, and the ref moved.
        $head = \trim((string) \shell_exec('git -C ' . \escapeshellarg($repo->path()) . ' rev-parse refs/heads/master 2>/dev/null'));
        $this->assertSame($push['commit'], $head);

        // Walking the ref reaches every reachable object (commit + tree + blob);
        // this only succeeds because all three landed in the object store.
        $count = \trim((string) \shell_exec('git -C ' . \escapeshellarg($repo->path()) . ' rev-list --objects --count refs/heads/master 2>/dev/null'));
        $this->assertSame('3', $count, 'ref must be walkable (all reachable objects present)');
    }

    // -------------------------------------------------------------------------
    // A create/update with no packfile must be rejected, not silently applied
    // -------------------------------------------------------------------------

    public function testReceivePackWithoutPackfileDoesNotMoveRef(): void
    {
        $push = $this->buildPush();
        $repo = $this->initBareRepo('nopack');
        $daemon = new GitDaemon($this->config);
        $daemon->registerRepo($repo);

        // create command but NO packfile follows
        $buffer = "git-receive-pack /nopack\n"
            . \str_repeat('0', 40) . ' ' . $push['commit'] . " refs/heads/master\n";

        $this->driveReceivePack($daemon, $repo, $buffer);

        // Neither the object nor the ref may exist — the push had no objects.
        $this->assertNotSame(0, $this->gitRc($repo->path(), 'cat-file -e ' . $push['commit']));
        $this->assertNotSame(0, $this->gitRc($repo->path(), 'rev-parse --verify refs/heads/master'));
    }
}
