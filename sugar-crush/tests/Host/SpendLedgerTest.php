<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Host\SpendLedger;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * O-2c: {@see SpendLedger} is the spend accounting `Chat` used to own — the
 * recording seam, the one "over budget" decision, the refusal and mid-turn
 * notices and `/budget`'s logic — lifted out so a headless host enforces the
 * same cap in the same words. The `/budget` and spend-cap behaviour through
 * `Chat` stays pinned by {@see \SugarCraft\Crush\Tests\Chat\BudgetCommandTest}
 * and {@see \SugarCraft\Crush\Tests\Chat\MidTurnSpendCapTranscriptTest}; these
 * cases pin the service on its own and that `Chat` reads the one its workspace
 * registered.
 */
final class SpendLedgerTest extends TestCase
{
    public function testAccountingRecordsTotalsAndRemembersAnUnpricedModel(): void
    {
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        $ledger->account($tracker, null);
        self::assertFalse($ledger->hasReported($tracker), 'an unreported call is not a zero-dollar call');

        $ledger->account($tracker, Usage::new(1500, 0.03));
        self::assertTrue($ledger->hasReported($tracker));
        self::assertEqualsWithDelta(0.03, $ledger->spent($tracker), 1e-9);
        self::assertSame(1500, $tracker->unsplitTokens());
        self::assertFalse($tracker->hasUnpricedUsage());

        $ledger->account($tracker, Usage::new(10, 0.0, unpricedModel: 'mystery-model'));
        self::assertTrue($tracker->hasUnpricedUsage());
    }

    public function testTheCapIsReachedOnlyByReportedSpendAtOrPastIt(): void
    {
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        self::assertFalse($ledger->capReached($tracker, null), 'no cap, never over');
        self::assertFalse($ledger->capReached($tracker, 0.5), 'an unreported session fails open');

        $ledger->account($tracker, Usage::new(100, 0.5));
        self::assertTrue($ledger->capReached($tracker, 0.5), 'reaching the cap is over it');
        self::assertFalse($ledger->capReached($tracker, 0.51));
    }

    public function testOnlyAPositiveFiniteFigureIsAUsableCap(): void
    {
        self::assertTrue(SpendLedger::isUsableCap(0.01));
        foreach ([0.0, -1.0, INF, NAN] as $cap) {
            self::assertFalse(SpendLedger::isUsableCap($cap));
            self::assertSame(SpendLedger::isUsableCap($cap), Chat::isUsableSpendCap($cap), 'Chat delegates the one definition');
        }
    }

    public function testTheNoticesNameTheStateAndTheWayOut(): void
    {
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();
        $ledger->account($tracker, Usage::new(100, 1.25));

        self::assertSame(
            'Spend cap reached — this turn was not sent. $1.2500 of the $1.0000 cap has been reported spent. '
            . SpendLedger::CROSSED_BY_PREVIOUS_TURN . ' Raise it with /budget 2.50, clear it with /budget off, or restart '
            . 'without $SUGARCRUSH_MAX_COST.',
            $ledger->refusalNotice($tracker, 1.0, SpendLedger::CROSSED_BY_PREVIOUS_TURN),
        );
        self::assertSame(
            '_Spend cap reached mid-turn: aborted after provider call 3 — $0.5100 of the $0.5000 cap spent. No further calls were made this turn; /budget raises the cap._',
            $ledger->midTurnNotice(new SpendCapBreached(3, 0.51, 0.5)),
        );
    }

    public function testTheStatusLineSaysNotReportedLowerBoundOrTheFigure(): void
    {
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        self::assertStringStartsWith('Spend so far: not reported by this provider (no cap).', $ledger->statusLine($tracker, null));

        $ledger->account($tracker, Usage::new(200, 0.02));
        self::assertSame(
            'Spend so far: $0.0200 (cap $5.0000). ' . $tracker->summary(),
            $ledger->statusLine($tracker, 5.0),
        );

        $ledger->account($tracker, Usage::new(5, 0.0, unpricedModel: 'mystery-model'));
        self::assertStringContainsString('LOWER BOUND', $ledger->statusLine($tracker, null));
    }

    public function testBudgetRepliesShowClearSetAndRefuse(): void
    {
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        self::assertSame(
            ['response' => $ledger->statusLine($tracker, 2.0), 'cap' => null, 'clearCap' => false],
            $ledger->budgetReply('', $tracker, 2.0),
        );

        $cleared = $ledger->budgetReply('OFF', $tracker, 2.0);
        self::assertSame('Spend cap cleared. ' . $ledger->statusLine($tracker, 2.0), $cleared['response']);
        self::assertTrue($cleared['clearCap']);
        self::assertStringStartsWith('No spend cap was set. ', $ledger->budgetReply('none', $tracker, null)['response']);

        $set = $ledger->budgetReply('$2.50', $tracker, null);
        self::assertSame(2.5, $set['cap']);
        self::assertSame('Spend cap set to $2.5000. ' . $ledger->statusLine($tracker, 2.5), $set['response']);

        foreach (['0', '-3', '1e309', 'lots'] as $bad) {
            $refused = $ledger->budgetReply($bad, $tracker, 2.0);
            self::assertStringStartsWith('Usage: /budget <amount>', $refused['response'], $bad);
            self::assertNull($refused['cap']);
            self::assertFalse($refused['clearCap'], 'a refused figure leaves the cap alone');
        }
    }

    public function testChatReadsTheLedgerItsWorkspaceRegistered(): void
    {
        $ledger = SpendLedger::new();
        $chat = new Chat(workspace: WorkspaceContext::new()->withService(SpendLedger::class, $ledger));

        self::assertSame($ledger, (new \ReflectionMethod(Chat::class, 'spendLedger'))->invoke($chat));
    }

    public function testChatsBudgetLineIsTheLedgersLine(): void
    {
        $tracker = new TokenTracker();
        $chat = new Chat(backend: new EchoBackend(), tokenTracker: $tracker, maxCostUsd: 3.0);
        SpendLedger::new()->account($tracker, Usage::new(40, 0.004));

        self::assertSame(
            SpendLedger::new()->statusLine($tracker, 3.0),
            (new \ReflectionMethod(Chat::class, 'budgetStatusLine'))->invoke($chat),
        );
        self::assertTrue($chat->hasReportedSpend());
        self::assertEqualsWithDelta(0.004, $chat->spentUsd(), 1e-9);
    }
}
