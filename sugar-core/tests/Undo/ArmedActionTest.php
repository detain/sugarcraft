<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Undo;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Undo\ArmedAction;

final class ArmedActionTest extends TestCase
{
    public function testNewCarriesKindPromptAndPayload(): void
    {
        $armed = ArmedAction::new('delete', 'Delete 2 files?', ['paths' => ['a', 'b']]);

        $this->assertSame('delete', $armed->kind);
        $this->assertSame('Delete 2 files?', $armed->prompt);
        $this->assertSame(['paths' => ['a', 'b']], $armed->payload);
        $this->assertSame('', $armed->localeAffirmative);
    }

    public function testNewDefaultsToAnEmptyPayload(): void
    {
        $this->assertSame([], ArmedAction::new('copy', 'Copy?')->payload);
    }

    public function testLowercaseYAlwaysConfirms(): void
    {
        $this->assertTrue(ArmedAction::new('delete', 'Delete?')->confirms('y'));
    }

    public function testUppercaseYDoesNotConfirm(): void
    {
        $this->assertFalse(ArmedAction::new('delete', 'Delete?')->confirms('Y'));
    }

    public function testAnyOtherKeyCancels(): void
    {
        $armed = ArmedAction::new('delete', 'Delete?');

        foreach (['n', 'x', ' ', "\n", '1'] as $rune) {
            $this->assertFalse($armed->confirms($rune), "rune {$rune} must cancel");
        }
    }

    public function testAnEmptyRuneNeverConfirms(): void
    {
        $this->assertFalse(ArmedAction::new('delete', 'Delete?', [], 'j')->confirms(''));
    }

    public function testTheLocaleAffirmativeConfirms(): void
    {
        // German teaches `j`; Turkish teaches `e`. Both confirm alongside `y`.
        $german = ArmedAction::new('delete', 'Löschen?', [], 'j');
        $this->assertTrue($german->confirms('j'));
        $this->assertTrue($german->confirms('y'));
        $this->assertFalse($german->confirms('n'));
    }

    public function testTheLocaleAffirmativeIsLowercasedBeforeMatching(): void
    {
        // A locale word given in capitals still matches its lowercase keystroke,
        // because the comparison runs against mb_strtolower() of the word.
        $armed = ArmedAction::new('delete', 'Удалить?', [], 'Д');

        $this->assertTrue($armed->confirms('д'));
        $this->assertFalse($armed->confirms('Д'));
    }

    public function testAMultibyteLocaleAffirmativeConfirms(): void
    {
        $this->assertTrue(ArmedAction::new('delete', 'Удалить?', [], 'д')->confirms('д'));
    }

    public function testACapitalisedLocaleWordConfirmsOnlyItsLowercaseKeystroke(): void
    {
        // Regression: new()'s docblock promised case-insensitive matching for a
        // capitalised locale word. The word folds to `j`; the typed rune does not.
        $armed = ArmedAction::new('delete', 'Löschen?', [], 'J');

        $this->assertTrue($armed->confirms('j'));
        $this->assertFalse($armed->confirms('J'));
        $this->assertTrue($armed->confirms('y'));
    }

    public function testTheLocaleAffirmativeDoesNotOverrideAnotherKey(): void
    {
        // A locale that teaches `e` must not turn `j` into a confirmation.
        $this->assertFalse(ArmedAction::new('delete', 'Sil?', [], 'e')->confirms('j'));
    }
}
