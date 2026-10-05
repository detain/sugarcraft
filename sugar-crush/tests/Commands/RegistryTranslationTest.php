<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\KeyBinding;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Commands\Specs\CommandDocGenerator;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingsDocGenerator;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Lang;

/**
 * Audit 15b-14, step 15b-14-3: the registries' user-facing text — built-in
 * command rows, key-binding descriptions and context headings, settings tab
 * and key labels — answers through `Lang::t()`, while the generated pages
 * built from that text stay English.
 *
 * Proved with a PSEUDO-LOCALE rather than by reading source: every English
 * value is wrapped in `⟦…⟧` in a throwaway `xx.php`, so a string that still
 * reaches the screen as a literal is the one that comes back unwrapped, and a
 * generator that forgot its `en` pin is the one whose page comes back
 * bracketed.
 */
final class RegistryTranslationTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../lang';

    private const PSEUDO = 'xx';

    private string $locale;

    private ?string $pseudoDir = null;

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::overrideNamespace('crush', self::LANG_DIR);
        T::setLocale($this->locale);
        if ($this->pseudoDir !== null) {
            array_map('unlink', glob($this->pseudoDir . '/*.php') ?: []);
            rmdir($this->pseudoDir);
        }
    }

    public function testEveryKeyABuiltInSpecAsksForExistsInEnglish(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $missing = [];
        foreach (glob(BuiltInCommands::specDir() . '/*.php') ?: [] as $file) {
            preg_match_all("/Lang::t\\(\\s*'([^']+)'/", (string) file_get_contents($file), $hits);
            self::assertNotSame([], $hits[1], basename($file) . ' shows no translated text');
            foreach ($hits[1] as $key) {
                if (!\array_key_exists($key, $en)) {
                    $missing[] = basename($file) . ': ' . $key;
                }
            }
        }

        self::assertSame([], $missing, 'spec keys with no lang/en.php entry');
    }

    public function testEveryLabelledSettingResolvesItsOwnLabelKey(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            if ($definition->label === $definition->key) {
                continue;
            }

            self::assertSame(
                $definition->label,
                Lang::t($definition->labelKey),
                "{$definition->key}'s label is not the one its labelKey names",
            );
        }
    }

    /**
     * Decision D7's English is kept, word for word: moving the tabs onto the
     * catalogue changed where the words live, not what they say.
     */
    public function testSettingTabsStillReadTheirEnglishLabels(): void
    {
        $labels = [];
        foreach (SettingCategory::cases() as $category) {
            $labels[$category->value] = $category->label();
        }

        self::assertSame([
            'model' => 'Model & Provider',
            'loop' => 'Agent loop',
            'context' => 'Context & Compaction',
            'permissions' => 'Permissions',
            'tools' => 'Tools',
            'memory' => 'Memory & Rules',
            'skills' => 'Skills',
            'subagents' => 'Sub-agents',
            'git' => 'Git & Automation',
            'interface' => 'Interface',
            'hooks' => 'Hooks & MCP',
            'server' => 'Server',
            'advanced' => 'Advanced',
        ], $labels);
    }

    public function testInLocaleRunsUnderTheGivenLocaleAndRestoresThePrevious(): void
    {
        T::setLocale('fr');
        self::assertSame('de', Lang::inLocale('de', static fn (): string => T::locale()));
        self::assertSame('fr', T::locale());

        try {
            Lang::inLocale('de', static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('fr', T::locale(), 'a throwing callable must not leave the pinned locale behind');
    }

    public function testBuiltInRowsAreTranslatedInTheActiveLocale(): void
    {
        $this->usePseudoLocale();

        foreach (CommandRegistry::all() as $spec) {
            self::assertPseudo($spec->description, "/{$spec->name} description");
            self::assertPseudo($spec->category, "/{$spec->name} category");
            if ($spec->paletteLabel !== null) {
                self::assertPseudo($spec->paletteLabel, "/{$spec->name} palette label");
            }
            if ($spec->argumentHint !== null) {
                self::assertPseudo($spec->argumentHint, "/{$spec->name} argument hint");
            }
        }
    }

    public function testKeyBindingDescriptionsAndHeadingsAreTranslatedButTheirChordsAreNot(): void
    {
        $english = KeyBindingRegistry::all();
        $this->usePseudoLocale();
        $translated = KeyBindingRegistry::all();

        self::assertSame(
            array_map(static fn (KeyBinding $b): string => $b->keys, $english),
            array_map(static fn (KeyBinding $b): string => $b->keys, $translated),
            'a chord label is routing data — KeyboardHandler derives its claim sets from it',
        );
        self::assertSame(
            array_map(static fn (KeyBinding $b): string => $b->context, $english),
            array_map(static fn (KeyBinding $b): string => $b->context, $translated),
            'a CONTEXT_* value is a routing key and stays English',
        );

        foreach ($translated as $binding) {
            self::assertPseudo($binding->description, $binding->id);
        }
        foreach (array_keys(KeyBindingRegistry::grouped()) as $heading) {
            self::assertPseudo($heading, 'a key-reference heading');
        }
    }

    public function testSettingLabelsAreTranslated(): void
    {
        $this->usePseudoLocale();

        foreach (SettingCategory::cases() as $category) {
            self::assertPseudo($category->label(), $category->labelKey());
        }
        foreach (SettingsSchema::all() as $definition) {
            if ($definition->label !== $definition->key) {
                self::assertPseudo($definition->label, $definition->labelKey);
            }
        }
    }

    public function testTheGeneratedPagesStayEnglishUnderAnotherLocale(): void
    {
        $commands = CommandDocGenerator::new()->blocks();
        $settings = SettingsDocGenerator::new()->blocks();

        $this->usePseudoLocale();

        self::assertSame($commands, CommandDocGenerator::new()->blocks(), 'docs/COMMANDS.md and the README roster must be generated in English');
        self::assertSame($settings, SettingsDocGenerator::new()->blocks(), 'docs/SETTINGS.md must be generated in English');
        self::assertSame(self::PSEUDO, T::locale(), 'a generator must hand the locale back');
        self::assertPseudo(CommandRegistry::all()[0]->description, 'the registry read after a generator ran');
    }

    /** Every English value wrapped in ⟦…⟧, placeholders intact, as locale `xx`. */
    private function usePseudoLocale(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $dir = sys_get_temp_dir() . '/crush-pseudo-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700), 'cannot create the pseudo-locale directory');
        $this->pseudoDir = $dir;

        file_put_contents($dir . '/en.php', '<?php return ' . var_export($en, true) . ';');
        file_put_contents(
            $dir . '/' . self::PSEUDO . '.php',
            '<?php return ' . var_export(array_map(static fn (string $v): string => '⟦' . $v . '⟧', $en), true) . ';',
        );

        T::overrideNamespace('crush', $dir);
        T::setLocale(self::PSEUDO);
    }

    private static function assertPseudo(string $text, string $what): void
    {
        self::assertMatchesRegularExpression('/^⟦.*⟧$/su', $text, "{$what} is not translated: {$text}");
    }
}
