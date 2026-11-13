<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\FilterModel;
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

    public function testEscapedCommaSurvivesAsLiteralData(): void
    {
        // scriptui-76: a title that contains a comma is reachable via `\,`
        self::assertSame(['a,b'], OptionList::expand(['a\,b']));
    }

    public function testEscapeSplitMixesBothWorlds(): void
    {
        self::assertSame(['a,b', 'c'], OptionList::expand(['a\,b,c']));
    }

    public function testOnlyTheTwoDocumentedEscapesUnescape(): void
    {
        self::assertSame(['a\b'], OptionList::expand(['a\\\\b']));  // \\ -> \
        self::assertSame(['a\nb'], OptionList::expand(['a\\nb']));   // \n stays data
        self::assertSame(['a\\'], OptionList::expand(['a\\']));      // trailing \ verbatim
    }

    public function testCommaTitleIsPreselectableThroughFilterModel(): void
    {
        // The end-to-end promise of -76(a): `filter --selected 'a\,b'`
        // marks the row literally titled "a,b".
        $model = FilterModel::fromOptions(
            options: ['a,b', 'c'],
            limit: 0,
            noLimit: true,
            preselected: OptionList::expand(['a\\,b']),
        );
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];

        self::assertSame(['a,b'], $submitted->selectedAll());
    }

    public function testBothListConsumersCallTheSharedSplitter(): void
    {
        // scriptui-53 KEEP (wiring-pin): the splitter's behaviour is unit-
        // tested exhaustively and the model's end-to-end preselect is proven
        // in testCommaTitleIsPreselectableThroughFilterModel, but whether the
        // CHOOSE/FILTER commands route --selected through it is only visible
        // inside a live interactive Program (both refuse headless) — so the
        // call-site itself is the contract.
        $choose = (string) \file_get_contents(__DIR__ . '/../../src/Command/ChooseCommand.php');
        $filter = (string) \file_get_contents(__DIR__ . '/../../src/Command/FilterCommand.php');
        self::assertStringContainsString('OptionList::expand((array) $input->getOption(\'selected\'))', $choose);
        self::assertStringContainsString('OptionList::expand((array) $input->getOption(\'selected\'))', $filter);
    }
}
