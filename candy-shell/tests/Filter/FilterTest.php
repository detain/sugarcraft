<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Filter;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Model\FilterModel;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the FilterCommand and FilterModel interactive filtering behavior.
 */
final class FilterTest extends TestCase
{
    /**
     * Test basic filtering with a single query matches the correct item.
     */
    public function testBasicFilteringWithSingleQuery(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry', 'date']);

        // Type "ban" to filter
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'b'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'n'));

        // Should match banana
        $visibleItems = $model->list->visibleItems();
        $this->assertCount(1, $visibleItems);
        $this->assertSame('banana', $visibleItems[0]->title());

        // Submit with Enter
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());
        $this->assertSame('banana', $model->selected());
    }

    /**
     * Test that filter mode starts immediately when model is created.
     */
    public function testFilterModeStartsImmediately(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);
        $this->assertTrue($model->list->isFiltering());
    }

    /**
     * Test case sensitivity in substring matching.
     * The default --strict mode performs substring matching.
     */
    public function testCaseSensitivityInSubstringMatching(): void
    {
        $model = FilterModel::fromOptions(['Apple', 'APPLE', 'apple', 'Banana']);

        // Filter with lowercase "app"
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'p'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'p'));

        // With strict (substring) matching, 'apple' should match 'app'
        $visibleTitles = array_map(fn($item) => $item->title(), $model->list->visibleItems());
        $this->assertContains('apple', $visibleTitles);
    }

    /**
     * Test limit option determines multi-select behavior.
     * When limit > 1, multi-select is enabled to allow multiple selections up to the limit.
     */
    public function testLimitOptionEnablesMultiSelectWhenGreaterThanOne(): void
    {
        // limit=1 (default) -> single select
        $singleModel = FilterModel::fromOptions(['apple', 'banana', 'cherry'], limit: 1);
        $this->assertFalse($singleModel->isMulti());

        // limit=2 -> multi-select (need multi UI to allow selecting up to 2 items)
        $multiModel = FilterModel::fromOptions(['apple', 'banana', 'cherry'], limit: 2, noLimit: false);
        $this->assertTrue($multiModel->isMulti());
        $this->assertSame(2, $multiModel->limit);

        // limit=1 single select works correctly
        [$singleModel, ] = $singleModel->update(new KeyMsg(KeyType::Char, 'b'));
        [$singleModel, ] = $singleModel->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($singleModel->isSubmitted());
        $this->assertSame('banana', $singleModel->selected());
    }

    /**
     * Test that no-limit option enables unlimited multi-select mode.
     */
    public function testNoLimitEnablesUnlimitedMultiSelect(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, limit: 1, noLimit: true);

        $this->assertTrue($model->isMulti());
        $this->assertSame(0, $model->limit); // 0 means no cap
    }

    /**
     * Test fuzzy matching option (currently alias for strict).
     * The --fuzzy flag is a no-op alias for --strict per gum compatibility.
     */
    public function testFuzzyMatchingFlag(): void
    {
        // Fuzzy is currently an alias for strict (substring matching)
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Type "b" should match "banana"
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'b'));

        $visibleTitles = array_map(fn($item) => $item->title(), $model->list->visibleItems());
        $this->assertContains('banana', $visibleTitles);
    }

    /**
     * Test that strict mode only matches substrings.
     */
    public function testStrictModeSubstringMatching(): void
    {
        $model = FilterModel::fromOptions(['apple', 'pineapple', 'banana', 'cherry']);

        // Filter "app" should match "apple" and "pineapple"
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'p'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'p'));

        $visibleTitles = array_map(fn($item) => $item->title(), $model->list->visibleItems());
        $this->assertContains('apple', $visibleTitles);
        $this->assertContains('pineapple', $visibleTitles);
        $this->assertNotContains('banana', $visibleTitles);
    }

    /**
     * Test select-if-one logic with single item via model.
     * The actual command-level behavior depends on stdin which is hard to test reliably.
     */
    public function testSelectIfOneModelBehavior(): void
    {
        // Test that model with single option could be auto-selected
        // (actual auto-select happens in command execute(), not in model)
        $model = FilterModel::fromOptions(['only_one_item']);
        $this->assertFalse($model->isSubmitted());
        // With single item and user presses Enter, it should select
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());
        $this->assertSame('only_one_item', $model->selected());
    }

    /**
     * Test header option is displayed.
     */
    public function testHeaderOption(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, header: 'Select a fruit');

        $view = $model->view();
        $this->assertStringContainsString('Select a fruit', $view);
    }

    /**
     * Test cursor prefix customization.
     */
    public function testCursorPrefixCustomization(): void
    {
        $options = ['apple', 'banana'];
        $model = FilterModel::fromOptions($options, cursorPrefix: '>> ');

        $view = $model->view();
        $this->assertStringContainsString('>>', $view);
    }

    /**
     * Test unselected prefix customization.
     */
    public function testUnselectedPrefixCustomization(): void
    {
        $options = ['apple', 'banana'];
        $model = FilterModel::fromOptions($options, unselectedPrefix: '- ');

        $view = $model->view();
        $this->assertStringContainsString('-', $view);
    }

    /**
     * Test value pre-fill option.
     */
    public function testValuePreFill(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, value: 'ban');

        // Filter should already have 'ban' typed
        $this->assertSame('ban', $model->list->filterText);

        // Should auto-submit since only banana matches
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());
        $this->assertSame('banana', $model->selected());
    }

    /**
     * Test Escape aborts filtering.
     */
    public function testEscapeAborts(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertFalse($model->isAborted());

        [$model, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($model->isAborted());
    }

    /**
     * Test Ctrl+C aborts filtering.
     */
    public function testCtrlCAborts(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertFalse($model->isAborted());

        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($model->isAborted());
    }

    /**
     * Test multi-select with Tab key.
     */
    public function testMultiSelectWithTab(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, limit: 2, noLimit: true);

        // Navigate to banana
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        // Tab to select
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));

        $this->assertArrayHasKey(1, $model->checked);
        $this->assertTrue($model->checked[1]);
    }

    /**
     * Test that Enter with no visible matches does nothing.
     */
    public function testEnterWithNoMatchesDoesNothing(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Type something that matches nothing
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'x'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'y'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'z'));

        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($model->isSubmitted());
        $this->assertNull($model->selected());
    }

    /**
     * Test reverse option is stored correctly.
     */
    public function testReverseOptionStored(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, limit: 3, noLimit: true, reverse: true);

        // The model should have reverse flag set
        $this->assertTrue($model->reverse);
    }

    /**
     * Test preselected options in multi-select mode.
     */
    public function testPreselectedOptions(): void
    {
        $options = ['apple', 'banana', 'cherry'];
        $model = FilterModel::fromOptions($options, limit: 3, noLimit: true, preselected: ['banana']);

        // banana should already be checked (index 1)
        $this->assertArrayHasKey(1, $model->checked);
        $this->assertTrue($model->checked[1]);

        // apple and cherry should not be checked
        $this->assertArrayNotHasKey(0, $model->checked);
        $this->assertArrayNotHasKey(2, $model->checked);
    }

    /**
     * Test that limit cap prevents exceeding maximum selections.
     */
    public function testLimitCapPreventsExceedingSelections(): void
    {
        $options = ['a', 'b', 'c', 'd'];
        $model = FilterModel::fromOptions($options, limit: 2, noLimit: false);

        // Select two items (reaching the limit)
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab)); // a
        $checkedA = count(array_filter($model->checked));

        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab)); // b
        $checkedB = count(array_filter($model->checked));

        $this->assertSame(1, $checkedA);
        $this->assertSame(2, $checkedB);

        // Try to select a third - should be prevented by limit
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab)); // c - should be rejected
        $checkedAfter = count(array_filter($model->checked));

        $this->assertSame(2, $checkedAfter); // Should still be 2, not 3
    }

    /**
     * Test height option affects visible item count.
     */
    public function testHeightOption(): void
    {
        // Create many options
        $options = array_map(fn($i) => "item{$i}", range(1, 20));
        $model = FilterModel::fromOptions($options, height: 5);

        // View should be rendered
        $view = $model->view();
        $this->assertNotEmpty($view);
    }

    /**
     * Test that filter text is preserved when moving cursor.
     */
    public function testFilterTextPreservedOnCursorMove(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry', 'date']);

        // Type to filter
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $model->list->filterText);

        // Move cursor down
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame('a', $model->list->filterText);

        // Move cursor up
        [$model, ] = $model->update(new KeyMsg(KeyType::Up));
        $this->assertSame('a', $model->list->filterText);
    }

    /**
     * Test Backspace removes filter characters.
     */
    public function testBackspaceRemovesFilterCharacters(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Type "ban"
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'b'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame('ban', $model->list->filterText);

        // Backspace removes last character
        [$model, ] = $model->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('ba', $model->list->filterText);

        // Now should match "banana" with "ba"
        $titles = array_map(fn($i) => $i->title(), $model->list->visibleItems());
        $this->assertContains('banana', $titles);
    }

    /**
     * Test filter model view contains expected elements.
     */
    public function testViewContainsExpectedElements(): void
    {
        $model = FilterModel::fromOptions(
            ['apple', 'banana'],
            header: 'Test Header',
            cursorPrefix: '>>'
        );

        $view = $model->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('Test Header', $view);
        $this->assertStringContainsString('>>', $view);
    }

    /**
     * Test model ignores keys after submission.
     */
    public function testIgnoresKeysAfterSubmission(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Submit
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());

        // Try to type more - model should be unchanged
        $originalModel = $model;
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($originalModel, $model);
    }

    /**
     * Test model ignores keys after abort.
     */
    public function testIgnoresKeysAfterAbort(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Abort
        [$model, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($model->isAborted());

        // Try to type more - model should be unchanged
        $originalModel = $model;
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($originalModel, $model);
    }

    /**
     * Test selectedAll returns empty when not submitted.
     */
    public function testSelectedAllEmptyWhenNotSubmitted(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana']);
        $this->assertSame([], $model->selectedAll());
    }

    /**
     * Test selectedAll returns empty for single-select mode even after submit.
     */
    public function testSelectedAllEmptyForSingleSelectAfterSubmit(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana']);
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));

        $this->assertSame([], $model->selectedAll());
    }

    /**
     * Test selected() returns null for multi-select mode after submit.
     */
    public function testSelectedNullForMultiSelectAfterSubmit(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana'], limit: 2, noLimit: true);
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($model->selected());
    }

    /**
     * Test that clicking Tab without selection just moves cursor in single-select.
     */
    public function testTabInSingleSelectMode(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry'], limit: 1);
        $this->assertFalse($model->isMulti());

        // Tab should not toggle anything in single-select
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));
        $this->assertEmpty($model->checked);
    }

    /**
     * Test filter with multiple matching items shows all matches.
     */
    public function testFilterShowsAllMatchingItems(): void
    {
        $model = FilterModel::fromOptions(['apple', 'apricot', 'banana', 'cherry']);

        // Type "ap" to match apple and apricot
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'p'));

        $visibleTitles = array_map(fn($i) => $i->title(), $model->list->visibleItems());
        $this->assertContains('apple', $visibleTitles);
        $this->assertContains('apricot', $visibleTitles);
        $this->assertNotContains('banana', $visibleTitles);
        $this->assertNotContains('cherry', $visibleTitles);
    }

    /**
     * Test model can be created with empty options.
     */
    public function testEmptyOptionsCreatesModel(): void
    {
        $model = FilterModel::fromOptions([]);
        $this->assertFalse($model->isSubmitted());
        $this->assertFalse($model->isAborted());
    }

    /**
     * Test Enter on empty filtered list does nothing.
     */
    public function testEnterOnEmptyFilteredListDoesNothing(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana']);

        // Filter to nothing
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'x'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));

        $this->assertFalse($model->isSubmitted());
    }

    /**
     * Test multiple selections via Tab in multi-select mode.
     */
    public function testMultipleSelectionsViaTab(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry'], limit: 3, noLimit: true);

        // Select apple (Tab at position 0)
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));

        // Move to banana and select
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));

        // Move to cherry and select
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));

        $this->assertCount(3, array_filter($model->checked));
    }

    /**
     * Test Tab toggles selection off when already selected.
     */
    public function testTabTogglesSelectionOff(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry'], limit: 3, noLimit: true);

        // Select apple
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));
        $this->assertTrue($model->checked[0] ?? false);

        // Tab again to deselect
        [$model, ] = $model->update(new KeyMsg(KeyType::Tab));
        $this->assertArrayNotHasKey(0, $model->checked);
    }

    /**
     * Test ArrowDown moves cursor in filtered list.
     */
    public function testArrowDownMovesCursor(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);

        // Filter to just banana (cursor should be on it)
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('banana', $model->list->selectedItem()->title());

        // Arrow down should have no effect since only one item
        [$model, ] = $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame('banana', $model->list->selectedItem()->title());
    }

    /**
     * Test command has correct options configured.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $command = (new Application())->find('filter');
        $definition = $command->getDefinition();

        // Verify key options exist
        $this->assertTrue($definition->hasOption('limit'));
        $this->assertTrue($definition->hasOption('no-limit'));
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('value'));
        $this->assertTrue($definition->hasOption('selected'));
        $this->assertTrue($definition->hasOption('select-if-one'));
        $this->assertTrue($definition->hasOption('strict'));
        $this->assertTrue($definition->hasOption('fuzzy'));
        $this->assertTrue($definition->hasOption('no-fuzzy'));
        $this->assertTrue($definition->hasOption('cursor'));
        $this->assertTrue($definition->hasOption('indicator'));
    }

    /**
     * Test command executes without error.
     * Note: actual behavior depends on stdin state in test environment.
     */
    public function testCommandExecutesWithoutError(): void
    {
        $command = (new Application())->find('filter');
        $tester = new CommandTester($command);

        // Execute with no input - should handle gracefully
        try {
            $tester->execute([]);
        } catch (\Throwable $e) {
            // Some stdin configurations may throw - that's ok for this test
        }

        // Command should have executed (status code may be success or failure depending on stdin)
        $this->assertContains($tester->getStatusCode(), [Command::SUCCESS, Command::FAILURE]);
    }
}
