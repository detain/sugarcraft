<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Host\Commands\BackgroundCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\ForkCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\SessionStore;

final class ForkCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fork-cmd-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store(): SessionStore
    {
        $store = new SessionStore($this->dir . '/s.db');
        $store->createSession('s1', 'echo', 'echo');

        return $store;
    }

    public function testWithoutASupervisorItIsAnErrorReply(): void
    {
        $result = (new ForkCommand())->run(CommandContext::new(), '/fork do it');

        self::assertSame(BackgroundCommand::notConfigured(), $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
        self::assertSame([], $result->effects);
    }

    public function testAnEmptyPromptAnswersTheUsage(): void
    {
        $result = (new ForkCommand())->run(CommandContext::new(backgroundSupervisor: new BackgroundSupervisor()), '/fork');

        self::assertSame(Lang::t('host.fork.usage'), $result->rows[1]->content);
        self::assertSame([], $result->effects);
    }

    public function testWithoutAStoreItCannotRecordTheForkedSession(): void
    {
        $result = (new ForkCommand())->run(CommandContext::new(backgroundSupervisor: new BackgroundSupervisor()), '/fork refactor');

        self::assertSame(Lang::t('host.fork.no_store'), $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testWithoutASessionIdItIsAnErrorReply(): void
    {
        $context = CommandContext::new(backgroundSupervisor: new BackgroundSupervisor(), sessionStore: $this->store());

        $result = (new ForkCommand())->run($context, '/fork refactor');

        self::assertSame(Lang::t('host.rename.no_session'), $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testAStoreFailureIsReportedAsAnErrorReply(): void
    {
        $context = CommandContext::new(backgroundSupervisor: new BackgroundSupervisor(), sessionStore: $this->store(), sessionId: 'no-such-session');

        $result = (new ForkCommand())->run($context, '/fork refactor');

        self::assertTrue($result->rows[1]->error);
        self::assertStringStartsWith(explode('{', Lang::t('host.rename.error'))[0], $result->rows[1]->content);
    }

    public function testASuccessfulForkEchoesTheCommandAndQueuesTheSpawn(): void
    {
        $context = CommandContext::new(backgroundSupervisor: new BackgroundSupervisor(), sessionStore: $this->store(), sessionId: 's1');

        $result = (new ForkCommand())->run($context, '/fork refactor the parser');

        self::assertCount(1, $result->rows, 'only the echo; the spawn row arrives when the daemon starts');
        self::assertSame('/fork refactor the parser', $result->rows[0]->content);
        self::assertNotNull($result->effect(CommandEffectKind::Async));
    }
}
