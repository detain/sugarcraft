<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Confirm;
use PHPUnit\Framework\TestCase;

final class ConfirmTest extends TestCase
{
    public function testInitialDefaultsToNo(): void
    {
        $f = Confirm::new('q');
        $this->assertFalse($f->value());
    }

    public function testWithDefault(): void
    {
        $this->assertTrue(Confirm::new('q')->withDefault(true)->value());
    }

    public function testYToggles(): void
    {
        [$f, ] = Confirm::new('q')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertTrue($f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertFalse($f->value());
    }

    public function testArrowsAndVim(): void
    {
        [$f, ] = Confirm::new('q')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Left));
        $this->assertTrue($f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertFalse($f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertTrue($f->value());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $f = Confirm::new('q');
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFalse($f->value());
    }

    public function testCustomLabels(): void
    {
        $f = Confirm::new('q')->withLabels('OK', 'Cancel')->withTitle('Continue?');
        $view = $f->view();
        $this->assertStringContainsString('OK', $view);
        $this->assertStringContainsString('Cancel', $view);
        $this->assertStringContainsString('Continue?', $view);
    }

    public function testValidatorRunsOnDefault(): void
    {
        $f = Confirm::new('agree', false)
            ->withValidator(static fn (bool $v): ?string => $v ? null : 'must agree');
        $this->assertSame('must agree', $f->getError());
    }

    public function testValidatorRunsOnValueChange(): void
    {
        $f = Confirm::new('agree', false)
            ->withValidator(static fn (bool $v): ?string => $v ? null : 'must agree');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($f->getError());
    }

    public function testValidatorNullClears(): void
    {
        $f = Confirm::new('q')
            ->withValidator(static fn (bool $v): ?string => 'always')
            ->withValidator(null);
        $this->assertNull($f->getError());
    }
}
