<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\FirstRunWizard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizardAction;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizardActionKind;

final class FirstRunWizardActionTest extends TestCase
{
    public function testFinishCarriesProviderAndKey(): void
    {
        $a = FirstRunWizardAction::finish('anthropic', 'sk-x');

        self::assertSame(FirstRunWizardActionKind::Finish, $a->kind);
        self::assertSame('anthropic', $a->provider);
        self::assertSame('sk-x', $a->key);
    }

    public function testSkipDefaultsToAPlainSkip(): void
    {
        $a = FirstRunWizardAction::skip();

        self::assertSame(FirstRunWizardActionKind::Skip, $a->kind);
        self::assertNull($a->provider);
        self::assertNull($a->key);
    }

    public function testSkipCanNameTheDeferredProviderButNeverCarriesAKey(): void
    {
        $a = FirstRunWizardAction::skip('openai');

        self::assertSame('openai', $a->provider);
        self::assertNull($a->key);
    }

    public function testCancelCarriesNothing(): void
    {
        $a = FirstRunWizardAction::cancel();

        self::assertSame(FirstRunWizardActionKind::Cancel, $a->kind);
        self::assertNull($a->provider);
        self::assertNull($a->key);
    }
}
