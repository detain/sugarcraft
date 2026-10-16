<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Forms\ItemList\StringItem;

/**
 * E736 5.15 (round 89): the load-more trigger. With {@see ItemList::withHasMore()}
 * on, a navigation that ARRIVES the cursor on the last visible item batches a
 * {@see LoadMoreMsg} through update()'s Cmd slot; edge-triggered, once per
 * arrival. The default (hasMore=false) keeps every pre-5.15 shape byte-identical.
 */
final class LoadMoreTest extends TestCase
{
    /** @return list<StringItem> */
    private function items(int $n = 4): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new StringItem('item-' . $i);
        }
        return $out;
    }

    private function list(?bool $hasMore = null, bool $infinite = false): ItemList
    {
        $l = ItemList::new($this->items(), 20, 3);
        if ($infinite) {
            $l = $l->withInfiniteScrolling(true);
        }
        if ($hasMore === true) {
            $l = $l->withHasMore();
        } elseif ($hasMore === false) {
            $l = $l->withHasMore(false);
        }
        [$l, ] = $l->focus();
        return $l;
    }

    private function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    /** Assert the Cmd slot fires a LoadMoreMsg (a navigation ARRIVAL). */
    private function assertFire(?\Closure $cmd): void
    {
        $this->assertNotNull($cmd, 'expected a load-more Cmd on arrival');
        $this->assertInstanceOf(LoadMoreMsg::class, $cmd());
    }

    /** Assert the Cmd slot stays empty (silence — resting, absent flag, excluded path). */
    private function assertQuiet(?\Closure $cmd, string $why = ''): void
    {
        $this->assertNull($cmd, $why);
    }

    public function testHasMoreDefaultsFalseAndRoundTrips(): void
    {
        $l = ItemList::new($this->items());
        $this->assertFalse($l->hasMore());
        $this->assertTrue($l->withHasMore()->hasMore());
        $this->assertTrue($l->withHasMore(true)->hasMore());
        $this->assertFalse($l->withHasMore()->withHasMore(false)->hasMore());
    }

    public function testFlagSurvivesNavigationAndFiltering(): void
    {
        $l = $this->list(hasMore: true);
        [$l2, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertTrue($l2->hasMore(), 'the flag rides every rebuild');
    }

    public function testArrivalOnLastItemDispatchesOnce(): void
    {
        $l = $this->list(hasMore: true); // cursor 0 of 4
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'j')); // -> 1
        $this->assertQuiet($cmd, 'not last yet');

        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'j')); // -> 2
        $this->assertQuiet($cmd, 'not last yet');

        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'j')); // -> 3 == last: FIRE
        $this->assertFire($cmd);

        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'j')); // already last, clamped
        $this->assertQuiet($cmd, 'resting on the last item stays quiet');
    }

    public function testJumpToEndArrivesAndFires(): void
    {
        $l = $this->list(hasMore: true);
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'G'));
        $this->assertFire($cmd);
        $this->assertSame(3, $l->index());
    }

    public function testPageDownIntoLastFires(): void
    {
        $l = $this->list(hasMore: true); // height 3, items 4
        [$l, $cmd] = $l->update($this->key(KeyType::PageDown)); // 0 -> 3 == last
        $this->assertFire($cmd);
    }

    public function testInfiniteWrapUpFromTopLandsOnLastAndFires(): void
    {
        $l = $this->list(hasMore: true, infinite: true);
        [$l, $cmd] = $l->update($this->key(KeyType::Up)); // 0 wraps to last
        $this->assertSame(3, $l->index());
        $this->assertFire($cmd);
    }

    public function testInfiniteStepPastLastWrapsAwayWithoutFire(): void
    {
        $l = $this->list(hasMore: true, infinite: true);
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'G')); // arrive last: fire
        $this->assertFire($cmd);
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'j')); // wrap to 0: leave
        $this->assertQuiet($cmd);
        $this->assertSame(0, $l->index());
    }

    public function testLeaveAndReturnFiresAgain(): void
    {
        $l = $this->list(hasMore: true);
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'G'));
        $this->assertFire($cmd);
        [$l, $cmd] = $l->update($this->key(KeyType::Up)); // leave: quiet
        $this->assertQuiet($cmd);
        [$l, $cmd] = $l->update($this->key(KeyType::Down));
        $this->assertFire($cmd); // second arrival: fires again
    }

    public function testDefaultShapeIsSilent(): void
    {
        $l = $this->list(); // hasMore unset => false
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'G'));
        $this->assertNull($cmd, 'pre-5.15 hosts see the old [model, null] pair');
    }

    public function testMouseArrivalComposesWithTheKeyboardFunnel(): void
    {
        $l = $this->list(hasMore: true);
        // click row 3 of the window => absolute visible index 2 — NOT the last (4 items).
        [$l, $cmd] = $l->update(new MouseMsg(1, 3, MouseButton::Left, MouseAction::Press));
        $this->assertQuiet($cmd);
        $this->assertSame(2, $l->index());

        $wheel = new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press);
        [$l, $cmd] = $l->update($wheel); // 2 -> 3 == last visible -> arrival fires
        $this->assertFire($cmd);

        [$l, $cmd] = $l->update($wheel); // resting (clamped) -> quiet
        $this->assertQuiet($cmd);
    }

    public function testEmptyListNeverFires(): void
    {
        $l = ItemList::new([], 20, 3);
        [$l, ] = $l->focus();
        $l = $l->withHasMore();
        [$l, $cmd] = $l->update($this->key(KeyType::Char, 'G'));
        $this->assertNull($cmd);
    }

    public function testFilteringNavigationIsExcluded(): void
    {
        $l = $this->list(hasMore: true);
        [$l, $cmd] = $l->update(new KeyMsg(KeyType::Char, '/')); // enter filter
        $this->assertQuiet($cmd);
        [$l, $cmd] = $l->update(new KeyMsg(KeyType::Char, '3')); // types into filter, cursor parks at 0
        $this->assertNull($cmd);
    }

    public function testLoadMoreMsgIsAPublicMarkerShape(): void
    {
        $this->assertTrue(interface_exists(\SugarCraft\Core\Msg::class));
        $a = new LoadMoreMsg();
        $this->assertInstanceOf(\SugarCraft\Core\Msg::class, $a);
    }
}
