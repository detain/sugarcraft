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

    /**
     * E736 1.7 keystone: attaching a validator to an ALREADY-INVALID value
     * takes the revalidate() fresh-ctor branch — which used to rebuild the
     * instance without the private $validator closure, so the field stopped
     * validating after its first error. The rule must keep re-firing on
     * every later toggle (mutation: drop carryNonCtorState() in revalidate()).
     */
    public function testValidatorSurvivesErrorRecomputeRoundTrip(): void
    {
        $f = Confirm::new('agree', false)
            ->withValidator(static fn (bool $v): ?string => $v ? null : 'must agree');
        $this->assertSame('must agree', $f->getError());

        [$f] = $f->focus();
        [$f] = $f->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertNull($f->getError(), 'checking clears the error');

        [$f] = $f->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame('must agree', $f->getError(), 'the validator must still exist after the recompute');
        $this->assertTrue($f->isFocused(), 'revalidate() rebuild preserves focus');
    }

    /**
     * E736 1.7: withValidator() now rides the canonical mutate() path, so
     * it must preserve the HasHideFunc / HasDynamicLabels closures exactly
     * as the old clone-based bypass did (mutation: route withValidator
     * through a mutate() that forgot the trait carry).
     */
    public function testWithValidatorPreservesTraitClosures(): void
    {
        $f = Confirm::new('q')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true)
            ->withValidator(static fn (bool $v): ?string => null);

        $this->assertSame('DYN', $f->getTitle());
        $this->assertSame('DSCF', $f->getDescription());
        $this->assertTrue($f->isHidden([]));
    }

    /**
     * E736 1.7 companion pin: the trait closures document themselves as
     * "preserved across mutations" — Confirm::mutate() rebuilt via new self()
     * and used to drop them (e.g. withTitleFunc set, then any later
     * withTitle()/focus() silently lost the dynamic title). Pin the contract
     * on the two representative mutate paths.
     */
    public function testTraitClosuresSurviveMutatePaths(): void
    {
        $titled = Confirm::new('q')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withTitle('ignored-while-func-set');
        $this->assertSame('DYN', $titled->getTitle());

        $hidden = Confirm::new('q')
            ->withHideFunc(static fn (array $v): bool => true)
            ->withDefault(true);
        $this->assertTrue($hidden->isHidden([]));
        $this->assertTrue($hidden->value());
    }
}
