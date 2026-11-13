<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\ContextPressure;
use SugarCraft\Code\Context\Pruning\ProjectedContext;
use SugarCraft\Code\Messages\UserMessage;

final class ProjectedContextTest extends TestCase
{
    public function testEmptyProjectionIsZeroTokens(): void
    {
        self::assertSame(0, (new ProjectedContext([]))->tokens());
    }

    public function testTokensDelegateToContextPressureEstimate(): void
    {
        $messages = [new UserMessage('hello world'), new UserMessage(str_repeat('word ', 200))];
        $projected = new ProjectedContext($messages);

        self::assertSame($messages, $projected->messages);
        self::assertSame(ContextPressure::ofMessages($messages), $projected->tokens());
        self::assertGreaterThanOrEqual(2 * ContextPressure::MESSAGE_OVERHEAD_TOKENS, $projected->tokens());
    }

    public function testNonMessageRowsAreIgnored(): void
    {
        $projected = new ProjectedContext(['raw string', ['role' => 'user'], new UserMessage('x')]);

        self::assertSame((new ProjectedContext([new UserMessage('x')]))->tokens(), $projected->tokens());
    }

    public function testMoreMessagesCostMoreTokens(): void
    {
        $one = new ProjectedContext([new UserMessage('a')]);
        $two = new ProjectedContext([new UserMessage('a'), new UserMessage('a')]);

        self::assertGreaterThan($one->tokens(), $two->tokens());
    }
}
