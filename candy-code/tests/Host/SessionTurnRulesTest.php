<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\SessionTurnRules;
use SugarCraft\Code\Role;

final class SessionTurnRulesTest extends TestCase
{
    private static function frame(array $payload): string
    {
        return RuntimeNoticeSink::STRUCTURED_PREFIX . json_encode($payload);
    }

    public function testAPlainNoticeBecomesAUiOnlySystemRow(): void
    {
        $m = SessionTurnRules::noticeSlotFor('disk is full');

        self::assertNotNull($m);
        self::assertSame(Role::System, $m->role);
        self::assertSame('disk is full', $m->content);
        self::assertTrue($m->uiOnly);
    }

    public function testAStructuredRecallFrameBecomesARecallRowWithTitles(): void
    {
        $m = SessionTurnRules::noticeSlotFor(self::frame(['kind' => 'memory.recall', 'count' => 2, 'titles' => ['a', 'b']]));

        self::assertNotNull($m);
        self::assertSame(RuntimeNoticeSink::recallRowText(2), $m->content);
        self::assertSame(['a', 'b'], $m->recallTitles);
        self::assertTrue($m->uiOnly);
    }

    public function testAMalformedFrameParksNothing(): void
    {
        self::assertNull(SessionTurnRules::noticeSlotFor(RuntimeNoticeSink::STRUCTURED_PREFIX . '{not json'));
        self::assertNull(SessionTurnRules::noticeSlotFor(self::frame(['kind' => 'other', 'count' => 1])));
        self::assertNull(SessionTurnRules::noticeSlotFor(self::frame(['kind' => 'memory.recall', 'count' => -1])));
    }

    public function testCompactionRouteBlocksAForegroundSessionWhoseBlockingTierIsDue(): void
    {
        $route = SessionTurnRules::compactionRoute(false, static fn (): bool => true, static fn (): bool => true);

        self::assertSame(SessionTurnRules::ROUTE_FOREGROUND_BLOCKED, $route);
    }

    public function testCompactionRouteCompactsWhenAutoAndTheAutoTierIsDue(): void
    {
        $route = SessionTurnRules::compactionRoute(true, static fn (): bool => true, static fn (): bool => true);

        self::assertSame(SessionTurnRules::ROUTE_COMPACT, $route);
    }

    public function testCompactionRouteSendsOtherwiseAndNeverCallsTheIrrelevantTier(): void
    {
        $calls = [];
        $send = SessionTurnRules::compactionRoute(
            true,
            static function () use (&$calls): bool {
                $calls[] = 'blocking';

                return true;
            },
            static fn (): bool => false,
        );
        self::assertSame(SessionTurnRules::ROUTE_SEND, $send);
        self::assertSame([], $calls, 'an auto-compacting session never consults the blocking tier');

        $notDue = SessionTurnRules::compactionRoute(false, static fn (): bool => false, static function () use (&$calls): bool {
            $calls[] = 'auto';

            return true;
        });
        self::assertSame(SessionTurnRules::ROUTE_SEND, $notDue);
        self::assertSame([], $calls);
    }

    public function testContextReminderIsOnlyMintedWhenDue(): void
    {
        self::assertNull(SessionTurnRules::contextReminderFor(false, 90_000));

        $m = SessionTurnRules::contextReminderFor(true, 90_000);
        self::assertNotNull($m);
        self::assertSame(CompactionService::contextReminderMessage(90_000)->content, $m->content);
    }

    public function testAheadOfNeedRequestNeedsEveryGate(): void
    {
        $floor = CompactionService::AHEAD_OF_NEED_TOKEN_FLOOR;

        self::assertTrue(SessionTurnRules::aheadOfNeedRequestAllowed(true, false, false, $floor, 'm'));
        self::assertFalse(SessionTurnRules::aheadOfNeedRequestAllowed(false, false, false, $floor, 'm'), 'not due');
        self::assertFalse(SessionTurnRules::aheadOfNeedRequestAllowed(true, true, false, $floor, 'm'), 'slot held');
        self::assertFalse(SessionTurnRules::aheadOfNeedRequestAllowed(true, false, true, $floor, 'm'), 'cap reached');
        self::assertFalse(SessionTurnRules::aheadOfNeedRequestAllowed(true, false, false, $floor - 1, 'm'), 'below the token floor');
    }

    public function testSummariesAreOutOfDateOnlyWhenHeldAndEitherStaleSignalFires(): void
    {
        self::assertFalse(SessionTurnRules::summariesOutOfDate(true, false, false), 'nothing held, nothing stale');
        self::assertTrue(SessionTurnRules::summariesOutOfDate(false, true, true), 'no longer due');
        self::assertTrue(SessionTurnRules::summariesOutOfDate(true, true, false), 'fingerprint drifted');
        self::assertFalse(SessionTurnRules::summariesOutOfDate(true, true, true), 'still valid');
    }
}
