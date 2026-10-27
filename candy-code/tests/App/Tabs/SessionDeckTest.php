<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App\Tabs;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Code\App\Tabs\SessionDeck;
use SugarCraft\Code\Tui\SessionTab;
use SugarCraft\Code\Tui\SessionTabs;

/**
 * The deck owns three things the pre-existing tab ledger could not do
 * alone: a KEY SPACE stable across session re-keys, the parked models
 * behind the invisible tabs, and the attention vocabulary written
 * through SessionTabs' detach/reattach/summary mutators — dormant until
 * this class became their caller.
 *
 * @internal
 *
 * @see SessionDeck
 */
final class SessionDeckTest extends TestCase
{
    public function testForLaunchSeedsOneNamedTabThatIsActiveAndAttached(): void
    {
        $deck = SessionDeck::forLaunch('t0', 'sess-old', 'main');

        $this->assertSame(1, $deck->count());
        $this->assertSame(['t0'], $deck->keys());
        $this->assertSame('t0', $deck->activeKey());
        $this->assertSame('sess-old', $deck->sessionOf('t0'));
        $this->assertNull($deck->model('t0'));

        $tab = $deck->tabs()->activeTab();
        $this->assertNotNull($tab);
        $this->assertFalse($tab->isDetached());
        $this->assertSame(SessionDeck::ATTENTION_IDLE, $tab->attention());
    }

    public function testTheIdleVocabularyMatchesTheLedgersDefaultSpelling(): void
    {
        // SessionTab stores the raw string; if the two 'idle' literals
        // ever drift, a fresh tab renders as an unknown state.
        $this->assertSame('idle', SessionDeck::ATTENTION_IDLE);
        $this->assertSame(SessionDeck::ATTENTION_IDLE, (new SessionTab(id: 'x', sessionName: 'x'))->attention());
        $this->assertSame(
            ['idle', 'working', 'waiting', 'unseen', 'failed'],
            SessionDeck::ATTENTION_STATES,
        );
    }

    public function testOpenAddsActivatedTabsAndRefusesToReuseALiveKey(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first')
            ->open('t1', 's1', 'second');

        $this->assertSame(['t0', 't1'], $deck->keys());
        $this->assertSame('t1', $deck->activeKey());
        $this->assertSame('s1', $deck->sessionOf('t1'));

        $refused = $deck->open('t1', 's-again', 'collision');
        $this->assertSame($deck, $refused);
        $this->assertSame('s1', $deck->sessionOf('t1'));
    }

    public function testParksAndUnparksAModelThroughTheLedgerLifecycle(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first')->open('t1', 's1', 'second');
        $model = $this->deckModelStub();

        $parked = $deck->park('t1', $model);
        $this->assertSame($model, $parked->model('t1'));
        $this->assertTrue($parked->tabs()->tabs()[1]->isDetached());
        // Parking marks the row; moving the foreground is the caller's
        // separate step, so the deck must not silently cycle.
        $this->assertSame('t1', $parked->activeKey());

        [$back, $taken] = $parked->unpark('t1');
        $this->assertSame($model, $taken);
        $this->assertNull($back->model('t1'));
        $this->assertSame('t1', $back->activeKey());
        $this->assertFalse($back->tabs()->activeTab()?->isDetached());
    }

    public function testUnparkingSomethingNeverParkedYieldsNoModel(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first')->open('t1', 's1', 'second');

        [$back, $taken] = $deck->unpark('t0');
        $this->assertNull($taken);
        $this->assertSame('t0', $back->activeKey());
    }

    public function testParkingUnderAnUnknownKeyIsRefused(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first');

        $this->assertSame($deck, $deck->park('ghost', $this->deckModelStub()));
    }

    public function testCloseHandsBackTheParkedModelAndDropsTheLedgerEntry(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first')
            ->open('t1', 's1', 'second')
            ->park('t0', $this->deckModelStub());

        [$closed, $model] = $deck->close('t0');
        $this->assertNotNull($model);
        $this->assertSame(['t1'], $closed->keys());
        $this->assertNull($closed->sessionOf('t0'));
        $this->assertNull($closed->model('t0'));
        $this->assertSame('t1', $closed->activeKey());
    }

    public function testTheLastTabCannotBeClosed(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first');

        [$closed, $model] = $deck->close('t0');
        $this->assertSame($deck, $closed);
        $this->assertNull($model);

        // An unknown key refuses the same way.
        [$other, $none] = $deck->open('t1', 's1', 'second')->close('ghost');
        $this->assertNull($none);
        $this->assertSame(['t0', 't1'], $other->keys());
    }

    public function testCyclesWalkTheSameRingTheKeystrokesDrive(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'a')
            ->open('t1', 's1', 'b')
            ->open('t2', 's2', 'c');

        $this->assertSame('t2', $deck->activeKey());
        $this->assertSame('t0', $deck->cycle(1)->activeKey()); // wraps forward
        $this->assertSame('t1', $deck->cycle(-1)->activeKey()); // one step back
        $this->assertSame('t1', $deck->cycle(2)->activeKey()); // two steps
        $this->assertSame($deck, $deck->cycle(0));

        // Cross-check against the ledger's own key path so the two
        // switch mechanisms can never drift apart.
        $viaKey = $deck->tabs()->handleKey(SessionTabs::CTRL_TAB);
        $this->assertNotNull($viaKey);
        $this->assertSame($deck->cycle(1)->activeKey(), $viaKey->activeTab()?->id);
    }

    public function testAttentionAndSummaryReachTheLedgerRows(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's0', 'first')
            ->open('t1', 's1', 'second')
            ->attention('t0', SessionDeck::ATTENTION_UNSEEN)
            ->summary('t0', 'turn settled: 3 files changed');

        $first = $deck->tabs()->tabs()[0];
        $this->assertSame(SessionDeck::ATTENTION_UNSEEN, $first->attention());
        $this->assertSame('turn settled: 3 files changed', $first->agentSummary());

        // Unknown ids are inert no-ops on the ledger mutators.
        $untouched = $deck->attention('ghost', SessionDeck::ATTENTION_WORKING);
        $this->assertSame($deck->tabs()->tabs()[0]->attention(), $untouched->tabs()->tabs()[0]->attention());
    }

    public function testRekeyMovesTheSessionBehindAStableTabKey(): void
    {
        $deck = SessionDeck::forLaunch('t0', 's-lazy', 'first')
            ->attention('t0', SessionDeck::ATTENTION_WORKING);

        $rekeyed = $deck->rekey('t0', 's-real');

        $this->assertSame('s-real', $rekeyed->sessionOf('t0'));
        $this->assertSame(['t0'], $rekeyed->keys());
        $this->assertSame(SessionDeck::ATTENTION_WORKING, $rekeyed->tabs()->tabs()[0]->attention());

        $this->assertSame($deck, $deck->rekey('ghost', 's-x'));
    }

    public function testParkedStateSurvivesCyclesAndRekeys(): void
    {
        $model = $this->deckModelStub();
        $deck = SessionDeck::forLaunch('t0', 's0', 'a')
            ->open('t1', 's1', 'b')
            ->park('t0', $model)
            ->cycle(1)
            ->rekey('t0', 's0-branch');

        $this->assertSame($model, $deck->model('t0'));
        $this->assertSame('s0-branch', $deck->sessionOf('t0'));
        $this->assertTrue($deck->tabs()->tabs()[0]->isDetached());
        // The ring steps onto the parked row without disturbing any of
        // that — whether the App unparks is a P-TABS.e decision.
        $this->assertSame('t0', $deck->activeKey());
    }

    /**
     * Placeholder Model parked behind a tab; named apart from the
     * sibling file's stub so the drift census sees two intentional
     * shapes rather than a clone.
     */
    private function deckModelStub(): Model
    {
        return new class implements Model {
            public function init(): ?Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string|View
            {
                return '';
            }

            public function subscriptions(): ?Subscriptions
            {
                return null;
            }
        };
    }
}
