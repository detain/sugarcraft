<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
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
}
