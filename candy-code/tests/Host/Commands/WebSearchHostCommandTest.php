<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\WebSearchHostCommand;
use SugarCraft\Code\Role;

/** Only the offline arms (help, flag errors): a real query would dial a search backend. */
final class WebSearchHostCommandTest extends TestCase
{
    public function testHelpIsAnAssistantReplyCarryingTheHelpText(): void
    {
        $level = ob_get_level();

        $result = (new WebSearchHostCommand())->run(CommandContext::new(), '/websearch --help');

        self::assertSame($level, ob_get_level(), 'the captured buffer is closed');
        self::assertFalse($result->isRefused());
        self::assertSame(Role::User, $result->rows[0]->role);
        self::assertSame('/websearch --help', $result->rows[0]->content);
        self::assertSame(Role::Assistant, $result->rows[1]->role);
        self::assertStringContainsString('─', $result->rows[1]->content);
    }

    public function testABadFlagValueFailsAsANoticeNamingTheValue(): void
    {
        $result = (new WebSearchHostCommand())->run(CommandContext::new(), '/websearch --safesearch 9 cats');

        self::assertSame(Role::User, $result->rows[0]->role);
        self::assertSame(Role::System, $result->rows[1]->role);
        self::assertStringContainsString('9', $result->rows[1]->content);
    }

    public function testABadTimeRangeFailsWithoutDialling(): void
    {
        $result = (new WebSearchHostCommand())->run(CommandContext::new(), '/websearch --time-range decade cats');

        self::assertSame(Role::System, $result->rows[1]->role);
        self::assertStringContainsString('decade', $result->rows[1]->content);
    }
}
