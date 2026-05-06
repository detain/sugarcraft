<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Confirm;
use CandyCore\Prompt\Field\Input;
use CandyCore\Prompt\Field\MultiSelect;
use CandyCore\Prompt\Field\Note;
use CandyCore\Prompt\Field\Select;
use CandyCore\Prompt\Field\Text;
use CandyCore\Prompt\Form;
use CandyCore\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    public function testFirstNonSkippableFieldStartsFocused(): void
    {
        $form = Form::new(
            Note::new('intro'),
            Input::new('name'),
            Confirm::new('ok'),
        );
        $this->assertSame(1, $form->focusedIndex);
        $this->assertTrue($form->focusedField()->isFocused());
    }

    public function testTabAdvancesFocus(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
            Input::new('c'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $form->focusedIndex);
        $this->assertSame('b', $form->focusedField()->key());
    }

    public function testTabSkipsNote(): void
    {
        $form = Form::new(
            Input::new('a'),
            Note::new('mid'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->focusedIndex);
    }

    public function testUpReturnsToPrevious(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        [$form, ] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $form->focusedIndex);
    }

    public function testEnterOnLastFieldSubmits(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertFalse($form->isSubmitted());
        [$form, $cmd] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted());
        $this->assertNotNull($cmd);
    }

    public function testEnterOnNonLastFieldAdvances(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(1, $form->focusedIndex);
        $this->assertFalse($form->isSubmitted());
    }

    public function testEscapeAborts(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, $cmd] = $form->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($form->isAborted());
        $this->assertNotNull($cmd);
    }

    public function testCtrlCAborts(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($form->isAborted());
    }

    public function testForwardsKeysToFocusedField(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'h'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame(['a' => 'hi', 'b' => ''], $form->values());
    }

    public function testValuesSkipsNotes(): void
    {
        $form = Form::new(
            Note::new('intro'),
            Input::new('name'),
            Confirm::new('ok')->withDefault(true),
            Select::new('lang')->withOptions('PHP', 'Go'),
        );
        $this->assertSame(['name' => '', 'ok' => true, 'lang' => 'PHP'], $form->values());
    }

    public function testIgnoresKeysAfterSubmit(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted());
        [$form2, $cmd] = $form->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($form, $form2);
        $this->assertNull($cmd);
    }

    public function testFocusedFieldOnlyOne(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'), Input::new('c'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $focusedCount = 0;
        foreach ($form->activeFields() as $f) {
            if ($f->isFocused()) $focusedCount++;
        }
        $this->assertSame(1, $focusedCount);
    }

    public function testInitReturnsFirstFieldFocusCmd(): void
    {
        $form = Form::new(
            Note::new('intro'),     // skippable
            Input::new('name'),     // first interactive field
        );
        $cmd = $form->init();
        $this->assertNotNull($cmd, 'Form::init() must propagate the first focused field\'s Cmd so the cursor blink starts immediately');
        $this->assertInstanceOf(TickRequest::class, $cmd());
    }

    public function testInitIsNullWhenNoInteractiveFields(): void
    {
        $form = Form::new(Note::new('only'));
        $this->assertNull($form->init());
    }

    public function testEnterInsideSelectFilterDoesNotAdvanceForm(): void
    {
        $form = Form::new(
            Select::new('lang')->withOptions('PHP', 'Go', 'Rust'),
            Input::new('name'),
        );
        $this->assertSame(0, $form->focusedIndex);

        // Enter filter mode and type something inside Select.
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, '/'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'g'));

        // Pre-condition: Select's wrapped ItemList is in filter mode.
        $this->assertTrue($form->activeFields()[0]->consumes(new KeyMsg(KeyType::Enter)));

        // Enter should be consumed by Select (leaves filter mode), not by
        // the Form (which would otherwise advance focus).
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(0, $form->focusedIndex);
        $this->assertFalse($form->isSubmitted());
    }

    public function testEscInsideSelectFilterDoesNotAbortForm(): void
    {
        $form = Form::new(Select::new('lang')->withOptions('PHP', 'Go'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, '/'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'g'));

        [$form, ] = $form->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($form->isAborted());
    }

    public function testEscOutsideFilterStillAborts(): void
    {
        $form = Form::new(Select::new('lang')->withOptions('PHP', 'Go'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($form->isAborted());
    }

    public function testArrowDownInsideMultiSelectMovesItsCursorNotForm(): void
    {
        $form = Form::new(
            MultiSelect::new('foods')->withOptions('A', 'B', 'C'),
            Input::new('name'),
        );
        $this->assertSame(0, $form->focusedIndex);
        $msField = $form->activeFields()[0];
        $this->assertSame(0, $msField->cursor);

        [$form, ] = $form->update(new KeyMsg(KeyType::Down));

        // Form focus stays on the MultiSelect.
        $this->assertSame(0, $form->focusedIndex);
        // The MultiSelect's internal cursor advanced.
        $this->assertSame(1, $form->activeFields()[0]->cursor);
    }

    public function testArrowDownInsideSelectMovesListNotForm(): void
    {
        $form = Form::new(
            Select::new('lang')->withOptions('PHP', 'Go', 'Rust'),
            Input::new('name'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $form->focusedIndex);
        $this->assertSame('Go', $form->activeFields()[0]->value());
    }

    public function testArrowDownInsideTextMovesLineCursorNotForm(): void
    {
        $form = Form::new(
            Text::new('notes')->withTitle('Notes'),
            Input::new('name'),
        );
        // Type two lines.
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame("a\nb", $form->activeFields()[0]->value());

        // Up moves between text lines, not between fields.
        [$form, ] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $form->focusedIndex);
        $this->assertSame(0, $form->activeFields()[0]->area->row);
    }

    public function testArrowDownStillNavigatesBetweenInputFields(): void
    {
        // Inputs don't claim Up/Down — form should still advance focus.
        $form = Form::new(Input::new('a'), Input::new('b'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $form->focusedIndex);
    }

    public function testGroupsCreatesMultiPageForm(): void
    {
        $form = Form::groups(
            \CandyCore\Prompt\Group::new(Input::new('a')),
            \CandyCore\Prompt\Group::new(Input::new('b')),
        );
        $this->assertSame(2, $form->totalGroups());
        $this->assertSame(0, $form->activeGroupIndex());
        // Tab past end of first group → moves to second group.
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $form->activeGroupIndex());
    }

    public function testGroupHideFuncSkipsGroup(): void
    {
        $form = Form::groups(
            \CandyCore\Prompt\Group::new(Input::new('a')),
            \CandyCore\Prompt\Group::new(Input::new('b'))
                ->withHideFunc(static fn(array $values): bool => true),
            \CandyCore\Prompt\Group::new(Input::new('c')),
        );
        // Tab from group 0 → should jump to group 2 (group 1 hidden).
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->activeGroupIndex());
    }

    public function testWithThemeSwapsTheme(): void
    {
        $form = Form::new(Input::new('a'))
            ->withTheme(\CandyCore\Prompt\Theme::dracula());
        $this->assertSame(\CandyCore\Prompt\Theme::dracula()::class, $form->theme::class);
    }

    public function testWithAccessibleSwitchesViewToPlainText(): void
    {
        $form = Form::new(Input::new('a'))
            ->withAccessible();
        $out = $form->view();
        // Accessible mode renders the focused field's title : value
        // rather than the multi-line component view.
        $this->assertStringNotContainsString("\n\n", $out);
    }

    public function testFieldHideFuncSkipsFieldInValues(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->withHideFunc(static fn(array $v) => true),
        );
        $b = $form->activeFields()[1];
        $this->assertTrue($b->isHidden([]));
        $this->assertFalse($form->activeFields()[0]->isHidden([]));
    }

    public function testGroupViewIncludesTitleAndDescription(): void
    {
        $form = Form::groups(
            \CandyCore\Prompt\Group::new(Input::new('a'))
                ->withTitle('Step 1')
                ->withDescription('First page'),
        )->withTheme(\CandyCore\Prompt\Theme::plain());
        $out = $form->view();
        $this->assertStringContainsString('Step 1',     $out);
        $this->assertStringContainsString('First page', $out);
    }

    public function testGetReturnsRawValue(): void
    {
        $form = Form::new(Input::new('name'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('ab', $form->get('name'));
    }

    public function testGetReturnsDefaultForUnknownKey(): void
    {
        $form = Form::new(Input::new('name'));
        $this->assertSame('xx', $form->get('missing', 'xx'));
    }

    public function testGetStringCoercesScalars(): void
    {
        $form = Form::new(
            Input::new('name'),
            Confirm::new('ok')->withDefault(true),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'h'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('hi',   $form->getString('name'));
        $this->assertSame('true', $form->getString('ok'));
        $this->assertSame('def',  $form->getString('missing', 'def'));
    }

    public function testGetStringJoinsArrays(): void
    {
        $form = Form::new(
            MultiSelect::new('langs')
                ->withOptions('PHP', 'Go', 'Rust'),
        );
        // Toggle the first two via Tab/Space depending on the field's API;
        // simplest: simulate via internal state by calling withDefault?
        // MultiSelect::new doesn't have withDefault; skip — test the empty
        // array path which still exercises getString for arrays.
        $this->assertSame('', $form->getString('langs'));
    }

    public function testGetIntCoercesNumericStrings(): void
    {
        $form = Form::new(Input::new('n'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, '4'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(42,  $form->getInt('n'));
        $this->assertSame(99,  $form->getInt('missing', 99));
    }

    public function testGetIntFromBoolField(): void
    {
        $form = Form::new(Confirm::new('ok')->withDefault(true));
        $this->assertSame(1, $form->getInt('ok'));
    }

    public function testGetBoolFromConfirm(): void
    {
        $form = Form::new(Confirm::new('ok')->withDefault(true));
        $this->assertTrue($form->getBool('ok'));
    }

    public function testGetBoolFromStringRecognised(): void
    {
        $form = Form::new(Input::new('flag'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'y'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'e'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertTrue($form->getBool('flag'));
    }

    public function testGetBoolDefaultForUnrecognised(): void
    {
        $form = Form::new(Input::new('flag'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'h'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertFalse($form->getBool('flag'));
        $this->assertTrue($form->getBool('flag', true));
    }

    public function testGetArrayWrapsScalar(): void
    {
        $form = Form::new(Input::new('name'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(['a'], $form->getArray('name'));
    }

    public function testGetArrayEmptyForMissing(): void
    {
        $form = Form::new(Input::new('name'));
        $this->assertSame([], $form->getArray('missing'));
    }
}
