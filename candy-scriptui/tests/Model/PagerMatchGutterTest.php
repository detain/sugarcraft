<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\ScriptUi\Model\PagerModel;

/**
 * scriptui-72: --match must highlight the BODY only. The old ordering
 * (highlight after gutter prefix) reverse-videoed the gutter's own line
 * digits and the '│' separator, so `--match 1` painted styling onto the
 * row-number "1" of every line — chrome answering to a needle the user
 * never typed into the content.
 */
final class PagerMatchGutterTest extends TestCase
{
    public function testMatchNeedleDoesNotHighlightGutterDigits(): void
    {
        // Ten lines so every gutter digit is exactly "1" — the needle.
        $content = implode("\n", array_fill(0, 10, 'hello'));
        $model   = PagerModel::fromContent($content, match: '1', showLineNumbers: true);

        $highlight = Ansi::sgr(Ansi::REVERSE) . '1' . Ansi::reset();
        self::assertStringNotContainsString(
            $highlight,
            $model->view(),
            'the gutter digit "1" must never receive match styling',
        );

        // The only legitimate match is in the word "digit-1" below.
        $model2 = PagerModel::fromContent("line one\ndigit-1 here", match: '1', showLineNumbers: true);
        self::assertStringContainsString($highlight, $model2->view());
        // Exactly ONE highlighted "1": the body one, not the gutter "1".
        self::assertSame(
            1,
            substr_count($model2->view(), $highlight),
            'one body match, zero gutter matches',
        );
    }

    public function testBodyHighlightStillLandsAfterTheGutterPrefix(): void
    {
        $model = PagerModel::fromContent("apple\npear", match: 'ea', showLineNumbers: true);
        $view  = $model->view();
        $line  = explode("\n", $view)[1];
        self::assertMatchesRegularExpression('/^\s*2 │ /', $line, 'gutter prefix stays plain at the head');
        self::assertStringContainsString(Ansi::sgr(Ansi::REVERSE) . 'ea' . Ansi::reset(), $line);
    }
}
