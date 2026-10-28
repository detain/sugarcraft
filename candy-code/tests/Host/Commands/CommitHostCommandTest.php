<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\Commands\CommitHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Workspace\AutoCommittedMsg;
use SugarCraft\Code\Workspace\AutoCommitter;
use SugarCraft\Code\Workspace\CommitMessageWriter;

/**
 * C-AGT-6.a (roadmap GAP-GIT-03): `/commit` commits every changed path
 * through the hardened auto-committer path — recorded as kind `command` so
 * `/undo` reverts it — and drafts the subject on the title model when the
 * user gave none, with the plain {@see CommitMessageWriter::fallback()}
 * subject standing in whenever the model is absent or fails.
 *
 * Driven against the same real repository the auto-committer suite builds;
 * the off-turn thunk is resolved by hand, exactly as `Cmd::promise` would
 * settle it on a loop.
 *
 * @see CommitHostCommand
 */
final class CommitHostCommandTest extends TestCase
{
    use CheckpointedRepoTrait;
    use PinsEnglishLocaleTrait;

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

    public function testAReadOnlyWindowRefusesTheCommitBeforeAnythingIsStaged(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited\n");

        $result = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: true, sessionId: 'undo-session'),
            '/commit',
        );

        self::assertNotNull($result->error, 'a refused command reports on the wire');
        self::assertStringContainsString('/commit was not run', $result->error ?? '');
        self::assertSame([], $result->rows, 'nothing lands in the transcript');
        self::assertNull($result->effect(CommandEffectKind::Async));
        self::assertSame(['base'], $this->logSubjects(), 'the commit never happened');
    }

    public function testACleanWorkTreeIsAPlainReplyAndNeverDialsTheModel(): void
    {
        $dialed = new \ArrayObject();
        $backend = $this->draftingBackend('feat: impossible here', $dialed);

        $result = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: false, sessionId: 'undo-session', titleBackend: $backend),
            '/commit',
        );

        self::assertNull($result->effect(CommandEffectKind::Async), 'nothing to commit answers at once');
        self::assertArrayNotHasKey('seen', (array) $dialed, 'the title model is never dialed for an empty tree');
        $texts = array_map(static fn (Message $m): string => $m->content, $result->rows);
        self::assertContains('Nothing to commit: the work tree is clean.', $texts);
        self::assertSame(['base'], $this->logSubjects());
    }

    public function testAProjectOutsideGitRepliesRatherThanCommittingNothing(): void
    {
        mkdir($this->tmp . '/loose');
        file_put_contents($this->tmp . '/loose/notes.txt', "not a repo\n");
        $bare = (string) realpath($this->tmp . '/loose');

        $result = (new CommitHostCommand())->run(
            CommandContext::new(root: $bare, readOnly: false, sessionId: 'undo-session'),
            '/commit',
        );

        self::assertNull($result->effect(CommandEffectKind::Async));
        $texts = array_map(static fn (Message $m): string => $m->content, $result->rows);
        self::assertContains('/commit was not run: this project is not a git work tree.', $texts);
    }

    public function testAGivenMessageCommitsEveryChangedPathOffTurnAndUndoRevertsIt(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited\n");

        $result = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: false, sessionId: 'undo-session'),
            '/commit fix: the hand-written message',
        );

        $effect = $result->effect(CommandEffectKind::Async);
        self::assertNotNull($effect, 'the commit runs off-turn, like /bg and /btw');

        $settled = $this->settledFrom($effect);
        self::assertInstanceOf(AutoCommittedMsg::class, $settled);
        self::assertNull($settled->error, $settled->error ?? '');
        self::assertNotNull($settled->sha);
        self::assertSame(['file.txt'], $settled->paths, 'every changed path of the tree went in');
        self::assertSame('fix: the hand-written message', $settled->subject);
        self::assertSame($settled->sha, $this->gitAt('rev-parse', 'HEAD'));
        self::assertSame('fix: the hand-written message', $this->logSubjects()[0]);
        self::assertSame('', $this->gitAt('status', '--porcelain'), 'every path went in');

        // The record is session-scoped kind `command` — exactly what the
        // next /undo reads: the file comes back and the commit is gone.
        // (A commit that ADDED a path its parent lacked would be refused by
        // name instead — the rule every kind shares, pinned in
        // AutoCommitterTest::testCommitAllCommitsEveryChangedPath…)
        $undo = AutoCommitter::new($this->repo)->withSessionId('undo-session')->undo();
        self::assertTrue($undo['ok'], $undo['reason'] ?? '');
        self::assertSame($settled->sha, $undo['sha']);
        self::assertSame(['base'], $this->logSubjects(), '/undo reverted the /commit');
    }

    public function testWithoutAMessageTheTitleModelDraftsTheSubjectAndItsUsageIsCarried(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited\n");

        $dialed = new \ArrayObject();
        $result = (new CommitHostCommand())->run(
            CommandContext::new(
                history: [Message::user('add the login retry')],
                root: $this->repo,
                readOnly: false,
                sessionId: 'undo-session',
                titleBackend: $this->draftingBackend('feat: add the thing', $dialed),
            ),
            '/commit',
        );

        $settled = $this->settledFrom($result->effect(CommandEffectKind::Async) ?? $this->neverEffect());
        self::assertSame(true, $dialed['seen'] ?? null, 'the drafting call went out on the title model');
        self::assertInstanceOf(AutoCommittedMsg::class, $settled);
        self::assertNull($settled->error);
        self::assertSame('feat: add the thing', $settled->subject);
        self::assertNotNull($settled->usage, 'the title model call rides back for accounting');
        self::assertSame(['feat: add the thing', 'base'], $this->logSubjects());

        // The served hosts' row names sha, subject and file count (ISS-097:
        // the describe closure builds the row, verdict included).
        $row = CommitHostCommand::settledRow($settled);
        self::assertNotNull($row);
        self::assertStringContainsString('feat: add the thing', $row->content);
        self::assertStringContainsString('1 file', $row->content);
        self::assertTrue($row->uiOnly);
        self::assertFalse($row->error);
    }

    public function testNoTitleModelOrAFailedDraftStillCommitsWithThePlainFallbackSubject(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited\n");
        $plain = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: false, sessionId: 'undo-session'),
            '/commit',
        );
        $settled = $this->settledFrom($plain->effect(CommandEffectKind::Async) ?? $this->neverEffect());
        self::assertInstanceOf(AutoCommittedMsg::class, $settled);
        self::assertNull($settled->usage, 'no model was dialed, so nothing was billed');
        self::assertSame(CommitMessageWriter::fallback($settled->paths), $settled->subject);

        // Point the same shape at a model that always rejects: the commit
        // still lands, with the fallback subject (a title model that failed
        // is no reason to leave the change uncommitted).
        file_put_contents($this->repo . '/file.txt', "edited again\n");
        $rejector = new class () implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('title model is down'));
            }
        };
        $drafted = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: false, sessionId: 'undo-session', titleBackend: $rejector),
            '/commit',
        );
        $second = $this->settledFrom($drafted->effect(CommandEffectKind::Async) ?? $this->neverEffect());
        self::assertInstanceOf(AutoCommittedMsg::class, $second);
        self::assertNull($second->error);
        self::assertSame(CommitMessageWriter::fallback($second->paths), $second->subject, 'the second commit took the plain subject');
        self::assertSame([$second->subject, CommitMessageWriter::fallback($settled->paths), 'base'], $this->logSubjects());
    }

    public function testTheUsersHooksRunAndARejectionLandsAsAFailedRowNotARejectingPromise(): void
    {
        mkdir($this->repo . '/.githooks');
        file_put_contents($this->repo . '/.githooks/pre-commit', "#!/bin/sh\necho 'lint failed: bad style' >&2\nexit 1\n");
        chmod($this->repo . '/.githooks/pre-commit', 0o755);
        $this->gitAt('config', 'core.hooksPath', '.githooks');
        file_put_contents($this->repo . '/file.txt', "edited\n");

        $result = (new CommitHostCommand())->run(
            CommandContext::new(root: $this->repo, readOnly: false, sessionId: 'undo-session'),
            '/commit fix: refused',
        );

        $settled = $this->settledFrom($result->effect(CommandEffectKind::Async) ?? $this->neverEffect());
        self::assertInstanceOf(AutoCommittedMsg::class, $settled);
        self::assertNull($settled->sha);
        self::assertStringContainsString('lint failed: bad style', (string) $settled->error);
        self::assertSame(['base'], $this->logSubjects());

        $row = CommitHostCommand::settledRow($settled);
        self::assertNotNull($row);
        self::assertTrue($row->error, 'a refused commit carries its own verdict (ISS-097)');
        self::assertStringContainsString('Nothing was committed', $row->content);
        self::assertStringContainsString('lint failed: bad style', $row->content);
    }

    /** Fail loudly when a test expected an async effect and the command handed none. */
    private function neverEffect(): void
    {
        self::fail('the command was expected to hand an async effect to the host');
    }

    /** Resolve the off-turn thunk the way `Cmd::promise` settles it on a loop. */
    private function settledFrom(\SugarCraft\Code\Host\Commands\CommandEffect $effect): mixed
    {
        $settled = null;
        ($effect->run())()->then(static function (mixed $msg) use (&$settled): void {
            $settled = $msg;
        });
        self::assertNotNull($settled, 'a synchronous backend settles the promise inline');

        return $settled;
    }

    /** @return list<string> newest first */
    private function logSubjects(): array
    {
        return explode("\n", $this->gitAt('log', '--format=%s'));
    }

    /**
     * A title model that answers with $reply and records that it was dialed.
     */
    private function draftingBackend(string $reply, \ArrayObject $dialed): Backend
    {
        return new class ($reply, $dialed) implements Backend {
            public function __construct(
                private readonly string $reply,
                private readonly \ArrayObject $dialed,
            ) {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                $this->dialed['seen'] = true;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(9, 0.001)));
            }
        };
    }
}
