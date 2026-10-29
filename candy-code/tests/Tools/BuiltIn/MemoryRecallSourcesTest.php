<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Memory\CompactionJournal;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionSearchIndex;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\MemoryTool;

/**
 * C-CTX-3.d: `recall`'s three sources. The `notes` leg must answer byte-identically
 * to before `source` existed; the `sessions` leg must scope hits to the launch
 * root's `cwd`, exclude the current session when the turn bound one, and never
 * leave a secret in a snippet; the `journal` leg answers through the bounded
 * read view, not the raw journal.
 *
 * @see MemoryTool
 * @see SessionSearchIndex::searchScoped()
 * @see CompactionJournal::recentView()
 */
final class MemoryRecallSourcesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    /** The launch root as the catalog passes it: the raw string, unmodified. */
    private string $rootA;

    private string $rootB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/crush_ctz3d_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->rootA = $this->dir . '/proj-a';
        $this->rootB = $this->dir . '/proj-b';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHomeSandbox();
        $this->disposeTree($this->dir);
    }

    /** Stack-ordered teardown of this test's temp tree; known paths only. */
    private function disposeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $stack = [$path];
        $dirs = [];
        while ($stack !== []) {
            $current = (string) array_pop($stack);
            foreach (scandir($current) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $current . '/' . $name;
                if (is_dir($child)) {
                    $stack[] = $child;
                    $dirs[] = $child;
                } else {
                    @unlink($child);
                }
            }
        }
        for ($i = count($dirs) - 1; $i >= 0; $i--) {
            @rmdir($dirs[$i]);
        }
        @rmdir($path);
    }

    private function writerFor(string $homeDir, string $root): MemoryWriter
    {
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0700, true);
        }

        return MemoryWriter::new(new MemoryStore($homeDir), $root);
    }

    /** @param array<string, mixed> $args */
    private function runTool(MemoryTool $tool, array $args): string
    {
        return $tool->execute($args)->content();
    }

    /**
     * A store at the HOME sandbox's session.db with three zebrafish sessions:
     * one in root A marked current, one older in root A, one in root B.
     */
    private function seedHomeSessions(string $homeDir): void
    {
        $dbPath = $homeDir . '/.candy-code/session.db';
        $store = new EnhancedSessionStore($dbPath);
        $store->createSession('cur-sess', 'p', 'm', null, null, $this->rootA);
        $store->createSession('past-a', 'p', 'm', null, null, $this->rootA);
        $store->createSession('past-b', 'p', 'm', null, null, $this->rootB);
        $store->saveTranscript('cur-sess', [['role' => 'user', 'content' => 'zebrafish is also discussed right here']]);
        $store->saveTranscript('past-a', [['role' => 'user', 'content' => 'we moved the zebrafish pool and stored sk-abcdefghijklmnopqrstuvwx after']]);
        $store->saveTranscript('past-b', [['role' => 'user', 'content' => 'zebrafish lives elsewhere']]);

        // The build marker on a second, loud-failing connection: rows the saves
        // synced are only answerable once the index is vouched for.
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1);
        SessionSearchIndex::create($pdo)->markBuilt();
    }

    /**
     * Nine zebrafish sessions, all of this test's root A, none of them the
     * current session — a pile one taller than the row cap so the cap is the
     * only thing that can answer "eight".
     */
    private function seedNineRootASessions(string $dbPath): void
    {
        $store = new EnhancedSessionStore($dbPath);
        for ($i = 1; $i <= 9; $i++) {
            $store->createSession('many-' . $i, 'p', 'm', null, null, $this->rootA);
            $store->saveTranscript('many-' . $i, [['role' => 'user', 'content' => 'the zebrafish pool note number ' . $i]]);
        }

        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1);
        SessionSearchIndex::create($pdo)->markBuilt();
    }

    /**
     * The answer's hit rows only — every line the tool prefixes with a bracketed
     * session id. The framing header is not one, so the count is a row census.
     *
     * @return list<string>
     */
    private function hitRows(string $answer): array
    {
        return array_values(array_filter(
            explode("\n", $answer),
            static fn (string $line): bool => str_starts_with($line, '- ['),
        ));
    }

    public function testTheSchemaGainsTheSourcePropertyWithoutMovingAnything(): void
    {
        $schema = (new MemoryTool())->inputSchema();

        self::assertSame(['notes', 'sessions', 'journal'], $schema['properties']['source']['enum']);
        self::assertSame(['action'], $schema['required']);
        self::assertSame(['view', 'save', 'str_replace', 'delete', 'recall'], $schema['properties']['action']['enum']);
    }

    public function testTheDefaultSourceAnswersExactlyAsNotesAlwaysDid(): void
    {
        $tool = new MemoryTool($this->writerFor($this->dir . '/home-notes', $this->rootA));
        self::assertStringContainsString('Saved note', $this->runTool($tool, [
            'action' => 'save',
            'content' => 'the zebrafish protocol, kept for the recall test',
        ]));

        $bare = $this->runTool($tool, ['action' => 'recall', 'query' => 'zebrafish']);
        $explicit = $this->runTool($tool, ['action' => 'recall', 'query' => 'zebrafish', 'source' => 'notes']);
        self::assertSame($bare, $explicit);
        self::assertStringContainsString('[scope: ', $bare);

        $miss = $this->runTool($tool, ['action' => 'recall', 'query' => 'aardvarks']);
        self::assertSame("No memory note matches 'aardvarks'.", $miss);
    }

    public function testAnUnknownSourceRefusesInsteadOfFallingBackToNotes(): void
    {
        $tool = new MemoryTool($this->writerFor($this->dir . '/home-bad', $this->rootA));

        $refused = $this->runTool($tool, ['action' => 'recall', 'query' => 'zebrafish', 'source' => 'everything']);
        self::assertSame('Error: source must be one of notes, sessions, journal', $refused);

        $wrongType = $tool->execute(['action' => 'recall', 'query' => 'zebrafish', 'source' => ['notes']]);
        self::assertTrue($wrongType->isError());
        self::assertSame('Error: source must be a string', $wrongType->content());
    }

    public function testWithoutAWriterEveryPathButSessionsKeepsTheBytePinnedRefusal(): void
    {
        $tool = new MemoryTool();

        self::assertSame('Error: no memory store is configured for this session', $this->runTool($tool, ['action' => 'view']));
        self::assertSame(
            'Error: no memory store is configured for this session',
            $this->runTool($tool, ['action' => 'recall', 'query' => 'zebrafish']),
        );
        self::assertSame(
            'Error: no memory store is configured for this session',
            $this->runTool($tool, ['action' => 'recall', 'query' => 'zebrafish', 'source' => 'journal']),
        );
    }

    public function testSessionsRecallIsScopedToTheRootExcludesTheCurrentSessionAndRedacts(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home') . '/.candy-code';
        mkdir($home, 0700);
        $this->seedHomeSessions(dirname($home));

        $bound = new MemoryTool(null, $this->rootA, 'cur-sess');
        $answer = $this->runTool($bound, ['action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions']);

        self::assertStringContainsString('untrusted data', $answer);
        self::assertStringContainsString('[past-a]', $answer);
        self::assertStringNotContainsString('cur-sess', $answer);
        self::assertStringNotContainsString('past-b', $answer);
        self::assertStringContainsString('[REDACTED]', $answer);
        self::assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwx', $answer);

        // A different root of the same store answers "no match", not "the other
        // project's session": the cwd filter, not the ranking, picked past-a.
        $elsewhere = $this->runTool(new MemoryTool(null, $this->rootB, 'cur-sess'), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertStringContainsString('[past-b]', $elsewhere);
        self::assertStringNotContainsString('[past-a]', $elsewhere);
    }

    public function testTheExclusionOnlyHoldsWhenTheTurnBoundASession(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home') . '/.candy-code';
        mkdir($home, 0700);
        $this->seedHomeSessions(dirname($home));

        // The pair pin: same store, same query — an unbound tool keeps the
        // current session, so every leg of the discriminating assertion above
        // is load-bearing on the binding, not on data absence.
        $unbound = $this->runTool(new MemoryTool(null, $this->rootA), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertStringContainsString('[cur-sess]', $unbound);
    }

    public function testSessionsRecallNamesTheMissingPieceInsteadOfGuessing(): void
    {
        $noRoot = $this->runTool(new MemoryTool(null, null, 'cur-sess'), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertSame('Session recall needs a project root; this launch has none.', $noRoot);

        // A wordless query refuses before the store is even opened.
        $wordless = new MemoryTool(null, $this->rootA);
        self::assertSame(
            'Error: query needs at least one indexable word',
            $this->runTool($wordless, ['action' => 'recall', 'query' => '???', 'source' => 'sessions']),
        );

        // A fresh sandbox home: Bootstrap mints an empty store, and an empty
        // store is an index that has not been built — never a guess of hits.
        $this->useHomeSandbox($this->dir . '/home-empty');
        $noStore = $this->runTool(new MemoryTool(null, $this->rootA), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertStringContainsString('has not been built yet', $noStore);
    }

    public function testAStoreWithoutTheBuildMarkerIsNotRead(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home') . '/.candy-code';
        mkdir($home, 0700);
        $store = new EnhancedSessionStore($home . '/session.db');
        $store->createSession('past-a', 'p', 'm', null, null, $this->rootA);
        $store->saveTranscript('past-a', [['role' => 'user', 'content' => 'zebrafish was here']]);
        // Deliberately NO markBuilt(): rows may exist and still not be a built
        // index — reading them as if they were is how a half-build answers wrong.

        $answer = $this->runTool(new MemoryTool(null, $this->rootA), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertStringContainsString('has not been built yet', $answer);
    }

    /**
     * The row cap's edge: nine matching sessions of this root exist, the answer
     * carries eight rows. The count is the LITERAL eight (`MemoryTool`'s private
     * `MAX_SESSION_HITS`), not a re-read of the constant — a pin that derived its
     * own bound from the shipped cap would stay green when the cap is widened.
     */
    public function testNineMatchingSessionsAnswerExactlyEightRows(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home-hits') . '/.candy-code';
        mkdir($home, 0700);
        $this->seedNineRootASessions($home . '/session.db');

        // The data leg, proved before the cap leg: the store really holds nine
        // distinct matches, so eight rows is the cap speaking, not thin fixture.
        $index = SessionSearchIndex::openForReadOnly($home . '/session.db');
        self::assertNotNull($index);
        self::assertCount(9, $index->searchScoped('zebrafish', $this->rootA, null, 9));

        $answer = $this->runTool(new MemoryTool(null, $this->rootA), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]);
        self::assertCount(8, $this->hitRows($answer));
    }

    /**
     * The snippet cap's edge: a body long enough that the FTS snippet passes 600
     * bytes arrives clipped — the row ends in the ellipsis marker and its snippet
     * is 600 bytes plus the 3-byte marker. 603 is the literal, for the same
     * reason 8 above is: neutering `MAX_SESSION_SNIPPET_BYTES` must redden this.
     */
    public function testAnOverlongBodyIsClippedAtTheSnippetEdge(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home-wide') . '/.candy-code';
        mkdir($home, 0700);
        $store = new EnhancedSessionStore($home . '/session.db');
        $store->createSession('zwide', 'p', 'm', null, null, $this->rootA);
        $store->saveTranscript('zwide', [[
            'role' => 'user',
            'content' => 'zebrafish ' . str_repeat('q', 700),
        ]]);
        $pdo = new \PDO('sqlite:' . $home . '/session.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 1);
        SessionSearchIndex::create($pdo)->markBuilt();

        $rows = $this->hitRows($this->runTool(new MemoryTool(null, $this->rootA), [
            'action' => 'recall', 'query' => 'zebrafish', 'source' => 'sessions',
        ]));
        self::assertCount(1, $rows);
        self::assertStringEndsWith('...', $rows[0]);

        $snippet = substr($rows[0], (int) strpos($rows[0], '] > ') + 4);
        self::assertSame(603, strlen($snippet));
        // The negative half: the body that was indexed is 712 bytes of q's and
        // none of that surplus reaches the row. Without the clip this line fails.
        self::assertStringNotContainsString(str_repeat('q', 700), $rows[0]);
    }

    public function testTheJournalViewKeepsTheNewestEntriesInsideTheBudget(): void
    {
        $journal = CompactionJournal::at($this->dir . '/journal.jsonl', 'proj');
        for ($i = 1; $i <= 15; $i++) {
            self::assertTrue($journal->append('c' . $i, ['entry' => sprintf('e%02d ', $i) . str_repeat('x', 3000)]));
        }

        $view = $journal->recentView();
        self::assertLessThanOrEqual(CompactionJournal::VIEW_MAX_ENTRIES, count($view));

        $bytes = 0;
        $tokens = [];
        foreach ($view as $entry) {
            self::assertNull($entry['state']);
            foreach ($entry['records'] as $record) {
                $bytes += strlen($record);
                $tokens[] = substr($record, 0, 3);
            }
        }
        self::assertLessThanOrEqual(CompactionJournal::VIEW_MAX_BYTES, $bytes);
        // Oldest-first among what survived, and the survivors are the NEWEST
        // tail: 3000-byte entries fit two under a 6000-byte budget.
        self::assertSame(['e14', 'e15'], $tokens);
    }

    /**
     * The entry-count cap on its own, with the byte budget made unable to bind:
     * eleven entries of a few bytes each fit 6000 bytes many times over, so the
     * only thing that can drop one is `VIEW_MAX_ENTRIES`. The eviction the code
     * performs is the OLDEST (the view reads the newest tail, oldest-first), so
     * e11 is present and e1 is gone.
     */
    public function testElevenTinyEntriesAreCappedByCountNotBytes(): void
    {
        $journal = CompactionJournal::at($this->dir . '/tiny.jsonl', 'proj');
        for ($i = 1; $i <= 11; $i++) {
            self::assertTrue($journal->append('c' . $i, ['entry' => sprintf('e%d ', $i) . str_repeat('t', 20)]));
        }

        $view = $journal->recentView();
        self::assertCount(10, $view);

        $tokens = [];
        $bytes = 0;
        foreach ($view as $entry) {
            foreach ($entry['records'] as $record) {
                $bytes += strlen($record);
                $tokens[] = strtok($record, ' ');
            }
        }
        // Every entry is tiny, so the byte budget never fires: 10 x ~24 bytes
        // against 6000. The survivors are the newest ten, oldest-first.
        self::assertLessThan(CompactionJournal::VIEW_MAX_BYTES, $bytes);
        self::assertSame(['e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'e8', 'e9', 'e10', 'e11'], $tokens);
    }

    public function testJournalRecallAnswersThroughTheBoundedViewAndFramesIt(): void
    {
        $writer = $this->writerFor($this->dir . '/home-journal', $this->rootA);

        $empty = $this->runTool(new MemoryTool($writer, $this->rootA), ['action' => 'recall', 'source' => 'journal']);
        self::assertSame('No compaction journal entries for this project yet.', $empty);

        $journal = CompactionJournal::forStore($writer->home());
        self::assertTrue($journal->append('c1', ['plan' => 'zebrafish pool plan', 'leak' => 'token sk-abcdefghijklmnopqrstuvwx']));
        self::assertTrue($journal->append('c2', ['next' => 'feed the zebrafish'], ['dream']));

        $answer = $this->runTool(new MemoryTool($writer, $this->rootA), ['action' => 'recall', 'source' => 'journal']);
        self::assertStringContainsString('untrusted data', $answer);
        self::assertStringContainsString('zebrafish pool plan', $answer);
        self::assertStringContainsString('feed the zebrafish', $answer);
        self::assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwx', $answer);
        self::assertStringContainsString('[REDACTED]', $answer);
    }

    public function testTheRebindingsCarryTheRootAndTheBoundSession(): void
    {
        $writer = $this->writerFor($this->dir . '/home-rebind', $this->rootA);
        $tool = (new MemoryTool($writer, $this->rootA, 'cur-sess'))->withShellOwner('other-sess');
        $root = new \ReflectionProperty(MemoryTool::class, 'root');
        $ref = new \ReflectionProperty(MemoryTool::class, 'sessionRef');
        self::assertSame($this->rootA, $root->getValue($tool));
        self::assertSame('other-sess', $ref->getValue($tool));

        $rebound = $tool->withWriter(null);
        self::assertSame($this->rootA, $root->getValue($rebound));
        self::assertSame('other-sess', $ref->getValue($rebound));
    }
}
