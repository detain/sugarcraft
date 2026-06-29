<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Forms\Field\Input;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Input field validation support.
 *
 * Covers:
 * - withValidator(\Closure $fn) — closure returns null for valid, error string for invalid
 * - withValidation(callable $predicate, string $errorMessage) — predicate + error message
 * - validate() — internal method that runs validation on update
 * - Error display in view() and getError()
 *
 * Note: The Input field must be focused before it can accept keyboard input.
 * Validation runs on every update() call after the input is processed.
 */
final class InputValidationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helper — focus an Input and return the focused field
    // -------------------------------------------------------------------------

    private function focusInput(Input $field): Input
    {
        [$focused] = $field->focus();
        return $focused;
    }

    // -------------------------------------------------------------------------
    // withValidator — closure returns null (valid) or string (invalid)
    // -------------------------------------------------------------------------

    public function testWithValidatorNoErrorWhenClosureReturnsNull(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->withValidator(static fn (string $v): ?string => null);

        // Trigger validation via update()
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertNull($field->getError());
    }

    public function testWithValidatorSetsErrorWhenClosureReturnsString(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validator(static fn (string $v): ?string => 'Invalid email address');

        // Trigger validation via update()
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Invalid email address', $field->getError());
    }

    public function testWithValidatorReceivesCurrentValue(): void
    {
        $received = null;
        $field = $this->focusInput(Input::new('name'))
            ->validator(static function (string $value) use (&$received): ?string {
                $received = $value;
                return null;
            });

        // Trigger validation via update
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'J'));

        $this->assertNull($field->getError());
        $this->assertSame('J', $received);
    }

    public function testWithValidatorErrorDisplayedInView(): void
    {
        // Validator that always returns an error (regardless of value)
        $field = $this->focusInput(Input::new('qty'))
            ->validator(static fn (string $v): ?string => 'Value is invalid');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $view = $field->view();
        $this->assertStringContainsString('! Value is invalid', $view);
    }

    public function testWithValidatorNoErrorWhenValueMakesValidatorPass(): void
    {
        // Validator that passes when value is non-empty
        $field = $this->focusInput(Input::new('qty'))
            ->validator(static fn (string $v): ?string => $v !== '' ? null : 'Required');

        // Type a character to make value non-empty, triggering validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, '5'));

        $view = $field->view();
        $this->assertStringNotContainsString('!', $view);
    }

    public function testWithValidatorChainsMultipleValidators(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => 'Error 1')
            ->validator(static fn (string $v): ?string => 'Error 2');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        // Chaining runs validators in sequence; first error wins.
        // Error 1 is returned because the first validator in the chain fails.
        $this->assertSame('Error 1', $field->getError());
    }

    // -------------------------------------------------------------------------
    // withValidation — predicate returns bool, errorMessage shown on false
    // -------------------------------------------------------------------------

    public function testWithValidationNoErrorWhenPredicateReturnsTrue(): void
    {
        $field = $this->focusInput(Input::new('age'))
            ->validation(static fn (string $v): bool => $v !== '', 'Age is required');

        // Type something to make predicate return true
        [$field] = $field->update(new KeyMsg(KeyType::Char, '2'));

        $this->assertNull($field->getError());
    }

    public function testWithValidationSetsErrorWhenPredicateReturnsFalse(): void
    {
        $field = $this->focusInput(Input::new('age'))
            ->validation(static fn (string $v): bool => false, 'Age is required');

        // Trigger validation (predicate always returns false)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Age is required', $field->getError());
    }

    public function testWithValidationErrorDisplayedInView(): void
    {
        // Predicate that fails when length < 3
        $field = $this->focusInput(Input::new('username'))
            ->validation(
                static fn (string $v): bool => strlen($v) >= 3,
                'Username must be at least 3 characters',
            );

        // Trigger validation with single char (length 1 < 3, so predicate fails)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $view = $field->view();
        $this->assertStringContainsString('! Username must be at least 3 characters', $view);
    }

    public function testWithValidationWithNonEmptyPredicate(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validation(
                static fn (string $v): bool => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
                'Must be a valid email address',
            );

        // Trigger validation (single char is not a valid email)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Must be a valid email address', $field->getError());
    }

    public function testWithValidationErrorNotDisplayedWhenValid(): void
    {
        // Predicate that passes when value is positive number
        $field = $this->focusInput(Input::new('count'))
            ->validation(
                static fn (string $v): bool => (int) $v > 0,
                'Count must be positive',
            );

        // Type a positive number
        [$field] = $field->update(new KeyMsg(KeyType::Char, '5'));

        $view = $field->view();
        $this->assertStringNotContainsString('!', $view);
    }

    // -------------------------------------------------------------------------
    // validate() — runs on every update() call
    // -------------------------------------------------------------------------

    public function testValidateRunsOnUpdateWithFocusedField(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => 'Always invalid');

        // Initial update triggers validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Always invalid', $field->getError());
    }

    public function testValidateShowsNoErrorWhenValuePassesValidator(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => $v !== '' ? null : 'Cannot be empty');

        // Type a character to enter a value
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'J'));

        $this->assertNull($field->getError());
    }

    public function testValidateShowsErrorWhenValueDoesNotPassValidator(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => strlen($v) < 3 ? 'Too short' : null);

        // Type 2 characters (length < 3, should fail)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame('Too short', $field->getError());
    }

    public function testValidateShowsErrorAgainWhenValueBecomesInvalid(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => $v === '' ? 'Cannot be empty' : null);

        // Type a character (value becomes non-empty, passes)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'J'));
        $this->assertNull($field->getError());

        // Clear with Backspace (value becomes empty, fails)
        [$field] = $field->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('Cannot be empty', $field->getError());
    }

    public function testValidatePreservesErrorOnceSet(): void
    {
        // When error doesn't change between updates, validate() returns same instance
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => 'Static error');

        // Trigger validation with 'x' - error is set to 'Static error'
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        $error1 = $field->getError();

        // Trigger validation with 'y' - value changes but error stays 'Static error'
        // So validate() should return same instance (same object)
        [$field2] = $field->update(new KeyMsg(KeyType::Char, 'y'));
        $error2 = $field2->getError();

        // Error should still be 'Static error' (same value)
        $this->assertSame('Static error', $error1);
        $this->assertSame('Static error', $error2);
    }

    // -------------------------------------------------------------------------
    // Chained validation scenarios
    // -------------------------------------------------------------------------

    public function testValidatorWithMinLengthCheck(): void
    {
        $field = $this->focusInput(Input::new('password'))
            ->validator(static function (string $v): ?string {
                return strlen($v) < 8 ? 'Password must be at least 8 characters' : null;
            });

        // Trigger validation with short input (6 chars < 8)
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Password must be at least 8 characters', $field->getError());
    }

    public function testValidatorWithAlphanumericCheck(): void
    {
        $field = $this->focusInput(Input::new('username'))
            ->validator(static function (string $v): ?string {
                return ctype_alnum($v) ? null : 'Username must be alphanumeric';
            });

        // Trigger validation with special character
        [$field] = $field->update(new KeyMsg(KeyType::Char, '!'));

        $this->assertSame('Username must be alphanumeric', $field->getError());
    }

    public function testValidatorWithNumericCheck(): void
    {
        $field = $this->focusInput(Input::new('age'))
            ->validator(static function (string $v): ?string {
                return is_numeric($v) ? null : 'Age must be a number';
            });

        // Trigger validation with non-numeric
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Age must be a number', $field->getError());
    }

    public function testValidationWithNumericCheck(): void
    {
        $field = $this->focusInput(Input::new('age'))
            ->validation('is_numeric', 'Age must be a number');

        // Trigger validation with non-numeric char
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Age must be a number', $field->getError());
    }

    public function testValidationWithCustomPredicate(): void
    {
        $field = $this->focusInput(Input::new('zip'))
            ->validation(
                static fn (string $v): bool => preg_match('/^\d{5}$/', $v) === 1,
                'ZIP code must be 5 digits',
            );

        // Trigger validation with non-matching input
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('ZIP code must be 5 digits', $field->getError());
    }

    // -------------------------------------------------------------------------
    // Error persistence across fluent interface calls
    // -------------------------------------------------------------------------

    public function testErrorPersistsAfterWithTitle(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validator(static fn (string $v): ?string => 'Invalid email')
            ->withTitle('Email Address');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Invalid email', $field->getError());
        $this->assertStringContainsString('Email Address', $field->view());
        $this->assertStringContainsString('! Invalid email', $field->view());
    }

    public function testErrorPersistsAfterWithDescription(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validator(static fn (string $v): ?string => 'Invalid email')
            ->withDescription('Enter your email address');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Invalid email', $field->getError());
        $this->assertStringContainsString('! Invalid email', $field->view());
    }

    public function testErrorPersistsAfterWithPlaceholder(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validator(static fn (string $v): ?string => 'Invalid email')
            ->withPlaceholder('you@example.com');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Invalid email', $field->getError());
    }

    public function testErrorDoesNotPersistOnNewInstanceWithoutValidator(): void
    {
        // Create a field with validator that produces error, then create fresh one without validator
        $field = Input::new('name')
            ->validator(static fn (string $v): ?string => 'Error');

        $freshField = Input::new('name');

        $this->assertNull($freshField->getError());
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testNoValidatorMeansNoError(): void
    {
        $field = $this->focusInput(Input::new('name'));
        $this->assertNull($field->getError());
    }

    public function testEmptyStringValueWithRequiredValidator(): void
    {
        // Validator that always returns error (doesn't check value)
        $field = $this->focusInput(Input::new('required'))
            ->validator(static fn (string $v): ?string => 'This field is required');

        // Trigger validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('This field is required', $field->getError());
    }

    public function testWhitespaceOnlyValueWithRequiredValidator(): void
    {
        $field = $this->focusInput(Input::new('required'))
            ->validator(static fn (string $v): ?string => trim($v) === '' ? 'This field is required' : null);

        // Space character is whitespace, trim gives '', triggers error
        [$field] = $field->update(new KeyMsg(KeyType::Space));

        $this->assertSame('This field is required', $field->getError());
    }

    public function testValidatorClosureSignatureIsRespected(): void
    {
        // Ensure the validator closure receives exactly one string argument
        $paramType = null;
        $field = $this->focusInput(Input::new('test'))
            ->validator(static function (string $value) use (&$paramType): ?string {
                $paramType = gettype($value);
                return null;
            });

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('string', $paramType);
    }

    public function testShortAliasValidatorWorks(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->validator(static fn (string $v): ?string => 'Invalid');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Invalid', $field->getError());
    }

    public function testShortAliasValidationWorks(): void
    {
        $field = $this->focusInput(Input::new('count'))
            ->validation(static fn (string $v): bool => false, 'Error message');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('Error message', $field->getError());
    }

    // -------------------------------------------------------------------------
    // View output formatting
    // -------------------------------------------------------------------------

    public function testViewShowsErrorWithExclamationMarkPrefix(): void
    {
        $field = $this->focusInput(Input::new('field'))
            ->validator(static fn (string $v): ?string => 'Some error');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $view = $field->view();
        $this->assertStringContainsString('! Some error', $view);
    }

    public function testViewShowsErrorAfterTitleAndDescription(): void
    {
        $field = $this->focusInput(Input::new('email'))
            ->withTitle('Email')
            ->withDescription('Enter your email')
            ->validator(static fn (string $v): ?string => 'Error');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $view = $field->view();
        $lines = explode("\n", $view);

        // Order: title, description, input view, error
        $this->assertSame('Email', $lines[0]);
        $this->assertSame('Enter your email', $lines[1]);
        $this->assertSame('! Error', $lines[3]);
    }

    public function testViewShowsNoErrorLineWhenValid(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->withTitle('Name')
            ->validator(static fn (string $v): ?string => null);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $view = $field->view();
        $lines = explode("\n", $view);

        // Order: title, input view (no error when validator returns null)
        $this->assertCount(2, $lines);
        $this->assertSame('Name', $lines[0]);
    }

    // -------------------------------------------------------------------------
    // Validation does not run without update()
    // -------------------------------------------------------------------------

    public function testValidationDoesNotRunAtConstructionTime(): void
    {
        // Validation only runs when update() is called, not at construction
        $field = Input::new('email')
            ->validator(static fn (string $v): ?string => 'Error');

        // Without calling update(), error should be null
        $this->assertNull($field->getError());
    }

    public function testValidationRunsOnEveryUpdate(): void
    {
        $validateCount = 0;
        $field = $this->focusInput(Input::new('name'))
            ->validator(static function (string $value) use (&$validateCount): ?string {
                $validateCount++;
                return null;
            });

        // Each update() call triggers validation
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(1, $validateCount);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame(2, $validateCount);
    }

    /**
     * Step 12: revalidate() clears error when validation passes.
     * The errorSet sentinel in Input::mutate() allows explicit error clearing
     * (validate() uses direct instantiation to apply the sentinel).
     */
    public function testRevalidateClearsErrorWhenValidationPasses(): void
    {
        $field = $this->focusInput(Input::new('name'))
            ->validator(static fn (string $v): ?string => $v === '' ? 'Required' : null);

        // Typing 'x' makes value non-empty, validator passes on revalidate
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        // revalidate() on a value that passes validation clears the error
        $rf = $field->revalidate();
        $this->assertNull($rf->getError());
    }
}
