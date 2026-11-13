<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Help\HelpCatalog;
use SugarCraft\ScriptUi\Help\HelpFormatter;

/**
 * scriptui-59: the help surface (command descriptions, option / argument
 * texts, example captions, section labels) rides the locale catalogs.
 *
 * Drift guard in the candy-code style: the rows are RE-DERIVED from the
 * command classes, so a flag or description added without a catalog row goes
 * red here (and in LocaleParityTest for the other sixteen catalogs) instead of
 * shipping English-only.
 */
final class HelpDriftTest extends TestCase
{
    protected function setUp(): void
    {
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        /** @var array<string, string> $rows */
        $rows = require __DIR__ . '/../../lang/' . $locale . '.php';

        return array_filter($rows, static fn (string $k): bool => str_starts_with($k, 'help.'), ARRAY_FILTER_USE_KEY);
    }

    public function testEnglishCatalogCarriesExactlyTheDerivedRows(): void
    {
        $derived = HelpCatalog::derive(new Application());

        self::assertSame($derived, self::catalog('en'), 'lang/en.php help.* rows drifted from the command classes — run: php tools/gen-help-catalog.php');
    }

    public function testEveryOptionAndArgumentResolvesToItsEnglishLiteral(): void
    {
        $app = new Application();
        $en = self::catalog('en');
        foreach ($app->all() as $command) {
            if (!HelpCatalog::owns($command)) {
                continue;
            }
            $name = (string) $command->getName();
            foreach ($command->getDefinition()->getOptions() as $option) {
                $text = null;
                foreach (HelpCatalog::optionKeys($name, $option->getName()) as $key) {
                    if (isset($en[$key])) {
                        $text = $en[$key];
                        break;
                    }
                }
                self::assertSame($option->getDescription(), $text, "{$name} --{$option->getName()} has no catalog row");
            }
            foreach ($command->getDefinition()->getArguments() as $argument) {
                self::assertSame(
                    $argument->getDescription(),
                    $en['help.arg.' . $name . '.' . $argument->getName()] ?? null,
                    "{$name} <{$argument->getName()}> has no catalog row",
                );
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function localeProvider(): array
    {
        $rows = [];
        foreach (glob(__DIR__ . '/../../lang/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if ($code !== 'en') {
                $rows[$code] = [$code];
            }
        }

        return $rows;
    }

    /**
     * Command-line tokens in the English text (`--flag`, `--`, `{{VAR}}`,
     * `candy-scriptui`-style literals in backticks) are interface, not prose:
     * a translation that mangles one tells the user to type something that
     * does not exist.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testTranslationsKeepTheTypedTokensVerbatim(string $code): void
    {
        $en = self::catalog('en');
        $loc = self::catalog($code);
        self::assertSame(array_keys($en), array_keys($loc), "{$code}: help.* keys must mirror en in order");

        foreach ($en as $key => $english) {
            preg_match_all('/--[a-z][a-z0-9-]*|\{\{[A-Z_]+\}\}|`[^`]+`/', $english, $tokens);
            foreach ($tokens[0] as $token) {
                self::assertStringContainsString($token, $loc[$key], "{$code} {$key} lost the token {$token}");
            }
            self::assertNotSame('', trim($loc[$key]), "{$code} {$key} is empty");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testLocalesActuallyTranslateTheSurface(string $code): void
    {
        $en = self::catalog('en');
        $loc = self::catalog($code);
        $same = 0;
        foreach ($en as $key => $english) {
            if ($loc[$key] === $english) {
                $same++;
            }
        }

        // Cognates and bare tokens legitimately match (a handful of one-word
        // labels); a catalog that is mostly English is an untranslated stub.
        self::assertLessThan(count($en) * 0.25, $same, "{$code}: {$same} of " . count($en) . ' help rows are still English');
    }

    public function testGermanLocaleLocalizesDescriptionsOptionsAndLabels(): void
    {
        T::setLocale('de');
        $app = new Application();
        $choose = $app->find('choose');

        self::assertNotSame('Pick one option from a list.', $choose->getDescription());
        self::assertSame(self::catalog('de')['help.cmd.choose'], $choose->getDescription());
        self::assertNotSame(
            'Visible item count.',
            $choose->getDefinition()->getOption('height')->getDescription(),
        );
        self::assertSame(
            self::catalog('de')['help.arg.choose.options'],
            $choose->getDefinition()->getArgument('options')->getDescription(),
        );

        $help = (new HelpFormatter())->format($choose);
        self::assertStringContainsString(self::catalog('de')['help.label.examples'], $help);
        self::assertStringContainsString(self::catalog('de')['help.example.choose.0'], $help);
        self::assertStringNotContainsString('Examples:', $help);
    }

    public function testLocalizationKeepsOptionShapes(): void
    {
        $english = (new Application())->find('choose')->getDefinition();
        T::setLocale('de');
        $german = (new Application())->find('choose')->getDefinition();

        self::assertSame(array_keys($english->getOptions()), array_keys($german->getOptions()));
        foreach ($english->getOptions() as $name => $option) {
            $other = $german->getOption($name);
            self::assertSame($option->acceptValue(), $other->acceptValue(), $name);
            self::assertSame($option->isArray(), $other->isArray(), $name);
            self::assertSame($option->getDefault(), $other->getDefault(), $name);
            self::assertSame($option->getShortcut(), $other->getShortcut(), $name);
        }
        self::assertSame(
            array_keys($english->getArguments()),
            array_keys($german->getArguments()),
        );
    }

    public function testUnknownLocaleFallsBackToTheEnglishLiterals(): void
    {
        T::setLocale('xx');

        self::assertSame('Pick one option from a list.', (new Application())->find('choose')->getDescription());
    }
}
