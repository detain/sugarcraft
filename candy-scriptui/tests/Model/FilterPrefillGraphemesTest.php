<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\ScriptUi\Model\FilterModel;

/**
 * scriptui-19 — the --value prefill replays graphemes, not bytes, so a
 * non-ASCII prefill matches and paints intact.
 */
final class FilterPrefillGraphemesTest extends TestCase
{
    public function testAccentedPrefillFindsItsRows(): void
    {
        $model = FilterModel::fromOptions(
            ['café au lait', 'green tea', 'chai'],
            value: 'café',
        );
        $view = $model->view();

        $this->assertTrue(mb_check_encoding($view, 'UTF-8'));
        $this->assertStringContainsString('café au lait', $view);
        $this->assertStringNotContainsString('green tea', $view);
        $this->assertStringNotContainsString('No matches', $view);
    }

    public function testCjkAndEmojiPrefillsRoundTrip(): void
    {
        $cjk = FilterModel::fromOptions(['茶 bowl', 'rice'], value: '茶');
        $this->assertStringContainsString('茶 bowl', $cjk->view());
        $this->assertStringNotContainsString('rice', $cjk->view());

        $emoji = FilterModel::fromOptions(['🍵 latte', 'green tea'], value: '🍵', fuzzy: true);
        $view = $emoji->view();
        $this->assertTrue(mb_check_encoding($view, 'UTF-8'));
        $this->assertStringContainsString('latte', $view);
        $this->assertStringNotContainsString('No matches', $view);
    }

    public function testBackspaceAfterPrefillDropsOneCodepoint(): void
    {
        $model = FilterModel::fromOptions(['café au lait', 'chai'], value: 'café');
        [$model, ] = $model->update(new KeyMsg(KeyType::Backspace));
        $view = $model->view();

        // Buffer is 'caf' — the status line quotes the filter text.
        $this->assertStringContainsString('"caf"', $view);
        $this->assertStringNotContainsString('"café"', $view);
    }

    public function testPrefillIsEquivalentToTypingTheValue(): void
    {
        $options = ['café au lait', 'green tea', 'chai tea'];
        $prefilled = FilterModel::fromOptions($options, value: 'café');

        $typed = FilterModel::fromOptions($options);
        foreach (['c', 'a', 'f', 'é'] as $ch) {
            [$typed, ] = $typed->update(new KeyMsg(KeyType::Char, $ch));
        }

        $this->assertSame($typed->view(), $prefilled->view());
    }
}
