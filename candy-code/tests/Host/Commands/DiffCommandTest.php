<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\DiffCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;

final class DiffCommandTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    private function context(): CommandContext
    {
        return CommandContext::new(sessionStore: $this->store, sessionId: 'undo-session', root: $this->repo);
    }

    private function reply(string $text, ?CommandContext $context = null): string
    {
        return (new DiffCommand())->run($context ?? $this->context(), $text)->rows[1]->content;
    }

    public function testNoStoreIsAnErrorReply(): void
    {
        $result = (new DiffCommand())->run(CommandContext::new(), '/diff');

        self::assertSame(Lang::t('host.checkpoint.no_store'), $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testAStoreWithoutCheckpointsIsExplained(): void
    {
        $plain = new SessionStore($this->tmp . '/db/plain.db');

        $reply = $this->reply('/diff', CommandContext::new(sessionStore: $plain, sessionId: 's'));

        self::assertSame(Lang::t('host.checkpoint.unsupported_store'), $reply);
    }

    public function testNoSessionIsRefused(): void
    {
        $reply = $this->reply('/diff', CommandContext::new(sessionStore: $this->store));

        self::assertSame(Lang::t('host.checkpoint.no_session'), $reply);
    }

    public function testBadStepCountsAnswerTheUsage(): void
    {
        foreach (['/diff 0', '/diff -1', '/diff last', '/diff 1 2'] as $bad) {
            self::assertSame(Lang::t('host.diff.usage'), $this->reply($bad), $bad);
        }
    }

    public function testNoCheckpointsMeansNothingToDiffAgainst(): void
    {
        self::assertSame(Lang::t('host.diff.none'), $this->reply('/diff'));
    }

    public function testAnUnchangedTreeSaysItMatches(): void
    {
        $index = $this->turnCheckpoint([], 'first');

        self::assertSame(Lang::t('host.diff.unchanged', ['index' => $index]), $this->reply('/diff --print'));
    }

    public function testAChangedFileYieldsTheHeadlineSummaryAndAFencedPatch(): void
    {
        $index = $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        $reply = $this->reply('/diff');

        self::assertStringStartsWith(Lang::t('host.diff.changed.one', ['count' => 1, 'index' => $index, 'steps' => 1]), $reply);
        self::assertStringContainsString('file.txt', $reply);
        self::assertStringContainsString("```diff\n", $reply);
        self::assertStringContainsString("-base\n+changed", $reply);
    }

    public function testSeveralFilesUseThePluralHeadline(): void
    {
        $index = $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "changed\n");
        file_put_contents($this->repo . '/new.txt', "created\n");

        $reply = $this->reply('/diff');

        self::assertStringStartsWith(Lang::t('host.diff.changed.other', ['count' => 2, 'index' => $index, 'steps' => 1]), $reply);
    }

    public function testTheReplyIsUiOnlyAndNeverReachesTheModel(): void
    {
        $this->turnCheckpoint([], 'first');
        $result = (new DiffCommand())->run($this->context(), '/diff');

        self::assertSame(Role::User, $result->rows[0]->role);
        self::assertTrue($result->rows[0]->uiOnly);
        self::assertTrue($result->rows[1]->uiOnly);
    }
}
