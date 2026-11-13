<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\TreeList\DefaultPrefixer;
use SugarCraft\TreeList\DefaultSuffixer;
use SugarCraft\TreeList\Model;
use SugarCraft\TreeList\StringItem;

/**
 * Pins the treelist-001 rename surface: every deprecated `set*()`/`add*()`/
 * `cursor*()` alias must (a) still exist, (b) delegate to its `with*()`
 * canonical with identical observable result, and (c) leave the receiver
 * untouched. Removing or mis-wiring any alias reddens a case here — the
 * deprecation path is pinned, not just documented.
 */
final class DeprecatedSurfaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}> old => new
     */
    public static function aliasPairs(): array
    {
        return [
            'setWidth'            => ['setWidth', 'withWidth'],
            'setHeight'           => ['setHeight', 'withHeight'],
            'setViewport'         => ['setViewport', 'withViewport'],
            'setCursorOffset'     => ['setCursorOffset', 'withCursorOffset'],
            'setWrap'             => ['setWrap', 'withWrap'],
            'setLineOffset'       => ['setLineOffset', 'withLineOffset'],
            'setLessFunc'         => ['setLessFunc', 'withLessFunc'],
            'setEqualsFunc'       => ['setEqualsFunc', 'withEqualsFunc'],
            'setPrefixer'         => ['setPrefixer', 'withPrefixer'],
            'setSuffixer'         => ['setSuffixer', 'withSuffixer'],
            'setLineStyle'        => ['setLineStyle', 'withLineStyle'],
            'setCurrentStyle'     => ['setCurrentStyle', 'withCurrentStyle'],
            'addItem'             => ['addItem', 'withItem'],
            'addItems'            => ['addItems', 'withItems'],
            'addItemsFromArray'   => ['addItemsFromArray', 'withItemsFromArray'],
            'setCursor'           => ['setCursor', 'withCursor'],
            'cursorUp'            => ['cursorUp', 'withCursorUp'],
            'cursorDown'          => ['cursorDown', 'withCursorDown'],
            'cursorPageUp'        => ['cursorPageUp', 'withCursorPageUp'],
            'cursorPageDown'      => ['cursorPageDown', 'withCursorPageDown'],
            'cursorToStart'       => ['cursorToStart', 'withCursorToStart'],
            'cursorToEnd'         => ['cursorToEnd', 'withCursorToEnd'],
        ];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $pairs
     * @dataProvider aliasPairs
     */
    public function testDeprecatedAliasStillExists(string $old, string $new): void
    {
        self::assertTrue(method_exists(Model::class, $old), "deprecated alias {$old}() must survive until 2.0.0");
        self::assertTrue(method_exists(Model::class, $new), "canonical {$new}() must exist");
    }

    public function testGeometryAliasesDelegateAndLeaveReceiverUntouched(): void
    {
        $base = Model::new();

        $pairs = [
            ['setWidth', 'withWidth', [42], static fn (Model $m): array => [$m->width()]],
            ['setHeight', 'withHeight', [12], static fn (Model $m): array => [$m->height()]],
            ['setViewport', 'withViewport', [33, 9], static fn (Model $m): array => [$m->width(), $m->height()]],
            ['setCursorOffset', 'withCursorOffset', [2], static fn (Model $m): array => [$m->cursorOffset()]],
            ['setWrap', 'withWrap', [3], static fn (Model $m): array => [$m->wrap()]],
            ['setLineOffset', 'withLineOffset', [4], static fn (Model $m): array => [$m->lineOffset()]],
        ];
        foreach ($pairs as [$old, $new, $args, $read]) {
            $viaOld = $base->{$old}(...$args);
            $viaNew = $base->{$new}(...$args);
            self::assertNotSame($base, $viaOld, "{$old}() must return a new instance");
            self::assertSame($read($viaNew), $read($viaOld), "{$old}() must delegate to {$new}()");
            self::assertSame([80, 24], [$base->width(), $base->height()], "receiver must be untouched after {$old}()");
        }
    }

    public function testCallbackAndHookAliasesDelegate(): void
    {
        $base  = Model::new();
        $less  = static fn (\Stringable $a, \Stringable $b): int => 0;
        $equal = static fn (\Stringable $a, \Stringable $b): bool => true;
        $pre   = new DefaultPrefixer();
        $suf   = new DefaultSuffixer();

        self::assertSame($base->withLessFunc($less)->lessFunc(), $base->setLessFunc($less)->lessFunc());
        self::assertSame($base->withEqualsFunc($equal)->equalsFunc(), $base->setEqualsFunc($equal)->equalsFunc());
        self::assertSame($pre, $base->setPrefixer($pre)->prefixer());
        self::assertSame($suf, $base->setSuffixer($suf)->suffixer());
        self::assertSame("\e[1m", $base->setLineStyle("\e[1m")->lineStyle());
        self::assertSame("\e[7m", $base->setCurrentStyle("\e[7m")->currentStyle());
        self::assertSame($base->withLineStyle("\e[1m")->lineStyle(), $base->setLineStyle("\e[1m")->lineStyle());
        self::assertSame($base->withCurrentStyle("\e[7m")->currentStyle(), $base->setCurrentStyle("\e[7m")->currentStyle());
    }

    public function testItemIntakeAliasesDelegate(): void
    {
        $base = Model::new();
        $one  = new StringItem('solo');

        self::assertSame(
            $base->withItem($one)->itemIds(),
            $base->addItem($one)->itemIds(),
            'addItem() must mint the same state as withItem()'
        );
        self::assertSame('solo', (string) $base->addItem($one)->itemAt(0));

        $many = [new StringItem('a'), new StringItem('b')];
        self::assertSame(
            $base->withItems(...$many)->itemIds(),
            $base->addItems(...$many)->itemIds()
        );
        self::assertSame(
            $base->withItemsFromArray(['x', 'y'])->length(),
            $base->addItemsFromArray(['x', 'y'])->length()
        );
        self::assertSame(0, $base->length(), 'receiver must be untouched after alias call');
    }

    public function testCursorMoverAliasesDelegate(): void
    {
        $base = Model::new()->withItemsFromArray(['a', 'b', 'c', 'd', 'e', 'f']);

        self::assertSame($base->withCursor(3)->cursorIndex(), $base->setCursor(3)->cursorIndex());
        self::assertSame($base->withCursorUp(2)->cursorIndex(), $base->cursorUp(2)->cursorIndex());
        self::assertSame($base->withCursorDown(2)->cursorIndex(), $base->cursorDown(2)->cursorIndex());
        self::assertSame($base->withCursorPageUp(1)->cursorIndex(), $base->cursorPageUp(1)->cursorIndex());
        self::assertSame($base->withCursorPageDown(1)->cursorIndex(), $base->cursorPageDown(1)->cursorIndex());
        self::assertSame($base->withCursorToStart()->cursorIndex(), $base->cursorToStart()->cursorIndex());
        self::assertSame($base->withCursorToEnd()->cursorIndex(), $base->cursorToEnd()->cursorIndex());
        self::assertSame(0, $base->cursorIndex(), 'receiver must be untouched after alias call');
    }
}
