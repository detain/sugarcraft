<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testKeyAndInitialValue(): void
    {
        $f = Input::new('name');
        $this->assertSame('name', $f->key());
        $this->assertSame('', $f->value());
        $this->assertFalse($f->isFocused());
        $this->assertFalse($f->skippable());
    }

    public function testFocusEnablesEditing(): void
    {
        [$f, ] = Input::new('name')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'h'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('hi', $f->value());
        $this->assertTrue($f->isFocused());
    }

    public function testTitleAndDescription(): void
    {
        $f = Input::new('name')->withTitle('Name')->withDescription('your full name');
        $this->assertStringContainsString('Name', $f->view());
        $this->assertStringContainsString('your full name', $f->view());
    }

    public function testValidatorRunsOnEveryUpdate(): void
    {
        $f = Input::new('email')->withValidator(
            static fn(string $v): ?string => str_contains($v, '@') ? null : 'must contain @',
        );
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('must contain @', $f->getError());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '@'));
        $this->assertNull($f->getError());
    }

    public function testCharLimitForwardedToInput(): void
    {
        [$f, ] = Input::new('x')->withCharLimit(2)->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame('ab', $f->value());
    }

    public function testBlurReleasesInput(): void
    {
        [$f, ] = Input::new('x')->focus();
        $f = $f->blur();
        $this->assertFalse($f->isFocused());
    }

    public function testWithSuggestionsExposesPool(): void
    {
        $f = Input::new('repo')->withSuggestions(['alpha', 'beta', 'gamma']);
        $this->assertSame(['alpha', 'beta', 'gamma'], $f->input->availableSuggestions());
        $this->assertTrue($f->input->showSuggestions);
    }

    public function testWithSuggestionsFuncReevaluatesOnUpdate(): void
    {
        $f = Input::new('repo')->withSuggestionsFunc(
            static fn (string $v): array => $v === ''
                ? []
                : ['{prefix}-' . $v, 'all-' . $v],
        );
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['{prefix}-x', 'all-x'], $f->input->availableSuggestions());
        $this->assertTrue($f->input->showSuggestions);
    }

    public function testWithPasswordMasksValue(): void
    {
        [$f, ] = Input::new('pw')->withPassword()->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('ab', $f->value());
        // Mask character does not change the underlying value but is
        // visible in the rendered output.
        $this->assertStringContainsString('**', $f->view());
        $this->assertStringNotContainsString('ab', $f->view());
    }

    public function testWithValidationShowsErrorWhenPredicateReturnsFalse(): void
    {
        $f = Input::new('name')->withValidation(
            static fn (string $v): bool => !empty($v),
            'Value is required',
        );
        [$f, ] = $f->focus();
        // Validation runs on update; empty value fails
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($f->getError());
        // Backspace to empty should fail
        [$f, ] = $f->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('Value is required', $f->getError());
    }

    public function testWithValidationShortAlias(): void
    {
        $f = Input::new('name')->validation(
            static fn (string $v): bool => strlen($v) >= 3,
            'Must be at least 3 characters',
        );
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('Must be at least 3 characters', $f->getError());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertNull($f->getError());
    }

    public function testWithValuePreFillsValue(): void
    {
        $f = Input::new('k')->withValue('hello');
        $this->assertSame('hello', $f->value());
    }

    public function testWithValueIsSubmittedWhenUntouched(): void
    {
        $form = Form::new(Input::new('k')->withValue('hello'));
        // No keystrokes: the pre-filled value is the submitted value.
        $this->assertSame('hello', $form->getString('k'));
    }

    public function testWithValueThenTypingEdits(): void
    {
        [$f, ] = Input::new('k')->withValue('hi')->focus();
        // Cursor sits at end of the pre-filled text; a keystroke appends.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '!'));
        $this->assertSame('hi!', $f->value());
    }

    public function testWithValueEmptyClearsToEmpty(): void
    {
        $f = Input::new('k')->withValue('seed')->withValue('');
        $this->assertSame('', $f->value());
    }

    /**
     * E741 keystone (mirrors E736 1.7 ConfirmTest:143 recipe shape): the
     * validator chain must keep re-firing across the error-set and
     * error-clear rebuilds — validate() returns fresh `new self(...)`
     * instances on both branches, and every such path now funnels through
     * carryNonCtorState(). (mutation: drop the carrier in Input.php → this
     * and the trait-closure pins below go red.)
     */
    public function testValidatorSurvivesErrorRecomputeRoundTrip(): void
    {
        $f = Input::new('email')->withValidator(
            static fn(string $v): ?string => str_contains($v, '@') ? null : 'must contain @',
        );
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('must contain @', $f->getError());

        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '@'));
        $this->assertNull($f->getError(), 'validating clears the error');

        [$f, ] = $f->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame(
            'must contain @',
            $f->getError(),
            'the validator must still exist after both error recomputes',
        );
    }

    /**
     * E741: withValidator() rebuilt via `new self(...)` and silently dropped
     * the HasHideFunc / HasDynamicLabels closures (the ctor never touches
     * them). The trait slots must survive the attach.
     */
    public function testWithValidatorPreservesTraitClosures(): void
    {
        $f = Input::new('k')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true)
            ->withValidator(static fn (string $v): ?string => null);

        $this->assertSame('DYN', $f->getTitle());
        $this->assertSame('DSCF', $f->getDescription());
        $this->assertTrue($f->isHidden([]));
    }

    /**
     * E741: focus/blur/keystroke/validate all rebuild through mutate() or
     * new self() — none of them may drop the dynamic-label or hide closures.
     * This was user-visible before the fix: a withTitleFunc'd field lost its
     * dynamic title on the very first focus.
     */
    public function testTraitClosuresSurviveFocusBlurAndUpdate(): void
    {
        $f = Input::new('k')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true)
            ->withValidator(static fn (string $v): ?string => $v === '' ? 'req' : null);

        [$f, ] = $f->focus();
        $f = $f->blur();
        [$f, ] = $f->withValue('a')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Backspace));
        [$f, ] = $f->update(new KeyMsg(KeyType::Backspace)); // empties → error path

        $this->assertSame('DYN', $f->getTitle());
        $this->assertSame('DSCF', $f->getDescription());
        $this->assertTrue($f->isHidden([]));
        $this->assertSame('req', $f->getError(), 'validator rode the same rebuilds');
    }
}
