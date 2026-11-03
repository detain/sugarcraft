<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;

/**
 * Pins for repository containment (review C-05) and the fail-closed daemon
 * posture (review M-16):
 *
 *  - '.' and '..' pass the old /^[a-zA-Z0-9._-]+$/ charset gate and, glued
 *    onto the repos root, address (or init) directories OUTSIDE the jail —
 *    reachable anonymously over the daemon and on-demand-creating over the
 *    SSH path. Repo::isJailSafeName rejects them at every choke point.
 *  - the git daemon protocol has no authentication, so the daemon must never
 *    serve git-receive-pack: "one config flag from default = unauthenticated
 *    writes" was exactly the pre-fix posture.
 *
 * The daemon dispatch seam is driven directly (private method via
 * reflection) with the client entry shaped exactly as acceptConnection()
 * builds it; the socket is a file:// handle so the written packet survives
 * closeClient()'s fclose and can be asserted.
 */
final class RepoJailContainmentTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/git-jail-test-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
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

    // -------------------------------------------------------------------------
    // Name validator table
    // -------------------------------------------------------------------------

    public function testJailSafeNameTable(): void
    {
        foreach (['foo', 'foo.git', 'a-b_c.1', 'a..b'] as $ok) {
            $this->assertTrue(Repo::isJailSafeName($ok), "$ok must be accepted");
        }
        foreach (['', '.', '..', '...', './x', '../x', 'a/b', 'a b', "a\nb", '-x'] as $bad) {
            $this->assertFalse(Repo::isJailSafeName($bad), \var_export($bad, true) . ' must be refused');
        }
    }

    // -------------------------------------------------------------------------
    // Daemon dispatch gate
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: array<int>} packet bytes written + remaining clients
     */
    private function driveDispatch(GitDaemon $daemon, string $buffer): array
    {
        $outFile = $this->tmpDir . '/wire-' . \uniqid();
        $socket  = \fopen('file://' . $outFile, 'wb');
        $this->assertIsResource($socket);

        $clients = new \ReflectionProperty(GitDaemon::class, 'clients');
        $clients->setAccessible(true);
        $clients->setValue($daemon, [
            0 => [
                'socket'        => $socket,
                'connected_at'  => \time(),
                'last_activity' => \time(),
                'buffer'        => $buffer,
                'handled'       => false,
            ],
        ]);

        $dispatch = new \ReflectionMethod($daemon, 'dispatchClientRequest');
        $dispatch->setAccessible(true);
        $dispatch->invoke($daemon, 0);

        /** @var array<int, array<string, mixed>> $after */
        $after = $clients->getValue($daemon);
        \clearstatcache();

        return [(string) \file_get_contents($outFile), \array_keys($after)];
    }

    public function testDaemonRefusesReceivePackWithNoSideEffects(): void
    {
        $daemon = new GitDaemon(Config::fromDefaults());

        [$bytes, $remaining] = $this->driveDispatch(
            $daemon,
            "git-receive-pack /dest\n" . \str_repeat('0', 40) . ' ' . \str_repeat('a', 40) . " refs/heads/master\n"
        );

        $this->assertStringContainsString('git-receive-pack is not served over the anonymous daemon', $bytes);
        $this->assertSame([], $remaining, 'refused client must be closed');
    }

    public function testDaemonRefusesTraversalRepoNameBeforeAnyLookup(): void
    {
        $daemon = new GitDaemon(Config::fromDefaults());

        [$bytes, $remaining] = $this->driveDispatch($daemon, "git-upload-pack /..\n");

        $this->assertStringContainsString('Invalid repository name', $bytes);
        $this->assertSame([], $remaining);
    }

    public function testDaemonRefusesDotRepoName(): void
    {
        $daemon = new GitDaemon(Config::fromDefaults());

        [$bytes, $remaining] = $this->driveDispatch($daemon, "git-upload-pack /.\n");

        $this->assertStringContainsString('Invalid repository name', $bytes);
        $this->assertSame([], $remaining);
    }

    public function testSshReceivePackCreateCannotClobberExistingRef(): void
    {
        // Review M-22: the create branch used to omit the old-value argument,
        // so a create racing an existing ref silently clobbered it. With CAS
        // (zeros old = 'must not exist') git refuses and the ref stands.
        $repoPath = $this->makeRepoWithMaster();
        $shaA     = $this->lastRef;
        $shaB     = $this->hashCommit($repoPath, 'B');

        $out = $this->pushProbe($repoPath, $shaB, 'master');

        $this->assertStringContainsString('RC:1', $out, 'clobbering create must fail');
        $this->assertStringContainsString('REF:' . $shaA, $out, 'master must still point at its original object');
    }

    public function testSshReceivePackRefusesEscapingRefName(): void
    {
        $repoPath = $this->makeRepoWithMaster();
        $shaB     = $this->hashCommit($repoPath, 'B');

        $out = $this->pushProbe($repoPath, $shaB, 'escape');

        $this->assertStringContainsString('RC:1', $out);
        $this->assertStringContainsString('invalid ref name', $out);
        // A loose ref file for '../escaped' would land beside the repo (git
        // resolves refs relative to the bare dir) — nothing may exist there.
        $this->assertFileDoesNotExist($repoPath . '/escaped');
        $this->assertFileDoesNotExist(\dirname($repoPath) . '/escaped');
    }

    private string $lastRef = '';

    private function makeRepoWithMaster(): string
    {
        $repoPath = $this->tmpDir . '/repo.git';
        \mkdir($repoPath, 0755, true);
        $this->git(['-c', 'init.defaultBranch=master', 'init', '--bare', $repoPath]);

        $shaA = $this->hashCommit($repoPath, 'A');
        $this->assertSame(0, $this->git(['update-ref', 'refs/heads/master', $shaA], $repoPath));
        $this->lastRef = $shaA;

        return $repoPath;
    }

    /**
     * Run ReceivePack::processPush() in a bounded php child (its packets and
     * diagnostics go through the STDOUT/STDERR constants, uncapturable in
     * process) and echo the resulting rc plus the ref target.
     */
    private function pushProbe(string $repoPath, string $shaB, string $refArg): string
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            $ref = $argv[4] === 'escape' ? '../escaped' : 'refs/heads/master';
            $repo = \SugarCraft\GitServer\Repo::new('probe', $argv[2]);
            $rp = new \SugarCraft\GitServer\Git\ReceivePack($repo, \SugarCraft\GitServer\User::new('alice')->withAdmin(true));
            $m = (new ReflectionClass($rp))->getMethod('processPush');
            $m->setAccessible(true);
            $rc = $m->invoke($rp, [['old' => str_repeat('0', 40), 'new' => $argv[3], 'ref' => $ref]]);
            echo 'RC:' . $rc . "\n";
            $out = [];
            exec('git -C ' . escapeshellarg($argv[2]) . ' rev-parse --verify refs/heads/master 2>/dev/null', $out);
            echo 'REF:' . trim($out[0] ?? '') . "\n";
            PHP;

        $file = $this->tmpDir . '/push-probe.php';
        $this->assertNotFalse(\file_put_contents($file, $script));
        $autoload = \realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertNotFalse($autoload);

        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '20', \PHP_BINARY, $file, $autoload, $repoPath, $shaB, $refArg],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($proc), 'probe child must terminate cleanly; stderr: ' . $stderr);

        return $stdout . $stderr;
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args, ?string $cwd = null): int
    {
        $cmd = \array_merge(['git'], $cwd === null ? $args : \array_merge(['-C', $cwd], $args));
        $out = [];
        $rc  = 0;
        \exec(\implode(' ', \array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $rc);

        return $rc;
    }

    /**
     * Build a real commit object (empty tree parentless commit) written into
     * $repoPath's object store — git refuses blob targets on branch refs, so
     * ref-move probes need commits. Message text varies the sha.
     */
    private function hashCommit(string $repoPath, string $message): string
    {
        $emptyTree = \trim((string) \shell_exec('git -C ' . \escapeshellarg($repoPath) . ' hash-object -t tree /dev/null 2>/dev/null'));
        $this->assertSame(1, \preg_match('/^[0-9a-f]{40}$/', $emptyTree));

        $desc = ['pipe', 'w'];
        $env  = \array_merge(getenv(), [
            'GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@e',
            'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@e',
        ]);
        $proc = \proc_open(
            ['git', '-C', $repoPath, 'commit-tree', $emptyTree, '-m', $message],
            [0 => $desc, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertIsResource($proc);
        \fclose($pipes[0]);
        $sha = \trim((string) \stream_get_contents($pipes[1]));
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($proc));
        $this->assertSame(1, \preg_match('/^[0-9a-f]{40}$/', $sha));

        return $sha;
    }
}
