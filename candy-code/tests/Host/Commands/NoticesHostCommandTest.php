<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\NoticesHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;

final class NoticesHostCommandTest extends TestCase
{
    public function testItRepliesWithTheNoticesReportAsAUiOnlyExchange(): void
    {
        $result = (new NoticesHostCommand())->run(CommandContext::new(), '/notices');

        self::assertFalse($result->isRefused());
        self::assertCount(2, $result->rows);
        self::assertSame(Role::User, $result->rows[0]->role);
        self::assertSame('/notices', $result->rows[0]->content);
        self::assertTrue($result->rows[0]->uiOnly);
        self::assertSame(Role::Assistant, $result->rows[1]->role);
        self::assertTrue($result->rows[1]->uiOnly);
        self::assertSame([], $result->effects);
    }

    public function testTheReportOpensWithTheTitleLine(): void
    {
        $result = (new NoticesHostCommand())->run(CommandContext::new(), '/notices');

        self::assertStringStartsWith(Lang::t('cmd.notices.title'), $result->rows[1]->content);
    }
}
