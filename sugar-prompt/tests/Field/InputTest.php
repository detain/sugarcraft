<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Input;
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
}
