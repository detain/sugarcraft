<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;
use SugarCraft\Mouse\ZoneId;
use SugarCraft\Mouse\ZoneRouter;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Zone as ZonesZone;

/**
 * P2-8 (b): routing a hit by typed id. The consumer pattern it replaces is a
 * chain of `str_starts_with($id, 'toolcall:')` tests plus `(int) substr(...)`.
 */
final class ZoneRouterTest extends TestCase
{
    public function testExactRouteBeatsNamespaceRoute(): void
    {
        $router = ZoneRouter::new()
            ->onNamespace('menu', static fn (ZoneId $id): string => 'menu:' . $id->key)
            ->on(ZoneId::of('menu', 'quit'), static fn (ZoneId $id): string => 'QUIT');

        self::assertSame('QUIT', $router->route('menu:quit'));
        self::assertSame('menu:open', $router->route('menu:open'));
        self::assertSame('QUIT', $router->route(ZoneId::of('menu', 'quit')));
    }

    public function testNamespaceMatchesByEqualityNeverByPrefix(): void
    {
        $router = ZoneRouter::new()->onNamespace('tab', static fn (ZoneId $id): ?int => $id->intKey());

        self::assertSame(3, $router->route('tab:3'));
        self::assertTrue($router->handles('tab'));
        self::assertFalse($router->handles('tabs:3'), "'tab' must not catch 'tabs:3'");
        self::assertNull($router->route('tabs:3'));
    }

    public function testUnroutableIdsAreNotErrors(): void
    {
        $router = ZoneRouter::new()->onNamespace('row', static fn (): string => 'hit');

        self::assertFalse($router->handles('has space'));
        self::assertFalse($router->handles('row:'), 'not a typed id');
        self::assertNull($router->route('other:1'));
        self::assertNull(ZoneRouter::new()->route('row:1'));
    }

    public function testRouteForwardsExtraArguments(): void
    {
        $router = ZoneRouter::new()->onNamespace(
            'row',
            static fn (ZoneId $id, string $verb, int $n): string => "{$verb} {$id->key} x{$n}",
        );

        self::assertSame('open 4 x2', $router->route('row:4', 'open', 2));
    }

    public function testRegistrationIsImmutableAndReplacesEarlierRoutes(): void
    {
        $base = ZoneRouter::new();
        $one = $base->onNamespace('row', static fn (): int => 1);
        $two = $one->onNamespace('row', static fn (): int => 2);

        self::assertFalse($base->handles('row:1'));
        self::assertSame(1, $one->route('row:1'));
        self::assertSame(2, $two->route('row:1'));

        $exact = $two->on(ZoneId::of('row', 1), static fn (): int => 10)->on(ZoneId::of('row', 1), static fn (): int => 11);
        self::assertSame(11, $exact->route('row:1'));
        self::assertSame(2, $exact->route('row:2'));
    }

    public function testOnNamespaceRefusesAnInvalidNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ZoneRouter::new()->onNamespace('a:b', static fn (): null => null);
    }

    public function testRouteMouseHitsTheSmallestZoneOfAScanner(): void
    {
        // A typed button nested inside an untyped panel on row 1.
        $frame = Mark::zone('panel', 'AB' . ZoneId::of('btn', 'ok')->mark('OK') . 'CD') . "\n" . ZoneId::of('row', 2)->mark('item two');
        $scanner = Scanner::new()->scan($frame);

        $seen = [];
        $router = ZoneRouter::new()
            ->on(ZoneId::of('btn', 'ok'), static function (ZoneId $id, MouseMsg $msg, Zone $zone) use (&$seen): string {
                $seen[] = [$id->wire(), $msg->x, $zone->id];
                return 'ok';
            })
            ->onNamespace('row', static fn (ZoneId $id): ?int => $id->intKey());

        self::assertSame('ok', $router->routeMouse(self::click(3, 1), $scanner), 'innermost zone owns the cell');
        self::assertSame([['btn:ok', 3, 'btn:ok']], $seen);
        self::assertSame(2, $router->routeMouse(self::click(5, 2), $scanner));
        self::assertNull($router->routeMouse(self::click(1, 1), $scanner), "'panel' is a hit with no route");
        self::assertNull($router->routeMouse(self::click(40, 9), $scanner), 'no zone at all');
    }

    public function testRouteMouseAcceptsAPrimitiveEvent(): void
    {
        $scanner = Scanner::new()->scan(ZoneId::of('row', 9)->mark('nine'));
        $router = ZoneRouter::new()->onNamespace('row', static fn (ZoneId $id, MouseEvent $e): string => $id->key . '@' . $e->x);

        self::assertSame('9@2', $router->routeMouse(MouseEvent::press(2, 1), $scanner));
    }

    public function testRouteMouseStripsAPrefixedManagersOwnPrefix(): void
    {
        $manager = Manager::newPrefix('list-');
        $manager->scan($manager->mark(ZoneId::of('item', 5)->wire(), 'five'));

        $router = ZoneRouter::new()->onNamespace(
            'item',
            static fn (ZoneId $id, MouseMsg $msg, ZonesZone $zone): string => $id->key . ' ' . $zone->id,
        );

        self::assertSame('5 list-item:5', $router->routeMouse(self::click(1, 1), $manager));
        self::assertNull($router->routeMouse(self::click(30, 1), $manager));
    }

    private static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }
}
