<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Palette;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Code\Renderer;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * S-U01: the palette cursor must never leave the painted window, so Enter
 * always names a row the user can see.
 */
final class PaletteScrollAnchorTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function viewportRows(): iterable
    {
        yield 'rows 12' => [12];
        yield 'rows 24' => [24];
        yield 'rows 30' => [30];
    }

    #[DataProvider('viewportRows')]
    public function testTheSelectedRowIsAlwaysPainted(int $rows): void
    {
        $chat = (new Chat(palette: PaletteState::root()))->withSize(100, $rows);
        $count = \count($chat->paletteMatches());
        $this->assertGreaterThan($rows, $count, 'fixture must overflow the viewport');

        for ($i = 0; $i < $count + 2; $i++) {
            $out = (string) preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($chat));
            $this->assertStringContainsString('▸ ', $out, "no cursor painted at step $i, rows=$rows");
            $selected = $chat->paletteMatches()[$chat->palette()->selectedIndex];
            $this->assertMatchesRegularExpression(
                '/▸ ' . preg_quote(mb_substr($selected, 0, 14), '/') . '/u',
                $out,
                "selected row '$selected' not on canvas at step $i, rows=$rows",
            );
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
    }

    public function testTheBoxFitsTheViewport(): void
    {
        $chat = (new Chat(palette: PaletteState::root()))->withSize(100, 24);
        $lines = explode("\n", rtrim(Renderer::render($chat), "\n"));
        $this->assertLessThanOrEqual(24, \count($lines));
    }
}
