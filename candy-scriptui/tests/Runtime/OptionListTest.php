<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\OptionList;

/**
 * --selected="a,b" taught a comma list in the README while both
 * consumers handed the single raw string to the model — a silent
 * no-op (M8). OptionList::expand is now the sole splitter the
 * commands call; these pins own that behaviour.
 */
final class OptionListTest extends TestCase
{
    public function testCommaListsSplitIntoSeparateItems(): void
    {
        self::assertSame(['a', 'b', 'c'], OptionList::expand(['a,b', 'c']));
    }

    public function testEmptyRawYieldsEmptyList(): void
    {
        self::assertSame([], OptionList::expand([]));
    }

    public function testItemsKeepTheirExactBytes(): void
    {
        // No trimming: a name with a leading space stays addressable
        // via a repeated flag, exactly as written.
        self::assertSame(['a ', ' b'], OptionList::expand(['a , b']));
    }

    public function testSingleNamePassesThroughUntouched(): void
    {
        self::assertSame(['only'], OptionList::expand(['only']));
    }

    public function testBothListConsumersCallTheSharedSplitter(): void
    {
        $choose = (string) \file_get_contents(__DIR__ . '/../../src/Command/ChooseCommand.php');
        $filter = (string) \file_get_contents(__DIR__ . '/../../src/Command/FilterCommand.php');
        self::assertStringContainsString('OptionList::expand((array) $input->getOption(\'selected\'))', $choose);
        self::assertStringContainsString('OptionList::expand((array) $input->getOption(\'selected\'))', $filter);
    }
}
