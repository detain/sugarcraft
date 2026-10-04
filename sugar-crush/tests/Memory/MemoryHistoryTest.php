<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\MemoryHistory;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.4-2: the home memory directory as its own git repository.
 */
final class MemoryHistoryTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $dir = '';

    protected function setUp(): void
    {
        if (!MemoryHistory::available()) {
            self::markTestSkipped('git is not on PATH');
        }

        $this->sandbox = sys_get_temp_dir() . '/crush-memhistory-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->dir = $this->sandbox . '/memory';
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testTheFirstCommitStartsTheRepositoryAndAnUnchangedTreeCommitsNothing(): void
    {
        $history = MemoryHistory::new($this->dir);
        $this->assertFalse($history->isInitialized());
        $this->assertSame([], $history->log(), 'no repository, no history');

        file_put_contents($this->dir . '/note.md', "one\n");
        $sha = $history->commit('memory: first');

        $this->assertNotNull($sha);
        $this->assertTrue($history->isInitialized());
        $this->assertFileExists($this->dir . '/.gitignore');
        $this->assertNull($history->commit('memory: nothing'), 'an unchanged tree is not a commit');

        $log = $history->log();
        $this->assertSame(
            ['memory: first', MemoryHistory::START_SUBJECT],
            array_map(static fn($r): string => $r->subject, $log),
            'the start commit holds only the ignore rules; the notes are the caller\'s commit',
        );
        $this->assertStringStartsWith($log[0]->shortSha, $log[0]->sha);
    }

    public function testTheLogIsNewestFirstAndBounded(): void
    {
        $history = MemoryHistory::new($this->dir);
        foreach (['a', 'b', 'c'] as $name) {
            file_put_contents("{$this->dir}/{$name}.md", $name);
            $history->commit("memory: {$name}");
        }

        $this->assertSame(['memory: c', 'memory: b', 'memory: a', MemoryHistory::START_SUBJECT], array_map(
            static fn($r): string => $r->subject,
            $history->log(),
        ));
        $this->assertCount(2, $history->log(2));
    }

    public function testRestorePutsTheTreeBackAsANewCommitAndRegeneratesTheIndex(): void
    {
        $store = new MemoryStore($this->dir);
        $history = MemoryHistory::forStore($store);
        $this->assertInstanceOf(MemoryHistory::class, $history);

        $kept = $store->add('kept note', 'user');
        $first = (string) $history->commit('memory: first');

        $added = $store->add('added later', 'user');
        $entry = $store->get($kept);
        $this->assertNotNull($entry);
        $history->commit('memory: second');
        // An uncommitted change and an ignored cache when the restore runs.
        file_put_contents($this->dir . '/user/stray.md', 'stray');
        file_put_contents($this->dir . '/.search.sqlite', 'cache');

        $restored = $history->restore($first);

        $this->assertNotNull($restored);
        $this->assertNull($store->get($added), 'a note added after the commit is gone');
        $this->assertNotNull($store->get($kept));
        $this->assertFileDoesNotExist($this->dir . '/user/stray.md', 'the stray file was committed, then replaced');
        $this->assertSame('cache', file_get_contents($this->dir . '/.search.sqlite'), 'ignored files are left alone');

        $index = (string) $store->loadIndex('user');
        $this->assertStringContainsString('kept note', $index);
        $this->assertStringNotContainsString('added later', $index, 'the index describes the restored notes');

        $this->assertSame(
            ['memory: restore to ' . substr($this->fullSha($first), 0, 12), 'memory: snapshot before restore', 'memory: second', 'memory: first', MemoryHistory::START_SUBJECT],
            array_map(static fn($r): string => $r->subject, $history->log()),
            'a restore is a new commit, and what it replaced is still in the history',
        );

        foreach (glob($this->dir . '/user/*') ?: [] as $file) {
            $this->assertSame('600', decoct(fileperms($file) & 0o777), basename($file) . ' must stay owner-only');
        }

        $this->assertNull($history->restore($restored), 'restoring the state on disk changes nothing');
    }

    /** @return iterable<string, array{string}> */
    public static function badRevisions(): iterable
    {
        yield 'a ref' => ['HEAD'];
        yield 'an option' => ['--all'];
        yield 'a range' => ['abcd..ef01'];
        yield 'not hex' => ['zzzz'];
        yield 'too short' => ['abc'];
    }

    /** @dataProvider badRevisions */
    public function testRestoreTakesOnlyAHexCommitId(string $revision): void
    {
        $history = MemoryHistory::new($this->dir);
        file_put_contents($this->dir . '/note.md', 'x');
        $history->commit('memory: first');

        $this->expectException(\InvalidArgumentException::class);
        $history->restore($revision);
    }

    public function testAnUnknownCommitIsRefused(): void
    {
        $history = MemoryHistory::new($this->dir);
        file_put_contents($this->dir . '/note.md', 'x');
        $history->commit('memory: first');

        $this->expectException(\InvalidArgumentException::class);
        $history->restore('deadbeef');
    }

    public function testARepositoryStoreHasNoHistory(): void
    {
        $this->assertNull(MemoryHistory::forStore(MemoryStore::forRepository($this->dir)));
    }

    public function testTheRepositoryIsPinnedNeverDiscovered(): void
    {
        // A home directory that is itself a git repository (dotfiles): the
        // memory directory inside it must get its OWN repository, and the
        // outer one must not have the notes staged into it.
        $outer = $this->sandbox . '/outer';
        mkdir($outer . '/memory', 0700, true);
        exec('git -C ' . escapeshellarg($outer) . ' init -q 2>&1', $out, $code);
        $this->assertSame(0, $code);

        file_put_contents($outer . '/memory/note.md', 'private');
        MemoryHistory::new($outer . '/memory')->commit('memory: first');

        $this->assertDirectoryExists($outer . '/memory/.git');
        exec('git -C ' . escapeshellarg($outer) . ' diff --cached --name-only 2>&1', $staged);
        $this->assertSame([], $staged, 'nothing was staged into the enclosing repository');
    }

    private function fullSha(string $short): string
    {
        exec('git -C ' . escapeshellarg($this->dir) . ' rev-parse ' . escapeshellarg($short), $out);

        return trim($out[0] ?? '');
    }
}
