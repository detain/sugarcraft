<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\PagerModel;

/**
 * scriptui-78: `printf '' | candy-scriptui pager` used to paint a 0-line
 * viewport — the last "nothing happened" surface in the tool. The empty
 * input now meets the user with a one-line sentence from the same
 * nothing-to-do family as the filter/table refusals.
 */
final class PagerEmptyStateTest extends TestCase
{
    public function testEmptyContentPaintsTheNothingToPageSentence(): void
    {
        $view = PagerModel::fromContent('')->view();

        self::assertStringContainsString(Lang::t('runtime.empty_input_pager'), $view);
    }

    public function testNonEmptyContentNeverShowsTheEmptyState(): void
    {
        $view = PagerModel::fromContent("hello\n")->view();

        self::assertStringNotContainsString(Lang::t('runtime.empty_input_pager'), $view);
        self::assertStringContainsString('hello', $view);
    }

    public function testContentThatSanitizesToNothingAlsoGetsTheSentence(): void
    {
        // A lone escape sequence is not content: the untrusted paint pass
        // strips it, and what remains is the empty state — not a blank frame.
        $view = PagerModel::fromContent("\x1b]0;title\x07")->view();

        self::assertStringContainsString(Lang::t('runtime.empty_input_pager'), $view);
    }
}
