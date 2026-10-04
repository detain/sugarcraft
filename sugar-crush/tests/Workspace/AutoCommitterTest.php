<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * Step 3.G: opt-in auto-commit, Aider's way — the user's own earlier changes
 * committed first in a commit of their own, only the files sugar-crush changed
 * committed, the user's hooks always run, the user is the author and the
 * trailer is the attribution setting's.
 *
 * Driven against a real repository whose checkpoints carry real workspace
 * snapshots (step 3.A-1), the baseline auto-commit reads "what the user had
 * before the turn" from.
 *
 * @see AutoCommitter
 */
final class AutoCommitterTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        $this->gitAt('config', 'user.name', 'Ada User');
        $this->gitAt('config', 'user.email', 'ada@example.invalid');
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testTheSettingAndTheTrailerAreReadTolerantly(): void
    {
        self::assertSame('off', AutoCommitter::modeFrom(null));
        self::assertSame('off', AutoCommitter::modeFrom('always'));
        self::assertSame('off', AutoCommitter::modeFrom(true));
        self::assertSame('turn', AutoCommitter::modeFrom('turn'));
        self::assertSame('edit', AutoCommitter::modeFrom('edit'));

        self::assertSame(AutoCommitter::DEFAULT_TRAILER, AutoCommitter::trailerFor(null));
        self::assertSame(AutoCommitter::DEFAULT_TRAILER, AutoCommitter::trailerFor(['pr' => 'x']));
        self::assertSame('Co-authored-by: Bot <b@x>', AutoCommitter::trailerFor(['commit' => ' Co-authored-by: Bot <b@x> ']));
        self::assertSame('', AutoCommitter::trailerFor(['commit' => '']), 'an empty attribution drops the trailer');
    }

    public function testATurnCommitPutsTheUsersEarlierChangesInACommitOfTheirOwnFirst(): void
    {
        file_put_contents($this->repo . '/file.txt', "mine\n");
        $workspace = $this->checkpoint();

        file_put_contents($this->repo . '/file.txt', "mine\nfrom the model\n");
        file_put_contents($this->repo . '/new.txt', "created\n");

        $committer = $this->committer();
        $prepared = $committer->prepareTurn($this->store->workspaceCheckpointer($this->repo), $workspace);
        self::assertIsArray($prepared);
        self::assertSame(['file.txt', 'new.txt'], $prepared['paths']);
        self::assertNotNull($prepared['snapshot']);
        self::assertStringContainsString('+from the model', $prepared['diff']);
        self::assertStringContainsString("new file new.txt\n", $prepared['diff']);
        self::assertStringContainsString('+created', $prepared['diff']);

        $outcome = $committer->commit($prepared['paths'], 'feat: add the model part');
        self::assertTrue($outcome['ok'], $outcome['reason']);

        self::assertSame(['feat: add the model part', AutoCommitter::SNAPSHOT_SUBJECT, 'base'], $this->subjects());
        self::assertSame("mine\n", $this->gitAt('show', 'HEAD~1:file.txt') . "\n", 'the snapshot holds the user\'s version');
        self::assertSame("mine\nfrom the model", $this->gitAt('show', 'HEAD:file.txt'));
        self::assertSame('', $this->gitAt('status', '--porcelain'), 'everything is committed, nothing left staged');
        self::assertSame('Ada User <ada@example.invalid>', $this->gitAt('log', '-1', '--format=%an <%ae>'));
        self::assertSame('Co-authored-by: Bot <bot@example.invalid>', trim($this->gitAt('log', '-1', '--format=%(trailers:only,unfold)')));
        self::assertSame($outcome['sha'], $this->gitAt('rev-parse', 'HEAD'));
    }

    public function testATurnWithNoUserChangesNeedsNoSnapshotAndAnUnchangedTurnCommitsNothing(): void
    {
        $workspace = $this->checkpoint();
        self::assertNull($this->committer()->prepareTurn($this->store->workspaceCheckpointer($this->repo), $workspace));

        file_put_contents($this->repo . '/file.txt', "model\n");
        $prepared = $this->committer()->prepareTurn($this->store->workspaceCheckpointer($this->repo), $workspace);
        self::assertIsArray($prepared);
        self::assertNull($prepared['snapshot']);
        self::assertSame(['file.txt'], $prepared['paths']);
    }

    public function testOnlyTheFilesTheTurnChangedAreCommittedAndOtherStagedWorkIsLeftAlone(): void
    {
        file_put_contents($this->repo . '/other.txt', "staged by the user\n");
        $this->gitAt('add', 'other.txt');
        $workspace = $this->checkpoint();
        file_put_contents($this->repo . '/file.txt', "model\n");

        $committer = $this->committer();
        $prepared = $committer->prepareTurn($this->store->workspaceCheckpointer($this->repo), $workspace);
        self::assertIsArray($prepared);
        self::assertSame(['file.txt'], $prepared['paths']);
        self::assertTrue($committer->commit($prepared['paths'], 'fix: model')['ok']);

        self::assertSame('file.txt', $this->gitAt('show', '--name-only', '--format=', 'HEAD'));
        self::assertSame('A  other.txt', $this->gitAt('status', '--porcelain'));
    }

    public function testTheUsersHooksRunAndARejectionLeavesTheChangeUncommittedAndUnstaged(): void
    {
        mkdir($this->repo . '/.githooks');
        file_put_contents($this->repo . '/.githooks/pre-commit', "#!/bin/sh\necho ran > \"\$(git rev-parse --git-dir)/hook-ran\"\n");
        chmod($this->repo . '/.githooks/pre-commit', 0o755);
        $this->gitAt('config', 'core.hooksPath', '.githooks');
        file_put_contents($this->repo . '/file.txt', "one\n");

        self::assertTrue($this->committer()->commit(['file.txt'], 'fix: one')['ok']);
        self::assertFileExists($this->repo . '/.git/hook-ran', 'the configured hooks directory ran — no --no-verify');

        file_put_contents($this->repo . '/.githooks/pre-commit', "#!/bin/sh\necho 'lint failed: bad style' >&2\nexit 1\n");
        file_put_contents($this->repo . '/file.txt', "two\n");
        $failed = $this->committer()->commit(['file.txt'], 'fix: two');

        self::assertFalse($failed['ok']);
        self::assertStringContainsString('lint failed: bad style', $failed['reason']);
        self::assertSame('fix: one', $this->subjects()[0]);
        self::assertSame(' M file.txt', $this->gitAt('status', '--porcelain', '--', 'file.txt'), 'left in the file, unstaged');
    }

    public function testTheDefaultHooksDirectoryRunsTooWhenNoneIsConfigured(): void
    {
        file_put_contents($this->repo . '/.git/hooks/pre-commit', "#!/bin/sh\necho 'refused by .git/hooks' >&2\nexit 1\n");
        chmod($this->repo . '/.git/hooks/pre-commit', 0o755);
        file_put_contents($this->repo . '/file.txt', "x\n");

        $failed = $this->committer()->commit(['file.txt'], 'fix: x');

        self::assertFalse($failed['ok']);
        self::assertStringContainsString('refused by .git/hooks', $failed['reason']);
    }

    public function testEditModeSnapshotsTheUsersChangesOnceAndCommitsEachEdit(): void
    {
        file_put_contents($this->repo . '/file.txt', "mine\n");
        $workspace = $this->checkpoint();
        $committer = $this->committer();

        file_put_contents($this->repo . '/file.txt', "mine\nedit one\n");
        $first = $committer->commitEdit($this->repo . '/file.txt', static fn (): string => 'chore: edit one', $workspace);
        self::assertNotNull($first);
        self::assertTrue($first['ok'], $first['reason']);
        self::assertNotNull($first['snapshot']);

        file_put_contents($this->repo . '/file.txt', "mine\nedit one\nedit two\n");
        $second = $committer->commitEdit($this->repo . '/file.txt', static fn (): string => 'chore: edit two', $workspace);
        self::assertNotNull($second);
        self::assertTrue($second['ok']);
        self::assertNull($second['snapshot'], 'the user\'s part is already in history');

        self::assertSame(['chore: edit two', 'chore: edit one', AutoCommitter::SNAPSHOT_SUBJECT, 'base'], $this->subjects());
    }

    public function testEditModeWithoutACheckpointWillNotMixButCommitsANewFile(): void
    {
        file_put_contents($this->repo . '/file.txt', "changed with no checkpoint\n");
        $refused = $this->committer()->commitEdit($this->repo . '/file.txt', static fn (): string => 'chore: x', null);
        self::assertNotNull($refused);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('could not be kept apart', $refused['reason']);
        self::assertSame(['base'], $this->subjects());

        file_put_contents($this->repo . '/fresh.txt', "new\n");
        $created = $this->committer()->commitEdit($this->repo . '/fresh.txt', static fn (): string => 'feat: add fresh', null);
        self::assertNotNull($created);
        self::assertTrue($created['ok'], $created['reason']);
        self::assertSame('fresh.txt', $this->gitAt('show', '--name-only', '--format=', 'HEAD'));
    }

    public function testIgnoredUnchangedAndOutsideFilesAreNotCommitted(): void
    {
        file_put_contents($this->repo . '/.gitignore', "*.log\n");
        $this->gitAt('add', '.gitignore');
        $this->gitAt('commit', '-q', '-m', 'ignore');
        file_put_contents($this->repo . '/debug.log', "x\n");
        $outside = $this->tmp . '/outside.txt';
        file_put_contents($outside, "x\n");

        $committer = $this->committer();
        self::assertNull($committer->commitEdit($this->repo . '/debug.log', static fn (): string => 'chore: log', null));
        self::assertNull($committer->commitEdit($this->repo . '/file.txt', static fn (): string => 'chore: same', null), 'unchanged');
        self::assertNull($committer->commitEdit($outside, static fn (): string => 'chore: outside', null));
        mkdir($this->repo . '/nested');
        $this->gitAt('-C', 'nested', 'init', '-q');
        file_put_contents($this->repo . '/nested/inner.txt', "x\n");
        self::assertNull($committer->commitEdit($this->repo . '/nested/inner.txt', static fn (): string => 'chore: nested', null), 'a nested repository\'s file is that repository\'s');
        self::assertSame(['ignore', 'base'], $this->subjects());
    }

    public function testNothingIsDoneOutsideAGitWorkTree(): void
    {
        $plain = $this->tmp . '/plain';
        mkdir($plain);
        file_put_contents($plain . '/a.txt', "x\n");
        $committer = AutoCommitter::new($plain);

        self::assertNull($committer->topLevel());
        self::assertNull($committer->commitEdit($plain . '/a.txt', static fn (): string => 'chore: x', null));
        self::assertFalse($committer->hasCommits());
    }

    private function committer(): AutoCommitter
    {
        return AutoCommitter::new($this->repo)
            ->withSessionId('undo-session')
            ->withTrailer('Co-authored-by: Bot <bot@example.invalid>');
    }

    /**
     * Save a turn checkpoint with a snapshot of the files as they are now and
     * return its workspace record.
     *
     * @return array<string, mixed>
     */
    private function checkpoint(): array
    {
        $index = $this->turnCheckpoint([], 'prompt');
        $state = $this->store->listCheckpoints('undo-session', 1)[0]['state_data'];
        self::assertSame($index, $this->store->listCheckpoints('undo-session', 1)[0]['index']);
        $workspace = $state[\SugarCraft\Crush\Session\EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY] ?? null;
        self::assertIsArray($workspace);

        return $workspace;
    }

    /** @return list<string> newest first */
    private function subjects(): array
    {
        return explode("\n", $this->gitAt('log', '--format=%s'));
    }
}
