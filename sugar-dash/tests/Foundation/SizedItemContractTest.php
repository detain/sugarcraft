<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Dash\Components\Card\Paragraph;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\SizedItem;
use SugarCraft\Dash\Keys\KeyMap;
use SugarCraft\Dash\Layout\Split;
use SugarCraft\Dash\Layout\Viewport;
use SugarCraft\Dash\Layout\VStack;
use SugarCraft\Dash\Layout\ZStack;

/**
 * A Sizer deliberately written WITHOUT getInnerSize(), the way a third-party
 * implementation of the published Sizer contract may legally be written.
 */
final class BareThirdPartySizer implements Sizer
{
    public function __construct(
        private readonly string $body,
        private readonly int $w,
        private readonly int $h,
    ) {}

    public function render(): string
    {
        return $this->body;
    }

    public function setSize(int $width, int $height): Sizer
    {
        return $this;
    }

    public function naturalWidth(): int
    {
        return $this->w;
    }

    public function naturalHeight(): int
    {
        return $this->h;
    }
}

/**
 * Audit finding #2: layout containers measured children behind a bare
 * `instanceof Sizer` guard and then called `getInnerSize()`, which the Sizer
 * contract never declared — a third-party Sizer fataled. SizedItem now owns
 * the read-back contract; guards ask for SizedItem, and classes that answer
 * getInnerSize declare SizedItem (still instanceof Sizer, so in-tree
 * behaviour is byte-identical).
 */
final class SizedItemContractTest extends TestCase
{
    private const BARE = "ab\ncd";

    public function testTheSizedItemContractExtendsSizerAndAddsTheReadBack(): void
    {
        self::assertTrue(is_subclass_of(SizedItem::class, Sizer::class));
        self::assertTrue((new ReflectionMethod(SizedItem::class, 'getInnerSize'))->hasReturnType());
        // Sizer itself still promises only setSize() — widening it would
        // break every existing third-party implementation.
        self::assertSame(['setSize', 'render'], array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Sizer::class))->getMethods(),
        ));
    }

    public function testEveryDeclarerOfGetInnerSizeImplementsSizedItem(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $line) {
                if (str_contains($line, 'public function getInnerSize') && !str_ends_with(rtrim($line), ';')) {
                    // concrete declaration: the owning class must have been
                    // migrated to SizedItem somewhere in this file
                    $contents = file_get_contents($file->getPathname());
                    if (!str_contains((string) $contents, 'SizedItem')) {
                        self::fail($file->getPathname() . ' declares getInnerSize() but never names SizedItem');
                    }
                    $checked++;
                    break;
                }
            }
        }
        self::assertGreaterThan(180, $checked, 'the in-tree SizedItem population should not shrink');
    }

    public function testAThirdPartySizerWithoutGetInnerSizeDoesNotFatalInVStack(): void
    {
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);
        $stack = VStack::new($bare);

        // Measure path (previously: instanceof Sizer -> getInnerSize() fatal)
        [$w, $h] = $stack->getInnerSize();
        self::assertSame(2, $w, 'falls back to render-measured width');
        self::assertSame(2, $h, 'falls back to render-measured height');
    }

    public function testAThirdPartySizerWithoutGetInnerSizeDoesNotFatalInZStack(): void
    {
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);
        $stack = ZStack::new($bare);

        [$w, $h] = $stack->getInnerSize();
        self::assertSame(2, $w);
        self::assertSame(2, $h);
    }

    public function testAThirdPartySizerKeepsItsAllocatedBoxInSplit(): void
    {
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);
        $split = Split::horizontal([$bare, $bare], [0.5, 0.5])->setSize(40, 10);

        // Split::getInnerSize() previously fataled on a Sizer that only
        // implements the published contract; now the allocated pane box wins.
        [$w, $h] = $split->getInnerSize();
        self::assertGreaterThan(0, $w);
        self::assertSame(10, $h);
    }

    public function testViewportTreatsUnmeasurableContentAsFillingThePane(): void
    {
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);
        $viewport = Viewport::new($bare);

        self::assertFalse($viewport->canScroll());
        self::assertIsString($viewport->render());
    }

    public function testKeyMapReportsZeroNaturalSizeForUnmeasurableContent(): void
    {
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);

        self::assertSame([0, 0], KeyMap::new($bare)->getInnerSize());
        // An explicit allocation still wins over the fallback.
        self::assertSame([7, 3], KeyMap::new($bare)->setSize(7, 3)->getInnerSize());
    }

    public function testContainerItemsAreStillAcceptedAsPlainSizersWhereOnlySetSizeIsUsed(): void
    {
        // instanceof Sizer remains true for every SizedItem — guards that
        // only call setSize() keep firing for migrated classes.
        $bare = new BareThirdPartySizer(self::BARE, 2, 2);
        $text = Paragraph::new('hello');
        self::assertTrue($text instanceof Sizer, 'migrated classes remain instanceof Sizer');
        self::assertTrue($text instanceof SizedItem);
        self::assertTrue($bare instanceof Sizer);
        self::assertFalse($bare instanceof SizedItem);
        // Mixed stack: migrated item keeps the fast getInnerSize path,
        // the bare item falls back to render measurement — both render.
        $stack = VStack::new($text, $bare);
        self::assertSame(
            max($text->getInnerSize()[0], 2),
            $stack->getInnerSize()[0],
        );
        self::assertIsString($stack->render());
    }
}
