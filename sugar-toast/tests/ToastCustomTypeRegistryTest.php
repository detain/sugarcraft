<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Toast\{AlertTypeDefinition, AlertTypeRegistry, Position, SymbolSet, Toast, ToastType};

/**
 * TST-CUSTTYPE seam leg: bubbleup's RegisterNewAlertType as an immutable
 * registry value. (The old built-in-spellings-only coverage lives in
 * ToastTypeSpellingTest — this file exercises the registry.)
 */
final class ToastCustomTypeRegistryTest extends TestCase
{
    private function registry(): AlertTypeRegistry
    {
        return AlertTypeRegistry::new()->with(
            AlertTypeDefinition::new('deploy', '🚀', Color::hex('#a855f7'), ToastType::Success)
        );
    }

    private function toast(): Toast
    {
        return Toast::new(40)->withPosition(Position::TopLeft)->withTypeRegistry($this->registry());
    }

    public function testRegisteredStringTypePaintsItsPrefixAndColour(): void
    {
        $out = $this->toast()->alert('deploy', 'v2.4 is live')->view('', 50, 6);
        $this->assertStringContainsString('🚀', $out);
        $this->assertStringContainsString("38;2;168;85;247m🚀", $out);
        $this->assertStringNotContainsString('✔', $out, 'the base type glyph does not leak');
    }

    public function testLookupIsCaseInsensitiveAndRegistryResolvesBeforeBuiltIns(): void
    {
        $reg = $this->registry()->with(AlertTypeDefinition::new('error', '!!'));
        $out = Toast::new(40)->withTypeRegistry($reg)->alert('ERROR', 'boom')->view('', 50, 6);
        $this->assertStringContainsString('!!', Ansi::strip($out));
        $this->assertStringNotContainsString('✖', $out);
        $this->assertNotNull($this->registry()->resolve('DEPLOY'));
    }

    public function testBuiltInsStillResolveAndUnknownStillThrowsNamingKnownKeys(): void
    {
        $toast = $this->toast();
        $this->assertCount(1, $toast->alert('info', 'x')->activeAlerts());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown toast type: nope (known: error, warning, info, success, deploy');
        $toast->alert('nope', 'x');
    }

    public function testWithoutARegistryACustomKeyStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Toast::new()->alert('deploy', 'x');
    }

    public function testAlertCarriesDefinitionAndBaseSeverity(): void
    {
        $alert = $this->toast()->alert('deploy', 'x')->activeAlerts()[0];
        $this->assertSame(ToastType::Success, $alert->type);
        $this->assertSame('deploy', $alert->definition?->key);
        $kept = $alert->withProgress(0.5);
        $this->assertSame($alert->definition, $kept->definition, 'mutate() carries the definition');
    }

    public function testAsciiSetAndAccessibleTextUseTheBaseUnlessOverridden(): void
    {
        $out = Ansi::strip(
            $this->toast()->withSymbolSet(SymbolSet::Ascii)->withAccessibleText()->alert('deploy', 'ok')->view('', 50, 6)
        );
        $this->assertStringContainsString('[S] Success ok', $out);

        $reg = AlertTypeRegistry::new()->with(
            AlertTypeDefinition::new('deploy', '🚀', null, ToastType::Success, 'Shipped', '[D]')
        );
        $out = Ansi::strip(
            Toast::new(40)->withTypeRegistry($reg)->withSymbolSet(SymbolSet::Ascii)->withAccessibleText()
                ->alert('deploy', 'ok')->view('', 50, 6)
        );
        $this->assertStringContainsString('[D] Shipped ok', $out);
    }

    public function testProgressToastResolvesCustomTypesToo(): void
    {
        $out = $this->toast()->progressToast('deploy', 'ship', 0.5)->view('', 50, 6);
        $this->assertStringContainsString('🚀', $out);
    }

    public function testRegistryIsImmutable(): void
    {
        $empty = AlertTypeRegistry::new();
        $with = $empty->with(AlertTypeDefinition::new('a', 'A'));
        $this->assertSame([], $empty->keys());
        $this->assertSame(['a'], $with->keys());
        $this->assertSame('B', $with->with(AlertTypeDefinition::new('a', 'B'))->resolve('a')?->prefix);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDefinitions')]
    public function testMalformedDefinitionsAreRefused(string $key, string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AlertTypeDefinition::new($key, $prefix);
    }

    /** @return array<string, array{string,string}> */
    public static function badDefinitions(): array
    {
        return [
            'upper key' => ['Deploy', 'x'],
            'empty key' => ['', 'x'],
            'spaced key' => ['a b', 'x'],
            'empty prefix' => ['ok', ''],
            'escape in prefix' => ['ok', "\x1b[31m"],
            'newline in prefix' => ['ok', "a\nb"],
            'osc-ish C1' => ['ok', "\u{9b}31m"],
            'too wide' => ['ok', '123456789'],
        ];
    }

    public function testAsciiPrefixMustBePrintableAscii(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AlertTypeDefinition::new('ok', 'x', asciiPrefix: '🚀');
    }
}
