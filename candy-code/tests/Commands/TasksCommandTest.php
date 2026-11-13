<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\TasksCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Lang;

/**
 * `/tasks` (#3): the list of background work, its tail and stop verbs, and the
 * read-only judgement the session gate applies. A session with nothing running
 * answers the empty line; the verbs answer their usage line without a registry.
 */
final class TasksCommandTest extends TestCase
{
    private function slash(string $text): CommandResult
    {
        return (new TasksCommand())->run(CommandContext::new(sessionId: 'ses_probe'), $text);
    }

    public function testBareTasksWithNothingRunningSaysSo(): void
    {
        $result = $this->slash('/tasks');

        self::assertSame(Lang::t('host.tasks.none'), $this->replyText($result));
    }

    public function testTheVerbsAnswerTheirUsageWithoutARegistry(): void
    {
        $tail = $this->slash('/tasks tail');
        $stop = $this->slash('/tasks stop');

        self::assertSame(Lang::t('host.shells.usage'), $this->replyText($tail));
        self::assertSame(Lang::t('host.shells.usage'), $this->replyText($stop));
    }

    public function testAnUnknownVerbIsRefusedWithTheUsageLine(): void
    {
        self::assertSame(Lang::t('host.shells.usage'), $this->replyText($this->slash('/tasks frobnicate')));
    }

    public function testListAndTailAreTheReadOnlyVerbs(): void
    {
        self::assertTrue(TasksCommand::isReadOnlyVerb('list'));
        self::assertTrue(TasksCommand::isReadOnlyVerb('TAIL'));
        self::assertFalse(TasksCommand::isReadOnlyVerb('stop'), 'stop kills a live child, so a read-only window must refuse it');
    }

    /** The text a reply carries. */
    private function replyText(CommandResult $result): string
    {
        // The echo of the typed command comes first; the answer is the last row.
        $rows = $result->rows;
        $last = end($rows);

        return $last === false ? '' : $last->content;
    }
}
