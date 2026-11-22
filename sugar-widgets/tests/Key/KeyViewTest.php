<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Key;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Key\KeyMap;
use SugarCraft\Widgets\Key\KeyView;

/**
 * KeyView: the KeyMap a sugar-core KeyBindings registry presents to Help.
 * Every Binding it hands out is derived from the registry — keys, help key
 * and description — and the layout knobs are immutable.
 */
final class KeyViewTest extends TestCase
{
    private static function registry(): KeyBindings
    {
        return KeyBindings::new()
            ->bind('up', ['up', 'k'], 'move up')
            ->bind('down', ['down', 'j'], 'move down')
            ->bind('save', 'ctrl+s', 'save')
            ->bind('quit', ['q', 'ctrl+c'], 'quit');
    }

    public function testIsAKeyMap(): void
    {
        self::assertInstanceOf(KeyMap::class, KeyView::new(self::registry()));
    }

    public function testBindingIsDerivedFromTheRegistry(): void
    {
        $b = KeyView::new(self::registry())->binding('quit');

        self::assertInstanceOf(Binding::class, $b);
        self::assertSame(['q', 'ctrl+c'], $b->keys);
        self::assertSame('q/ctrl+c', $b->help->key);
        self::assertSame('quit', $b->help->desc);
        self::assertFalse($b->disabled);
    }

    public function testDescriptionFallsBackToTheActionId(): void
    {
        $b = KeyView::new(KeyBindings::new()->bind('jump', 'g'))->binding('jump');

        self::assertSame('g', $b->help->key);
        self::assertSame('jump', $b->help->desc);
    }

    public function testAnUnboundActionIsADisabledBinding(): void
    {
        $keys = self::registry()->withOverride('save');
        $b = KeyView::new($keys)->binding('save');

        self::assertTrue($b->disabled);
        self::assertSame([], $b->keys);
    }

    public function testShortHelpDefaultsToEveryActionInOrder(): void
    {
        $view = KeyView::new(self::registry());

        self::assertSame(
            ['↑/k', '↓/j', 'ctrl+s', 'q/ctrl+c'],
            array_map(static fn(Binding $b): string => $b->help->key, $view->shortHelp()),
        );
    }

    public function testWithShortPicksAndResets(): void
    {
        $view = KeyView::new(self::registry());
        $picked = $view->withShort('quit', 'save');

        self::assertSame(['quit', 'save'], array_map(
            static fn(Binding $b): string => $b->help->desc,
            $picked->shortHelp(),
        ));
        self::assertCount(4, $picked->withShort()->shortHelp());
        self::assertCount(4, $view->shortHelp(), 'the original is untouched');
    }

    public function testFullHelpIsOneColumnByDefaultAndChunksBySize(): void
    {
        $view = KeyView::new(self::registry());

        self::assertCount(1, $view->fullHelp());
        self::assertCount(4, $view->fullHelp()[0]);

        $chunked = $view->withColumnSize(3)->fullHelp();
        self::assertCount(2, $chunked);
        self::assertCount(3, $chunked[0]);
        self::assertCount(1, $chunked[1]);
        self::assertSame([], KeyView::new(KeyBindings::new())->fullHelp());
    }

    public function testWithColumnsLaysOutExplicitlyAndResets(): void
    {
        $view = KeyView::new(self::registry())->withColumns([['up', 'down'], ['quit']]);
        $cols = $view->fullHelp();

        self::assertCount(2, $cols);
        self::assertSame('move down', $cols[0][1]->help->desc);
        self::assertSame('quit', $cols[1][0]->help->desc);
        self::assertCount(1, $view->withColumns([])->fullHelp());
    }

    public function testWithColumnsRejectsABadShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyView::new(self::registry())->withColumns([['up', 5]]);
    }

    public function testWithColumnSizeRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyView::new(self::registry())->withColumnSize(-1);
    }

    public function testWithKeySeparatorAndWithBindings(): void
    {
        $view = KeyView::new(self::registry())->withKeySeparator(' ');
        self::assertSame('q ctrl+c', $view->binding('quit')->help->key);

        $remapped = $view->withBindings(self::registry()->withOverride('quit', 'ctrl+q'));
        self::assertSame('ctrl+q', $remapped->binding('quit')->help->key);
        self::assertSame('q ctrl+c', $view->binding('quit')->help->key);
    }
}
