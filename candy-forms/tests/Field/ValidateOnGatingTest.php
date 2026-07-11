<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\TextInput\ValidateOn;

/**
 * Field-level validation-timing gating (Input + Text withValidateOn).
 *
 * The Field layer historically ran its validator chain on EVERY keystroke,
 * so the TextInput\ValidateOn Blur/Submit modes were unreachable from the
 * Field — an expensive validator (e.g. a catastrophic Pattern) paid its cost
 * per keypress. withValidateOn() lets callers defer the chain to blur/submit.
 *
 * Revert-proof: if the per-keystroke gate is removed (validate() runs
 * unconditionally in update()), the Blur/Submit "no error after keystroke"
 * assertions fail because the always-erroring validator would fire.
 */
final class ValidateOnGatingTest extends TestCase
{
    private function focusInput(Input $f): Input
    {
        [$focused] = $f->focus();
        return $focused;
    }

    private function focusText(Text $f): Text
    {
        [$focused] = $f->focus();
        return $focused;
    }

    // ---- Input -----------------------------------------------------------

    public function testInputDefaultValidatesOnEveryKeystroke(): void
    {
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('always', $field->getError());
        $this->assertSame(ValidateOn::None, $this->readValidateOn($field));
    }

    public function testInputSubmitModeDoesNotValidateOnKeystroke(): void
    {
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Submit);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        // Keystroke processed, but validator deferred → no error yet.
        $this->assertSame('x', $field->value());
        $this->assertNull($field->getError());
    }

    public function testInputBlurModeDoesNotValidateOnKeystroke(): void
    {
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Blur);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertNull($field->getError());
    }

    public function testInputSubmitModeStillDeferredAcrossMultipleKeystrokes(): void
    {
        // Proves validateOn survives mutate() (threaded through every new self).
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Submit);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'a'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'b'));
        [$field] = $field->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertSame('abc', $field->value());
        $this->assertNull($field->getError());
    }

    public function testInputSubmitModeValidatesOnRevalidate(): void
    {
        // Form submit path calls revalidate() → the deferred chain runs.
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Submit);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($field->getError());

        $field = $field->revalidate();
        $this->assertSame('always', $field->getError());
    }

    public function testInputBlurModeValidatesOnBlur(): void
    {
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Blur);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($field->getError());

        $field = $field->blur();
        $this->assertSame('always', $field->getError());
    }

    public function testInputChangeModeValidatesOnKeystroke(): void
    {
        // Change is explicit validate-on-every-keystroke (same as default).
        $field = $this->focusInput(Input::new('q'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Change);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('always', $field->getError());
    }

    public function testInputWithValidateOnReturnsNewInstance(): void
    {
        $a = Input::new('q');
        $b = $a->withValidateOn(ValidateOn::Blur);
        $this->assertNotSame($a, $b);
    }

    // ---- Text ------------------------------------------------------------

    public function testTextDefaultValidatesOnEveryKeystroke(): void
    {
        $field = $this->focusText(Text::new('bio'))
            ->withValidator(static fn (string $v): ?string => 'always');

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('always', $field->getError());
    }

    public function testTextSubmitModeDoesNotValidateOnKeystroke(): void
    {
        $field = $this->focusText(Text::new('bio'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Submit);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame('x', $field->value());
        $this->assertNull($field->getError());
    }

    public function testTextBlurModeValidatesOnBlur(): void
    {
        $field = $this->focusText(Text::new('bio'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Blur);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($field->getError());

        $field = $field->blur();
        $this->assertSame('always', $field->getError());
    }

    public function testTextSubmitModeValidatesOnRevalidate(): void
    {
        $field = $this->focusText(Text::new('bio'))
            ->withValidator(static fn (string $v): ?string => 'always')
            ->withValidateOn(ValidateOn::Submit);

        [$field] = $field->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($field->getError());

        $field = $field->revalidate();
        $this->assertSame('always', $field->getError());
    }

    private function readValidateOn(Input $field): ValidateOn
    {
        $ref = new \ReflectionProperty(Input::class, 'validateOn');
        return $ref->getValue($field);
    }
}
