<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Workspace\AutoCommittedMsg;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * Step 3.G, `autoCommit: turn`: when a turn settles, the files it changed are
 * committed in one commit whose subject the cheap title model writes from the
 * diff; the call is accounted and one display-only line says what happened.
 *
 * @see Chat::scheduleAutoCommit()
 */
final class AutoCommitTurnTest extends TestCase
{
    use CheckpointedRepoTrait;

    private string|false $suggestions = false;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        // One Cmd per settle, so the test can resolve it: no prompt suggestion.
        $this->suggestions = getenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS');
        putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false ? putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS') : putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        $this->tearDownCheckpointedRepo();
    }

    public function testASettledTurnIsCommittedWithTheTitleModelsSubject(): void
    {
        $this->turnCheckpoint([], 'add a greeting');
        file_put_contents($this->repo . '/hello.txt', "hello\n");
        $title = $this->titleBackend("feat: add a greeting file\n");

        [$settled, $cmd] = $this->chat('turn', $title)->update(new AssistantMsg(Message::assistant('Added hello.txt.')));

        self::assertInstanceOf(\Closure::class, $cmd);
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(AutoCommittedMsg::class, $landed);
        self::assertSame('feat: add a greeting file', $this->gitAt('log', '-1', '--format=%s'));
        self::assertCount(1, $title->asked);
        self::assertStringContainsString('<context>' . "\nthe prompt\n" . '</context>', $title->asked[0][1]->content);
        self::assertStringContainsString('new file hello.txt', $title->asked[0][1]->content);

        [$shown] = $settled->update($landed);
        $last = $shown->history[\count($shown->history) - 1];
        self::assertTrue($last->uiOnly);
        self::assertStringStartsWith('Auto-committed ' . substr($this->gitAt('rev-parse', 'HEAD'), 0, 7) . ' feat: add a greeting file — 1 file.', $last->content);
        self::assertEqualsWithDelta(0.001, $shown->spentUsd(), 1e-9, 'the title model\'s call is accounted');
    }

    public function testWithNoTitleModelThePlainSubjectIsUsed(): void
    {
        $this->turnCheckpoint([], 'change it');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        [, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertInstanceOf(AutoCommittedMsg::class, $this->resolve($cmd));
        self::assertSame('chore: update file.txt', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testATurnThatChangedNothingOrAnotherModeCommitsNothing(): void
    {
        $this->turnCheckpoint([], 'just talk');
        [, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Nothing to change.')));
        self::assertNull($this->resolve($cmd));

        file_put_contents($this->repo . '/file.txt', "changed\n");
        foreach (['off', 'edit', 'sometimes'] as $mode) {
            [, $none] = $this->chat($mode, null)->update(new AssistantMsg(Message::assistant('Done.')));
            self::assertNull($none, "autoCommit: {$mode} schedules nothing at the end of a turn");
        }
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testAFailedCommitIsReportedNotHidden(): void
    {
        file_put_contents($this->repo . '/.git/hooks/pre-commit', "#!/bin/sh\necho 'tests failed' >&2\nexit 1\n");
        chmod($this->repo . '/.git/hooks/pre-commit', 0o755);
        $this->turnCheckpoint([], 'change it');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        [$settled, $cmd] = $this->chat('turn', null)->update(new AssistantMsg(Message::assistant('Done.')));
        $landed = $this->resolve($cmd);
        self::assertInstanceOf(AutoCommittedMsg::class, $landed);

        [$shown] = $settled->update($landed);
        self::assertStringStartsWith('Auto-commit skipped: git commit failed: tests failed', $shown->history[\count($shown->history) - 1]->content);
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    private function chat(string $mode, ?Backend $title): Chat
    {
        return new Chat(
            history: [Message::user('the prompt')],
            inFlight: true,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 'undo-session',
            currentSessionName: 'named',
            projectRoot: $this->repo,
            titleBackend: $title,
            workspace: WorkspaceContext::new(root: $this->repo, userConfig: [AutoCommitter::SETTINGS_KEY => $mode]),
        );
    }

    private function resolve(?\Closure $cmd): mixed
    {
        self::assertNotNull($cmd);
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function titleBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}
