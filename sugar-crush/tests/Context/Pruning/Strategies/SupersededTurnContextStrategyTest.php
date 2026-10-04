<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning\Strategies;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededTurnContextStrategy;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\UserMessage;

/**
 * The W4 1.A-2 follow-up in 2.2-1: every persisted `<turn-context>` row but
 * the newest is named in the ledger, once.
 */
final class SupersededTurnContextStrategyTest extends TestCase
{
    public function testEveryRowButTheNewestIsProposedOnce(): void
    {
        $one = self::row('one');
        $two = self::row('two');
        $three = self::row('three');
        $rows = [new UserMessage('go'), $one, $two, new UserMessage('more'), $three];

        $delta = SupersededTurnContextStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(
            [ContextLedger::contextRowKey($one->content()), ContextLedger::contextRowKey($two->content())],
            array_keys($delta->droppedContextRows),
        );
        $this->assertGreaterThan(0, $delta->freedTokens());

        $ledger = ContextLedger::new()->apply($delta);
        $this->assertTrue(SupersededTurnContextStrategy::new()->propose($rows, $ledger, PruningPolicy::new())->isEmpty(), 'already named');
    }

    public function testASingleRowIsTheNewestAndStays(): void
    {
        $this->assertTrue(SupersededTurnContextStrategy::new()->propose([new UserMessage('go'), self::row('only')], ContextLedger::new(), PruningPolicy::new())->isEmpty());
    }

    private static function row(string $state): UserMessage
    {
        return new UserMessage(TurnContextBlock::FENCE . "\n" . $state . "\n</turn-context>");
    }
}
