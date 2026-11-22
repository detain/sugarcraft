<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Widgets\Help\Help;
use SugarCraft\Widgets\Key\KeyView;

/**
 * P2-1 (AUD D11): Help::fullView() over a sugar-core KeyBindings registry is
 * generated from the registry — every line is re-derived here from the same
 * registry the dispatch reads, so no hand-maintained help text can survive.
 */
final class HelpFromBindingsTest extends TestCase
{
    private static function registry(): KeyBindings
    {
        return KeyBindings::new()
            ->bind('up', ['up', 'k'], 'move up')
            ->bind('down', ['down', 'j'], 'move down')
            ->bind('save', 'ctrl+s', 'save')
            ->bind('back', 'shift+tab', 'previous pane')
            ->bind('quit', ['q', 'ctrl+c'], 'quit');
    }

    /**
     * The help lines a registry must produce, derived from the registry
     * itself: label with the `/` separator, the gap, the help text.
     *
     * @return list<string>
     */
    private static function derivedLines(KeyBindings $keys): array
    {
        return array_map(
            static fn(string $id): string => $keys->label($id, '/') . ' ' . $keys->help($id),
            $keys->actions(),
        );
    }

    public function testFullViewLinesMatchTheBindingsExactly(): void
    {
        $keys = self::registry();

        self::assertSame(self::derivedLines($keys), explode("\n", Help::new()->fullView($keys)));
    }

    public function testTheGeneratedBlockByteForByte(): void
    {
        self::assertSame(
            "↑/k move up\n↓/j move down\nctrl+s save\nshift+tab previous pane\nq/ctrl+c quit",
            Help::new()->fullView(self::registry()),
        );
    }

    public function testAUserOverrideLandsInTheHelpWithoutEditingAString(): void
    {
        $dir = sys_get_temp_dir() . '/sc-help-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $path = $dir . '/keys.json';
        file_put_contents($path, '{"quit": ["ctrl+q"], "save": ["f2", "ctrl+s"]}');
        try {
            $keys = KeyBindings::fromFile($path, self::registry());
        } finally {
            unlink($path);
            rmdir($dir);
        }

        $lines = explode("\n", Help::new()->fullView($keys));
        self::assertSame(self::derivedLines($keys), $lines);
        self::assertContains('ctrl+q quit', $lines);
        self::assertContains('f2/ctrl+s save', $lines);
    }

    public function testShortViewAndViewAcceptTheRegistryToo(): void
    {
        $keys = self::registry();
        $help = Help::new();

        self::assertSame(implode(' • ', self::derivedLines($keys)), $help->shortView($keys));
        self::assertSame($help->shortView($keys), $help->view($keys));
        self::assertSame($help->fullView($keys), $help->withShowAll()->view($keys));
    }

    public function testRegistryAndKeyViewRenderIdentically(): void
    {
        $keys = self::registry();

        self::assertSame(Help::new()->fullView(KeyView::new($keys)), Help::new()->fullView($keys));
    }

    public function testColumnLayoutKeepsEveryDerivedEntry(): void
    {
        $keys = self::registry();
        $block = Help::new()->fullView(KeyView::new($keys)->withColumnSize(3));

        self::assertSame(
            "↑/k move up      shift+tab previous pane\n"
            . "↓/j move down    q/ctrl+c quit\n"
            . 'ctrl+s save',
            $block,
        );
        foreach (self::derivedLines($keys) as $line) {
            self::assertStringContainsString($line, $block);
        }
    }

    public function testUnboundActionsAreLeftOutUnlessShowDisabled(): void
    {
        $keys = self::registry()->withOverride('save');

        self::assertStringNotContainsString('save', Help::new()->fullView($keys));
        self::assertStringContainsString(' save', Help::new()->withShowDisabled()->fullView($keys));
    }
}
