<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Memory\MemoryHistory;
use SugarCraft\Code\Memory\MemoryRevision;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Methods\MemoryMethods;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-020: the `memory.*` surface is routed through MemoryWriter, the same
 * router `/memory` and the `Memory` tool use. A repo-local project note is
 * visible in list and search with its source badged, an id that `memory.add`
 * answered with resolves again through `memory.edit`/`memory.delete` against
 * the OWNING store, a project note the repository could not host is reported
 * as a home fallback rather than silently private, and each mutation commits
 * the home history under `memory: panel <verb>`.
 */
final class MemoryMethodsTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    private MemoryStore $home;

    private string $homeRoot = '';

    protected function setUp(): void
    {
        $this->homeRoot = \sys_get_temp_dir() . '/iss020-home-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        $storeDir = $this->homeRoot . '/memory';
        \mkdir($storeDir, 0o700, true);
        $this->home = new MemoryStore($storeDir);

        $this->fixture = ProtocolFixture::new(memoryStore: $this->home);
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->purgeTree($this->homeRoot);
    }

    // ── reads span both stores ─────────────────────────────────────────

    public function testProjectListingShowsRepositoryNotesBadgedBySource(): void
    {
        $shared = ProjectMemoryWriter::createForRoot($this->fixture->root);
        self::assertNotNull($shared, 'the fixture root must be able to host the repo store');
        $shared->write('use pnpm in this repo');
        $this->home->add('my private editor theme', 'project');

        $items = $this->listItems('project');

        self::assertCount(2, $items);
        self::assertSame('use pnpm in this repo', $items[0]['content']);
        self::assertSame(MemoryMethods::SOURCE_REPO, $items[0]['source']);
        self::assertSame('my private editor theme', $items[1]['content']);
        self::assertSame(MemoryMethods::SOURCE_HOME, $items[1]['source']);
    }

    public function testUserScopeListingReadsOnlyTheHomeStore(): void
    {
        $shared = ProjectMemoryWriter::createForRoot($this->fixture->root);
        self::assertNotNull($shared);
        $shared->write('repo note');
        $this->home->add('user note', 'user');

        $items = $this->listItems('user');

        self::assertCount(1, $items);
        self::assertSame('user note', $items[0]['content']);
        self::assertSame(MemoryMethods::SOURCE_HOME, $items[0]['source']);
    }

    public function testSearchCoversBothStores(): void
    {
        $shared = ProjectMemoryWriter::createForRoot($this->fixture->root);
        self::assertNotNull($shared);
        $shared->write('deploy staging with the repo runner');
        $this->home->add('deploy staging with the home runner');

        $items = $this->client()->call('memory.search', ['query' => 'deploy staging'])['items'];

        $contents = array_column($items, 'content');
        self::assertContains('deploy staging with the repo runner', $contents);
        self::assertContains('deploy staging with the home runner', $contents);
        $sources = array_column($items, 'source');
        self::assertContains(MemoryMethods::SOURCE_REPO, $sources);
        self::assertContains(MemoryMethods::SOURCE_HOME, $sources);
    }

    // ── writes route like /memory add ──────────────────────────────────

    public function testAddingAProjectNoteLandsInTheRepositoryStoreAndResolvesAgain(): void
    {
        $client = $this->client();

        $result = $client->call('memory.add', ['content' => 'ship behind a flag', 'scope' => 'project']);

        self::assertSame(MemoryMethods::SOURCE_REPO, $result['source']);
        self::assertFalse($result['fellBackToHome']);
        // The pre-fix defect: this id lived only in the repo store, so the
        // old home-only edit/delete answered "no note".
        $edited = $client->call('memory.edit', ['id' => $result['id'], 'content' => 'ship behind two flags']);
        self::assertSame($result['id'], $edited['id']);

        $repo = ProjectMemoryWriter::forRoot($this->fixture->root)?->store();
        self::assertNotNull($repo);
        self::assertSame('ship behind two flags', $repo->get($result['id'])?->content());
        self::assertSame([], $this->home->list('project'), 'the home store must hold no copy of a repo-routed note');

        $deleted = $client->call('memory.delete', ['id' => $result['id']]);
        self::assertTrue($deleted['deleted']);
        self::assertNull($repo->get($result['id']));
    }

    public function testAProjectNoteTheTreeCannotHostReportsTheHomeFallback(): void
    {
        // `.candy-code` planted as a symlink out of the tree: the writer's
        // containment gate refuses it, so the note degrades to home — and
        // the panel must be able to SEE that, per 15d-05's never-silent rule.
        $outside = $this->homeRoot . '/escape';
        \mkdir($outside, 0o700, true);
        self::assertTrue(symlink($outside, $this->fixture->root . '/.candy-code'));

        $result = $this->client()->call('memory.add', ['content' => 'quiet note', 'scope' => 'project']);

        self::assertSame(MemoryMethods::SOURCE_HOME, $result['source']);
        self::assertTrue($result['fellBackToHome']);
        self::assertNotSame([], $this->home->list('project'));
        self::assertSame(glob($outside . '/*.md') ?: [], [], 'no note file may be written through the planted symlink');
    }

    // ── typed refusals (gap-minor N-3) ────────────────────────────────

    public function testAnOversizedProjectNoteIsRefusedWithTypedInvalidParams(): void
    {
        // gap-minor N-3: the params cap (64 KiB) let an over-bound project
        // note through, and ProjectMemoryWriter's 8 KiB fail-fast threw past
        // the dispatcher's Throwable net — the panel got -32099 'internal
        // error'. The refusal is typed now, naming its reason, and the store
        // keeps no trace of the rejected note.
        $note = \str_repeat('x', ProjectMemoryWriter::MAX_CONTENT_BYTES + 1);

        $frame = $this->client()->request('memory.add', ['content' => $note, 'scope' => 'project']);

        self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code']);
        self::assertSame('invalid_params', $frame['error']['data']['kind']);
        self::assertStringContainsString('exceeds', $frame['error']['message']);
        $repo = ProjectMemoryWriter::forRoot($this->fixture->root)?->store();
        self::assertNotNull($repo, 'the fixture root hosts a repo store by construction here');
        foreach ($repo->list('project') as $entry) {
            self::assertNotSame($note, $entry->content(), 'a refused note leaves no file behind');
        }

        // Same conversion, sibling reason: whitespace-only text is the
        // router's empty-note refusal, not an internal error either.
        $blank = $this->client()->request('memory.add', ['content' => '   ', 'scope' => 'user']);
        self::assertSame(ErrorCode::InvalidParams->value, $blank['error']['code']);
        self::assertSame('invalid_params', $blank['error']['data']['kind']);
    }

    public function testAMutationCommitsTheHomeHistoryUnderItsPanelSubject(): void
    {
        if (!MemoryHistory::available()) {
            $this->markTestSkipped('git is not on PATH; the history is not kept');
        }

        $this->client()->call('memory.add', ['content' => 'a home note', 'scope' => 'user']);

        $history = MemoryHistory::forStore($this->home);
        self::assertNotNull($history);
        $subjects = array_map(static fn (MemoryRevision $revision): string => $revision->subject, $history->log(10));

        self::assertContains('memory: panel add', $subjects, 'the panel change must be its own commit, not a later "outside" credit');
    }

    public function testTheWireLogAndUndoRideTheHomeHistoryLikeTheCli(): void
    {
        if (!MemoryHistory::available()) {
            $this->markTestSkipped('git is not on PATH; the history is not kept');
        }

        $client = $this->client();
        $keeper = $client->call('memory.add', ['content' => 'the keeper note', 'scope' => 'user']);
        $client->call('memory.add', ['content' => 'the doomed note', 'scope' => 'user']);

        $log = $client->call('memory.log')['items'];
        $subjects = array_column($log, 'subject');
        self::assertSame(['memory: panel add', 'memory: panel add'], \array_slice($subjects, 0, 2), 'both adds are committed, newest first');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{4,64}\z/', $log[0]['sha']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T/', $log[0]['committedAt']);

        // Undo by name: back to the keeper-only revision. The restore is its
        // own commit (CLI law), and only the doomed note disappears.
        $undone = $client->call('memory.undo', ['revision' => $log[1]['sha']]);
        self::assertSame($log[1]['sha'], $undone['restored']);
        self::assertNotNull($undone['commit'], 'a tree-changing restore lands as its own commit');
        // (not $this->listItems(): a second WireClient shares the dispatcher's
        // default connection id and would steal this client's later frames)
        self::assertSame([$keeper['id']], array_column($client->call('memory.list', ['scope' => 'user'])['items'], 'id'), 'the undone note is gone and the keeper stayed');

        // The default undo names the state before the newest commit — here,
        // undoing the undo brings the undone note back.
        $redone = $client->call('memory.undo');
        self::assertSame($log[0]['sha'], $redone['restored'], 'the default undo steps back from the restore commit');
        self::assertCount(2, $client->call('memory.list', ['scope' => 'user'])['items'], 'the restored-again note is really back');
    }

    public function testUndoRefusesTypedAndChangesNothing(): void
    {
        if (!MemoryHistory::available()) {
            $this->markTestSkipped('git is not on PATH; the history is not kept');
        }

        $client = $this->client();
        // No commit yet: a default undo has no earlier state to name, and a
        // named revision is either malformed or not a commit here. All are
        // input refusals (-32602), never the -32099 net.
        $void = $client->request('memory.undo');
        self::assertSame(ErrorCode::InvalidParams->value, $void['error']['code']);
        self::assertSame('memory_undo_unavailable', $void['error']['data']['kind']);

        foreach (['not a hex id', str_repeat('d', 40)] as $revision) {
            $frame = $client->request('memory.undo', ['revision' => $revision]);
            self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code'], $revision);
            self::assertSame('invalid_params', $frame['error']['data']['kind']);
        }
        self::assertSame([], $client->call('memory.list', ['scope' => 'user'])['items'], 'refused undos change nothing');
    }

    public function testTheHistoryMethodsRefuseAWorkspaceWithoutAMemoryStore(): void
    {
        $bare = ProtocolFixture::new();
        try {
            $wire = $bare->client();
            foreach (['memory.log', 'memory.undo'] as $method) {
                $frame = $wire->request($method);
                self::assertSame(ErrorCode::UnsupportedInServer->value, $frame['error']['code'], $method);
                self::assertSame('memory_unavailable', $frame['error']['data']['kind'], $method);
            }
        } finally {
            $bare->tearDown();
        }
    }

    // ── the standing contract ──────────────────────────────────────────

    public function testAWorkspaceWithoutAMemoryStoreStillRefusesWithMemoryUnavailable(): void
    {
        $bare = ProtocolFixture::new();
        try {
            $frame = $bare->client()->request('memory.list', ['scope' => 'project']);

            self::assertSame(ErrorCode::UnsupportedInServer->value, $frame['error']['code']);
            self::assertSame('memory_unavailable', $frame['error']['data']['kind']);
        } finally {
            $bare->tearDown();
        }
    }

    public function testEditingAnUnknownIdStillReportsNoteNotFound(): void
    {
        $frame = $this->client()->request('memory.edit', ['id' => 'nope', 'content' => 'x']);

        self::assertSame(ErrorCode::NotFound->value, $frame['error']['code']);
        self::assertSame('note_not_found', $frame['error']['data']['kind']);
    }

    /** @return list<array<string, mixed>> */
    private function listItems(string $scope): array
    {
        $result = $this->client()->call('memory.list', ['scope' => $scope]);

        /** @var list<array<string, mixed>> $items */
        $items = $result['items'];

        return $items;
    }

    private function client(): WireClient
    {
        return $this->fixture->client();
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_link($path) || is_file($path) ? unlink($path) : $this->purgeTree($path);
        }
        @rmdir($dir);
    }
}
