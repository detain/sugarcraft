<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Validator\Required;
use SugarCraft\Forms\Validator\Validator;
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

    /**
     * withValidator() must accept a Validator instance (parity with Input),
     * not just a Closure. Revert to `?\Closure` → TypeError here.
     * A Validator is fed the checkbox state as '1' (checked) / '' (unchecked).
     */
    public function testWithValidatorAcceptsValidatorInstance(): void
    {
        $validator = new class implements Validator {
            public function validate(string $input): true|string
            {
                return $input === '1' ? true : 'must check';
            }
        };

        // Unchecked (false) → fed '' → invalid.
        $f = Confirm::new('agree', false)->withValidator($validator);
        $this->assertSame('must check', $f->getError());

        // Checked default (true) → fed '1' → valid.
        $f2 = Confirm::new('agree', true)->withValidator($validator);
        $this->assertNull($f2->getError());
    }

    public function testWithValidatorAcceptsBuiltinRequiredValidator(): void
    {
        // Required on a Confirm means "must be checked": false → '' → fails.
        $f = Confirm::new('agree', false)->withValidator(new Required());
        $this->assertNotNull($f->getError());

        // Toggling to checked ('1') clears the error via revalidate().
        [$f] = $f->focus();
        [$f] = $f->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($f->getError());
    }

    public function testWithValidatorStillAcceptsClosure(): void
    {
        // The Closure path must remain intact after widening the union.
        $f = Confirm::new('agree', false)
            ->withValidator(static fn (bool $v): ?string => $v ? null : 'must agree');
        $this->assertSame('must agree', $f->getError());
    }

    public function testValidatorShortAliasAcceptsValidatorInstance(): void
    {
        $f = Confirm::new('agree', false)->validator(new Required());
        $this->assertNotNull($f->getError());
    }
}
