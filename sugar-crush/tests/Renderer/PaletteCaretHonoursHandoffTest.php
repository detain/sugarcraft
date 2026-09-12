<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tui\Components\MenuBar;

/**
 * E3 (caret-paint half): the palette's block caret is its keyboard claim, so
 * the builder must not forge it once the shell holds the keys.
 *
 * {@see AbandonedPaletteIsNotCompositedTest} pins the whole-box E682 stand-down
 * at the App seam. This pins the LOCAL symmetry inside
 * {@see Renderer::renderPalette()} itself: with the abandonment static armed,
 * the query line paints without its `█` tail while the box still renders
 * (wire-not-delete - a builder change, not a compositing one). Today the only
 * caller of renderPalette() is renderView()'s gated overlay chain
 * (`!self::$paletteAbandoned`), so the armed builder is unreachable through
 * the public entry points - the states are built deliberately via reflection
 * exactly as KeyHelpTest builds the chain states it pins, so the rule holds
 * for every future caller too.
 *
 * The palette fixture rides the canonical keyboard route - Ctrl+P then the
 * query keystrokes, same open-palette shape KeyHelpTest builds at :859.
 */
final class PaletteCaretHonoursHandoffTest extends TestCase
{
    /** Byte-identical fixture across the three arms - only the static differs. */
    private const PALETTE_QUERY = 'mar';

    private const QUERY_LINE_NEEDLE = '🔍';

    private const CARET_GLYPH = '█';

    protected function tearDown(): void
    {
        // The abandonment static is frame-scoped in production (App::view
        // resets it in finally); arm it only for one assertion at a time.
        Renderer::setPaletteAbandoned(false);
        MenuBar::closeMenu();
        Renderer::scanner()->clear();
    }

    public function testTheBuilderPaintsTheCaretWhileThePaletteDrives(): void
    {
        $box = $this->paletteBoxWithHandoff(false);

        self::assertStringContainsString(self::QUERY_LINE_NEEDLE, $box, 'the query line must still paint while driving');
        self::assertStringContainsString(self::CARET_GLYPH, $box, 'the driving palette claims the keys - the caret is that claim');
    }

    public function testTheBuilderWithholdsTheCaretOnceTheShellHoldsTheKeys(): void
    {
        $box = $this->paletteBoxWithHandoff(true);

        self::assertStringContainsString(self::QUERY_LINE_NEEDLE, $box, 'wire-not-delete: the box keeps rendering, only the keyboard claim goes');
        self::assertStringNotContainsString(self::CARET_GLYPH, $box, 'the builder must not forge the caret while the shell holds the keys');
    }

    public function testHandOffSwapsTheCaretForItsPadCellAndNoOtherByte(): void
    {
        $driving = $this->paletteBoxWithHandoff(false);
        $handedOff = $this->paletteBoxWithHandoff(true);

        // Exact minimal delta: the query line is padded to the box width, so
        // the caret's cell is absorbed by one more pad space and every other
        // byte - borders, rows, SGR - is untouched.
        self::assertSame(
            str_replace('🔍 ' . self::PALETTE_QUERY . self::CARET_GLYPH, '🔍 ' . self::PALETTE_QUERY . ' ', $driving),
            $handedOff,
            'the hand-off must cost the caret cell and no other byte',
        );
        self::assertNotSame($driving, $handedOff, 'the two frames must actually differ (guards the swap-above from a vacuous no-op)');
    }

    /**
     * The palette content overlay, painted by the builder itself with the E682
     * hand-off static armed exactly as App::view arms it for one frame. The
     * builder returns '' on a closed palette, so this is non-empty only while
     * the fixture is open.
     */
    private function paletteBoxWithHandoff(bool $shellHoldsKeys): string
    {
        [$chat] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        self::assertNotNull($chat->palette(), 'fixture: Ctrl+P must open the palette');
        foreach (str_split(self::PALETTE_QUERY) as $keystroke) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $keystroke));
        }

        Renderer::setPaletteAbandoned($shellHoldsKeys);
        $overlay = (new ReflectionMethod(Renderer::class, 'renderPalette'))->invoke(null, $chat, $chat->theme());

        self::assertIsString($overlay);
        self::assertNotSame('', $overlay, 'the fixture must reach the builder with an open palette');

        return $overlay;
    }
}
