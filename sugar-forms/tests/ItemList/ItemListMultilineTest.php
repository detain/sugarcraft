<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\Lang;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

/**
 * SF-47 (sugar-forms-074/-075): titles are refused at the door when they
 * carry newlines (the walk counts one line per title), descriptions get
 * per-physical-line accounting on both the budget and the click axis, and
 * prefixes are measured — never assumed two cells.
 */
final class ItemListMultilineTest extends TestCase
{
    private static function item(string $title, string $description = ''): Item
    {
        return new class ($title, $description) implements Item {
            public function __construct(
                private readonly string $t,
                private readonly string $d,
            ) {}

            public function title(): string
            {
                return $this->t;
            }

            public function description(): string
            {
                return $this->d;
            }

            public function filterValue(): string
            {
                return $this->t;
            }
        };
    }

    /** Chrome-free body so line 1 IS the first item title. */
    private static function bare(array $items, int $visibleLines = 0): ItemList
    {
        $list = ItemList::new($items, 40, 10)
            ->withTitle('')
            ->withShowStatusBar(false)
            ->withShowFilter(false);
        return $visibleLines > 0 ? $list->withVisibleLines($visibleLines) : $list;
    }

    /** @return list<string> */
    private static function rows(ItemList $list): array
    {
        return explode("\n", $list->view());
    }

    private static function click(ItemList $list, int $y): ItemList
    {
        [$focused, ] = $list->focus();
        [$next, ] = $focused->update(new MouseMsg(1, $y, MouseButton::Left, MouseAction::Press));

        return $next;
    }

    public function testMultiLineTitleIsRefusedAtEveryDoor(): void
    {
        $bad = self::item("first\nsecond");

        foreach (['new' => static fn () => ItemList::new([$bad]),
            'setItems' => static fn () => self::bare([self::item('ok')])->setItems([$bad]),
            'setItem' => static fn () => self::bare([self::item('ok')])->setItem(0, $bad),
        ] as $door => $call) {
            $thrown = null;
            try {
                $call();
                $this->fail("{$door} accepted a multi-line title");
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }
            $this->assertSame(Lang::t('list.title_multiline'), $thrown, $door);
        }
    }

    public function testSingleLineTitlesStillPassTheDoors(): void
    {
        $list = self::bare([self::item('plain'), self::item("wide\u{FF0B}")]);
        $this->assertSame(2, count($list->items()));
    }

    public function testEveryPhysicalDescriptionLineIsClickable(): void
    {
        $list = self::bare([
            self::item('alpha', "one\ntwo\nthree"),
            self::item('beta', 'solo'),
        ]);
        $rows = self::rows($list);
        // alpha: lines 1-4 (title + 3 description rows), beta: lines 5-6.
        $this->assertCount(6, $rows);
        $this->assertSame('    two', $rows[2]);
        foreach ([1, 2, 3, 4] as $y) {
            $this->assertSame(0, $this->click($list, $y)->index(), "line {$y} selects alpha");
        }
        foreach ([5, 6] as $y) {
            $this->assertSame(1, $this->click($list, $y)->index(), "line {$y} selects beta");
        }
    }

    public function testLineBudgetCountsMultiLineBodies(): void
    {
        $list = self::bare([
            self::item('alpha', "one\ntwo\nthree"),
            self::item('beta', 'solo'),
        ], visibleLines: 5);
        // alpha costs 4 lines; beta costs 2 and would overrun the 5-line
        // budget, so the window paints alpha only.
        $this->assertCount(4, self::rows($list));
        $this->assertSame(4, $list->renderedLineCount());
    }

    public function testSingleLineDescriptionBytesAreUnchanged(): void
    {
        $rows = self::rows(self::bare([self::item('alpha', 'solo')]));
        // Byte-for-byte the pre-SF-47 shape: legacy reverse-video cursor
        // row + the four-cell description indent.
        $this->assertSame(["> [7malpha[0m", '    solo'], $rows);
    }

    public function testWidePrefixShiftsClipNotClick(): void
    {
        $list = self::bare([self::item('alpha', 'solo'), self::item('beta', 'solo')])
            ->withCursorPrefix('➔➔ ')
            ->withUnselectedPrefix('😊 ')
            ->setSize(40, 2)
            ->withVisibleLines(3);
        $rows = self::rows($list);
        $this->assertSame(2, count($rows), 'a 3-line budget fits one 2-line body plus the next title');
        $this->assertLessThanOrEqual(40, Width::of($rows[0]));
        // Line addressing never consulted the prefixes, so clicks hold.
        $this->assertSame(0, $this->click($list, 1)->index());
    }
}
