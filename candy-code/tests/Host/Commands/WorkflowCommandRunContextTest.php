<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C10 command-half: `/workflow run` context parsing is quote-aware and every
 * malformed token is refused by name instead of dropped in silence.
 */
final class WorkflowCommandRunContextTest extends TestCase
{
    public function testPlainPairsParseAsBefore(): void
    {
        [$name, $context, $problems] = WorkflowCommand::parseRunArguments('plan a=b c=d');

        self::assertSame('plan', $name);
        self::assertSame(['a' => 'b', 'c' => 'd'], $context);
        self::assertSame([], $problems);
    }

    public function testADoubleQuotedValueCarriesSpaces(): void
    {
        [, $context, $problems] = WorkflowCommand::parseRunArguments('plan a="two words"');

        self::assertSame(['a' => 'two words'], $context);
        self::assertSame([], $problems);
    }

    public function testASingleQuotedValueCarriesSpacesToo(): void
    {
        [, $context] = WorkflowCommand::parseRunArguments("plan a='two words'");

        self::assertSame(['a' => 'two words'], $context);
    }

    public function testAQuotedWorkflowNameIsDequoted(): void
    {
        [$name, $context] = WorkflowCommand::parseRunArguments('"my plan" x=1');

        self::assertSame('my plan', $name);
        self::assertSame(['x' => '1'], $context);
    }

    public function testAnEmptyValueIsAValidPair(): void
    {
        [, $context, $problems] = WorkflowCommand::parseRunArguments('plan key=');

        self::assertSame(['key' => ''], $context);
        self::assertSame([], $problems);
    }

    public function testABareTokenIsReportedNotDropped(): void
    {
        [, , $problems] = WorkflowCommand::parseRunArguments('plan oops');

        self::assertCount(1, $problems);
        self::assertStringContainsString('expected key=value', $problems[0]);
        self::assertStringContainsString('oops', $problems[0]);
    }

    public function testAReservedAtKeyIsRefusedWithItsName(): void
    {
        [, , $problems] = WorkflowCommand::parseRunArguments('plan @results=x');

        self::assertCount(1, $problems);
        self::assertStringContainsString('@results', $problems[0]);
        self::assertStringContainsString('reserved', $problems[0]);
    }

    public function testAnUnterminatedQuoteIsRefused(): void
    {
        [, , $problems] = WorkflowCommand::parseRunArguments('plan x="unterminated');

        self::assertCount(1, $problems);
        self::assertStringContainsString('unterminated quote', $problems[0]);
    }

    public function testEveryMalformedTokenIsListedNotJustTheFirst(): void
    {
        [, , $problems] = WorkflowCommand::parseRunArguments('plan bad1 =v @x=1 k=v');

        self::assertCount(3, $problems);
    }

    public function testTabsSeparateArgumentsLikeSpaces(): void
    {
        [$name, $context] = WorkflowCommand::parseRunArguments("plan\ta=1\tb=2");

        self::assertSame('plan', $name);
        self::assertSame(['a' => '1', 'b' => '2'], $context);
    }

    public function testEmptyArgumentsNameNothingAndComplainNothing(): void
    {
        self::assertSame(['', [], []], WorkflowCommand::parseRunArguments(''));
    }

    public function testTheRunCommandRefusesBeforeTouchingTheEngine(): void
    {
        $engine = new class () implements WorkflowEngineInterface {
            /** @var list<array{0: string, 1: array<string, string>}> */
            public array $runs = [];

            public function run(string $workflowPath, array $context = [], ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                $this->runs[] = [$workflowPath, $context];

                return new WorkflowResult('wf-x', WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
            }

            public function resume(string $workflowId, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult('wf-x', WorkflowStatus::Completed);
            }

            public function status(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Completed;
            }

            public function listWorkflows(): array
            {
                return [];
            }
        };

        $result = (new WorkflowCommand())->run(
            CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()),
            '/workflow run plan oops',
        );

        self::assertTrue($result->rows[1]->error, 'the refusal row must carry the error flag');
        self::assertStringContainsString('key=value', $result->rows[1]->content);
        self::assertStringContainsString('oops', $result->rows[1]->content);
        self::assertSame([], $engine->runs, 'a refused run must never reach the engine');
    }
}
