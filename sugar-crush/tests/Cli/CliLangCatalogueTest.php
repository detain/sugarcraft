<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\Lang;

/**
 * The `cli.` block of the `crush` catalogue (audit 15b-14, step 15b-14-2):
 * the CLI's user-facing text — the help screen, argv errors, subcommand
 * output, serve/attach/acp messages and Bootstrap's launch notices — read
 * through {@see Lang::t()} from `lang/en.php`.
 *
 * {@see \SugarCraft\Crush\Tests\LangParityTest} already proves every LITERAL
 * key src asks for exists. What it cannot see is a key held in a table and
 * resolved later — the completion descriptions and the launch-notice map — and
 * the one property English output depends on: that moving a string into the
 * catalogue changed no byte of it. Both are pinned here, under `en`, because
 * every other test that reads this text compares it against English.
 */
final class CliLangCatalogueTest extends TestCase
{
    private const ENGLISH_CATALOGUE = __DIR__ . '/../../lang/en.php';

    private const LOCALE_DIR = __DIR__ . '/../../lang';

    private string $locale;

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    /** @return array<string, string> */
    private static function english(): array
    {
        return require self::ENGLISH_CATALOGUE;
    }

    /**
     * The launch-notice constants stay the English source of truth (the D7
     * shape SettingCategory::label() has), so each catalogue value must be
     * the constant it translates, byte for byte — otherwise every guard that
     * reads the constant would be measuring a line the launcher no longer
     * prints.
     */
    public function testEveryLaunchNoticeEntryIsTheEnglishConstantItTranslates(): void
    {
        $english = self::english();
        $map = Bootstrap::LAUNCH_NOTICE_CATALOGUE;
        self::assertNotSame([], $map, 'the launch-notice map is empty, so this is vacuous');

        $reflection = new \ReflectionClass(Bootstrap::class);
        $byValue = [];
        foreach ($reflection->getReflectionConstants() as $constant) {
            if (\is_string($constant->getValue())) {
                $byValue[$constant->getValue()][] = $constant->getName();
            }
        }

        foreach ($map as $text => $key) {
            self::assertStringStartsWith('cli.launch.', $key);
            self::assertArrayHasKey($key, $english, "{$key} is in Bootstrap::LAUNCH_NOTICE_CATALOGUE but not in lang/en.php");
            self::assertSame($text, $english[$key], "lang/en.php {$key} drifted from the constant it translates");
            self::assertCount(1, $byValue[$text] ?? [], "{$key}'s English text is not exactly one Bootstrap constant");
        }

        $launchKeys = \array_filter(\array_keys($english), static fn (string $k): bool => \str_starts_with($k, 'cli.launch.'));
        \sort($launchKeys);
        $mapped = \array_values($map);
        \sort($mapped);
        self::assertSame($launchKeys, $mapped, 'a cli.launch.* entry is not reached through Bootstrap::LAUNCH_NOTICE_CATALOGUE');
    }

    /**
     * A translated launch format is a printf format of its own. It may reorder
     * (`%1$s`) and drop the English agreement slots, but sprintf() throws on a
     * format that asks for more arguments than the English one is given — a
     * fatal at the first launch that raises the notice, in that locale only.
     */
    public function testEveryLocalesLaunchFormatRendersWithTheEnglishArguments(): void
    {
        $english = self::english();
        $checked = 0;
        foreach (\glob(self::LOCALE_DIR . '/*.php') ?: [] as $file) {
            $locale = \basename($file, '.php');
            $catalogue = require $file;
            foreach (Bootstrap::LAUNCH_NOTICE_CATALOGUE as $key) {
                $format = (string) ($catalogue[$key] ?? $english[$key]);
                $conversions = (int) \preg_match_all('/%[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', \str_replace('%%', '', $english[$key]));
                $arguments = \array_fill(0, $conversions, '1');
                try {
                    \vsprintf($format, $arguments);
                } catch (\ArgumentCountError | \ValueError $e) {
                    self::fail("lang/{$locale}.php {$key} cannot be rendered with the English arguments: " . $e->getMessage());
                }
                ++$checked;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    /**
     * The completion tables hold catalogue KEYS, resolved when a script is
     * generated, so LangParityTest's literal scan cannot see them.
     */
    public function testEveryCompletionDescriptionKeyExistsInEnglish(): void
    {
        $english = self::english();
        $reflection = new \ReflectionClass(Subcommands::class);

        $options = $reflection->getConstant('OPTIONS');
        self::assertIsArray($options);
        $descriptions = $reflection->getConstant('SUBCOMMAND_DESCRIPTIONS');
        self::assertIsArray($descriptions);

        $keys = [...\array_column($options, 'desc'), ...\array_values($descriptions)];
        self::assertCount(\count($options) + \count($descriptions), $keys);
        foreach ($keys as $key) {
            self::assertStringStartsWith('cli.completion.', $key);
            self::assertArrayHasKey($key, $english, "completion description key {$key} is not in lang/en.php");
        }
    }

    public function testCompletionScriptsCarryTheEnglishDescriptions(): void
    {
        $method = new \ReflectionMethod(Subcommands::class, 'fishCompletion');
        $fish = (string) $method->invoke(null);
        self::assertStringContainsString("-l prompt -s p -r -d 'Run a single prompt and exit (one-shot mode)'", $fish);
        self::assertStringContainsString("-a 'session' -d 'Manage stored sessions'", $fish);
        self::assertStringContainsString("-d 'session argument'", $fish);

        $zsh = (string) (new \ReflectionMethod(Subcommands::class, 'zshCompletion'))->invoke(null);
        self::assertStringContainsString("'(-h --help)--help[Show the help screen]'", $zsh);
        self::assertStringContainsString("'doctor:Report on this installation and exit'", $zsh);
    }

    /**
     * Every `cli.` key is asked for by src — as a literal argument to
     * Lang::t() or as a table value resolved through it — so the block holds
     * no orphan a translator would translate for nothing.
     */
    public function testEveryCliKeyIsUsedBySrc(): void
    {
        $source = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $source .= (string) \file_get_contents($file->getPathname());
            }
        }

        $orphans = [];
        foreach (\array_keys(self::english()) as $key) {
            if (\str_starts_with($key, 'cli.') && !\str_contains($source, "'" . $key . "'")) {
                $orphans[] = $key;
            }
        }

        self::assertSame([], $orphans, 'cli.* keys in lang/en.php that nothing in src/ asks for');
    }

    /**
     * Four groups add catalogue blocks concurrently (W11), so this one stays a
     * single contiguous, sorted run between its two markers — which is what
     * lets the blocks be unioned without a merge decision.
     */
    public function testTheCliBlockIsOneSortedRunBetweenItsMarkers(): void
    {
        $text = (string) \file_get_contents(self::ENGLISH_CATALOGUE);
        $start = \strpos($text, "    // --- cli (W11-a) ---\n");
        $end = \strpos($text, "    // --- end cli (W11-a) ---\n");
        self::assertIsInt($start, 'the cli block lost its opening marker');
        self::assertIsInt($end, 'the cli block lost its closing marker');

        \preg_match_all("/^    '(cli\\.[^']+)' =>/m", \substr($text, $start, $end - $start), $inside);
        \preg_match_all("/^    '(cli\\.[^']+)' =>/m", $text, $everywhere);
        self::assertSame($everywhere[1], $inside[1], 'a cli.* key sits outside the W11-a block');

        $sorted = $inside[1];
        \sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $inside[1], 'the cli block is not sorted');
        self::assertGreaterThan(100, \count($inside[1]));
    }

    /**
     * The help screen is translated as one page — layout included — so it is
     * one entry, and Help::screen() is that entry whole.
     */
    public function testTheHelpScreenIsItsOneCatalogueEntry(): void
    {
        $english = self::english();
        self::assertSame($english['cli.help.screen'], Help::screen());
        self::assertStringStartsWith("SugarCrush — AI coding assistant for the terminal.\n\nUsage:\n", Help::screen());
        self::assertStringEndsWith("  https://github.com/detain/sugarcraft/tree/master/sugar-crush\n", Help::screen());
    }

    /**
     * A message whose program-name prefix stays in code and whose sentence
     * comes from the catalogue renders exactly the line it always did.
     */
    public function testParameterisedMessagesRenderTheirEnglishBytes(): void
    {
        self::assertSame(
            '--root /nope: no such directory',
            Lang::t('cli.argv.root.no_such_directory', ['root' => '/nope']),
        );
        self::assertSame(
            'unexpected arguments: a, b',
            Lang::t('cli.argv.unexpected.many', ['operands' => 'a, b']),
        );
        self::assertSame(
            "Run it? [y/N] ",
            Lang::t('cli.permission.confirm'),
            'the trailing space is the cursor position after the question',
        );
        // A value carrying a placeholder-shaped string is not re-expanded.
        self::assertSame(
            '--config {path}: not readable',
            Lang::t('cli.argv.config.not_readable', ['path' => '{path}']),
        );
    }
}
