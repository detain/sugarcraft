<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\NavStack\NavigationItem;
use SugarCraft\NavStack\NavStack;
use SugarCraft\NavStack\Url;

/**
 * Guards the bounded-work contracts added with the zone-forgery remediation:
 * payload search depth, title byte budget, stack depth ceiling, and the
 * intake validators that keep junk out of the stack in the first place.
 */
final class DepthGuardsTest extends TestCase
{
    public function testSelfReferencingPayloadThrowsInsteadOfRecursingForever(): void
    {
        $payload = ['k' => 'v'];
        $payload['self'] = &$payload;

        $stack = (new NavStack())->push('x', $payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('64-level search limit');
        $stack->filter('zz');
    }

    public function testOverDeepNestingThrows(): void
    {
        $deep = 'x';
        for ($i = 0; $i < 70; $i++) {
            $deep = [$deep];
        }

        $stack = (new NavStack())->push('y', $deep);

        $this->expectException(InvalidArgumentException::class);
        $stack->filter('zz');
    }

    public function testNestingWithinTheBudgetStaysSearchable(): void
    {
        $nested = 'needle';
        for ($i = 0; $i < 50; $i++) {
            $nested = [$nested];
        }

        $stack = (new NavStack())->push('y', $nested);

        self::assertSame(1, $stack->filter('needle')->depth());
        self::assertSame(0, $stack->filter('nope-no-match')->depth());
    }

    public function testTitleByteBudgetEdge(): void
    {
        $at = str_repeat('a', NavigationItem::MAX_TITLE_BYTES);
        $over = $at . 'b';

        self::assertSame(65536, NavigationItem::MAX_TITLE_BYTES);
        self::assertSame($at, (new NavigationItem($at))->title);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('65536 bytes');
        new NavigationItem($over);
    }

    public function testStackDepthCeilingIsLoud(): void
    {
        $stack = new NavStack();
        for ($i = 0; $i < NavStack::MAX_STACK_DEPTH; $i++) {
            $stack->push('level-' . $i);
        }

        self::assertSame(10000, NavStack::MAX_STACK_DEPTH);
        self::assertSame(10000, $stack->depth());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('10000 levels');
        $stack->push('one too many');
    }

    public function testBlankTitlesRefusedAtTheFrontDoor(): void
    {
        foreach (['', '   ', "\t", "\n"] as $blank) {
            try {
                new NavigationItem($blank);
                self::fail('blank title accepted: ' . json_encode($blank));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('must not be empty', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        (new NavStack())->push('  ');
    }

    public function testSetItemsRefusesForeignElements(): void
    {
        $stack = new NavStack();

        try {
            $stack->setItems(['plain string']);
            self::fail('string element accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('string', $e->getMessage());
        }

        try {
            $stack->setItems([new NavigationItem('ok'), 42]);
            self::fail('int element accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('int', $e->getMessage());
        }

        $stack->setItems([new NavigationItem('ok')]);
        self::assertSame(1, $stack->depth());
    }

    public function testSetItemsReKeysNonListArrays(): void
    {
        $stack = new NavStack();
        $stack->setItems(['z' => new NavigationItem('first'), 'a' => new NavigationItem('second')]);

        // array_values re-keying keeps items() list-shaped for consumers.
        self::assertSame([0, 1], array_keys($stack->items()));
        self::assertSame('first', $stack->items()[0]->title);
    }

    public function testUrlParseDropsWhitespaceOnlySegments(): void
    {
        // Pairs the NavigationItem blank-title refusal: an encoded blank never
        // becomes an item the constructor would refuse on the next round-trip.
        $stack = Url::parse('/%20');
        self::assertSame(0, $stack->depth());

        $mixed = Url::parse('/a/%20/b');
        self::assertSame(['a', 'b'], array_map(static fn (NavigationItem $i): string => $i->title, $mixed->items()));
    }
}
