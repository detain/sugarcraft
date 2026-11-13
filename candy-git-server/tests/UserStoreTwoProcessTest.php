<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\UserStore;

/**
 * S11 (finding 010) — the lost-update proof with REAL processes.
 *
 * The pre-S11 shape was an unlocked load-mutate-save-whole-file: two
 * overlapping `user add` runs both printed exit-0 success and the later
 * rename erased the earlier account — breaking the store's own "an
 * account exists exactly when its bytes are on disk" contract. Only a
 * cross-process stress reproduces it; a single process can't race with
 * itself. Each child runs the whole CLI (php bin), so the pins cover the
 * transaction wiring at its real call site, not just the class in
 * isolation.
 *
 * This is also the candy-git-server entry on the child-lifetime census
 * discipline: every handle started here is drained and proc_close'd
 * before the test ends, on the pass path AND the fail path.
 */
final class UserStoreTwoProcessTest extends TestCase
{
    private string $tmpDir;

    /** @var list<resource> open children to reap no matter what an assert does */
    private array $openProcs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-2proc-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->openProcs as $proc) {
            if (\is_resource($proc)) {
                @\proc_terminate($proc, \SIGKILL);
                @\proc_close($proc);
            }
        }
        $this->openProcs = [];

        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @return array{proc: resource, pipes: array<int, resource>} */
    private function startBin(array $args): array
    {
        $bin = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '20', \PHP_BINARY, $bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            [
                'CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir,
                'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            ]
        );
        $this->assertIsResource($proc);
        $this->openProcs[] = $proc;

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /** @param array{proc: resource, pipes: array<int, resource>} $child @return array{rc: int, out: string, err: string} */
    private function finish(array $child): array
    {
        $out = (string) \stream_get_contents($child['pipes'][1]);
        \fclose($child['pipes'][1]);
        $err = (string) \stream_get_contents($child['pipes'][2]);
        \fclose($child['pipes'][2]);
        $rc = \proc_close($child['proc']);
        foreach ($this->openProcs as $i => $proc) {
            if ($proc === $child['proc']) {
                unset($this->openProcs[$i]);
            }
        }

        return ['rc' => $rc, 'out' => $out, 'err' => $err];
    }

    public function testConcurrentDistinctAddsAllSurvive(): void
    {
        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $children[] = $this->startBin(['user', 'add', 'acct' . $i]);
        }
        $results = \array_map(fn (array $c): array => $this->finish($c), $children);

        foreach ($results as $i => $r) {
            $this->assertSame(0, $r['rc'], "child {$i} failed: {$r['err']}");
        }

        $store = new UserStore($this->tmpDir . '/users.json');
        for ($i = 0; $i < 6; $i++) {
            $this->assertNotNull(
                $store->find('acct' . $i),
                'every exit-0 add must be on disk — the old last-rename-wins shape lost all but one'
            );
        }
        $this->assertCount(6, $store->all());
    }

    public function testDuplicateRaceRefusesExactlyOneWriter(): void
    {
        $children = [];
        for ($i = 0; $i < 4; $i++) {
            $children[] = $this->startBin(['user', 'add', 'same-name']);
        }
        $results = \array_map(fn (array $c): array => $this->finish($c), $children);

        $successes = \count(\array_filter($results, static fn (array $r): bool => $r['rc'] === 0));
        $refusals = \array_filter($results, static fn (array $r): bool => $r['rc'] === 1);

        $this->assertSame(1, $successes, 'exactly one writer may claim the name');
        $this->assertCount(3, $refusals);
        foreach ($refusals as $r) {
            $this->assertStringContainsString('already exists', $r['err']);
        }

        $store = new UserStore($this->tmpDir . '/users.json');
        $this->assertCount(1, $store->all());
        $this->assertNotNull($store->find('same-name'));
    }

    public function testLockSidecarIsCreatedAndCarriesNoAuthMaterial(): void
    {
        // The sidecar exists while a transaction holds it; after the CLI
        // exits it is a 0600 zero-byte name (lock state is in the inode,
        // not the payload) — and the payload never lands in it.
        $r = $this->finish($this->startBin(['user', 'add', 'locked']));
        $this->assertSame(0, $r['rc'], $r['err']);

        $lock = $this->tmpDir . '/users.json.lock';
        $this->assertFileExists($lock, 'the transaction path must leave its sidecar behind as evidence');
        $this->assertSame('', (string) \file_get_contents($lock));
        if (\DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, \fileperms($lock) & 0777);
        }
    }
}
