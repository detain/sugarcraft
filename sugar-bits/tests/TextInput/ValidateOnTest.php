<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\TextInput;

use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Bits\TextInput\ValidateOn;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class ValidateOnTest extends TestCase
{
    // ---- ValidateOn enum -------------------------------------------------

    public function testValidateOnEnumCases(): void
    {
        $this->assertSame('none', ValidateOn::None->value);
        $this->assertSame('blur', ValidateOn::Blur->value);
        $this->assertSame('change', ValidateOn::Change->value);
        $this->assertSame('submit', ValidateOn::Submit->value);
    }

    // ---- withValidateOn() ------------------------------------------------

    public function testWithValidateOnReturnsNewInstance(): void
    {
        $t = TextInput::new();
        $t2 = $t->withValidateOn(ValidateOn::Blur);
        $this->assertNotSame($t, $t2);
        $this->assertSame(ValidateOn::Blur, $t2->validateOn);
    }

    public function testValidateOnDefaultIsNone(): void
    {
        $t = TextInput::new();
        $this->assertSame(ValidateOn::None, $t->validateOn);
    }

    // ---- Blur timing ----------------------------------------------------

    public function testValidationRunsOnBlurWhenValidateOnBlur(): void
    {
        // Set validateOn BEFORE focus to ensure no immediate validation happens
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Blur)
            ->setValue('hi');
        [$t, ] = $t->focus();
        $t = $t->withValidator(static fn(string $s): ?string
            => strlen($s) >= 3 ? null : 'too short');

        // Value is "hi" (length 2) so validation should fail
        $this->assertNull($t->err());

        // Blur should trigger validation
        $blurred = $t->blur();
        $this->assertSame('too short', $blurred->err());
    }

    public function testValidationRunsOnBlurEvenIfValueUnchanged(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Blur)
            ->setValue('hello');
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        $this->assertSame(0, $validateCalls);
        $t->blur();
        $this->assertSame(1, $validateCalls);
    }

    public function testNoValidationOnBlurWhenValidateOnChange(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Change)
            ->setValue('hi');
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        // With Change mode, validation runs on every edit, so blur should not run extra validation
        $t->blur();
        $this->assertSame(1, $validateCalls); // One validation from setValue('hi')
    }

    // ---- Change timing --------------------------------------------------

    public function testValidationRunsOnEveryKeystrokeWhenValidateOnChange(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Change);
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        // withValidator validates immediately on current value (validateCalls=1)
        $this->assertSame(1, $validateCalls);

        // Type 'a' (length 1) - should fail validation (validateCalls=2)
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(2, $validateCalls);
        $this->assertSame('too short', $t->err());

        // Type 'b' (length 2) - should still fail
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame(3, $validateCalls);
        $this->assertSame('too short', $t->err());

        // Type 'c' (length 3) - should pass
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(4, $validateCalls);
        $this->assertNull($t->err());
    }

    public function testNoImmediateValidationOnEditWhenValidateOnBlur(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Blur);
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        // Type 'a' - validation should NOT run yet
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(0, $validateCalls);
        $this->assertNull($t->err());
    }

    // ---- Submit timing ---------------------------------------------------

    public function testValidationRunsOnEnterWhenValidateOnSubmit(): void
    {
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Submit)
            ->setValue('hi');
        [$t, ] = $t->focus();
        $t = $t->withValidator(static fn(string $s): ?string
            => strlen($s) >= 3 ? null : 'too short');

        // Value is "hi" (length 2) so validation should fail on submit
        $this->assertNull($t->err());

        // Press Enter - should trigger validation
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('too short', $t->err());
    }

    public function testValidationPassesOnEnterWhenValueValid(): void
    {
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Submit)
            ->setValue('hello');
        [$t, ] = $t->focus();
        $t = $t->withValidator(static fn(string $s): ?string
            => strlen($s) >= 3 ? null : 'too short');

        // Press Enter - should pass validation
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($t->err());
    }

    public function testNoValidationOnKeystrokeWhenValidateOnSubmit(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::Submit);
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        // Type 'a' - validation should NOT run yet
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(0, $validateCalls);
        $this->assertNull($t->err());
    }

    // ---- None (default) timing ------------------------------------------

    public function testValidationRunsImmediatelyWhenValidateOnNone(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::None);
        [$t, ] = $t->focus();
        $t = $t->withValidator(static function (string $s) use (&$validateCalls): ?string {
            $validateCalls++;
            return strlen($s) >= 3 ? null : 'too short';
        });

        // withValidator validates immediately on current value (validateCalls=1)
        $this->assertSame(1, $validateCalls);

        // Type 'a' - validation should run (validateCalls=2)
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(2, $validateCalls);
        $this->assertSame('too short', $t->err());
    }

    public function testValidationRunsOnSetValueWhenValidateOnNone(): void
    {
        $validateCalls = 0;
        $t = TextInput::new()
            ->withValidateOn(ValidateOn::None)
            ->withValidator(static function (string $s) use (&$validateCalls): ?string {
                $validateCalls++;
                return strlen($s) >= 3 ? null : 'too short';
            });

        // withValidator validates immediately on current value (validateCalls=1)
        $this->assertSame(1, $validateCalls);

        // setValue should trigger validation (validateCalls=2)
        $t = $t->setValue('hello');
        $this->assertSame(2, $validateCalls);
        $this->assertNull($t->err());
    }
}
