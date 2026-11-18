<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tests\Config\Support\EnvReadScanner;

/**
 * `docs/KEYBINDINGS.md` and `docs/PROVIDERS.md` are hand-written, because no
 * generator writes either page (PD6 = B). Hand-written reference is only worth
 * having while something holds it to the code, so this test reads both pages
 * back against the two sources they summarise:
 *
 * - every live row of {@see KeyBindingRegistry} must appear on the keys page,
 *   with its id and its key label on one line, since the id is what the
 *   `keybindings` setting names and the label is what a user presses;
 * - every environment variable that {@see ProviderFactory}'s source reads, and
 *   every provider type it offers, must appear on the providers page.
 *
 * The provider oracle is the same token scan the environment roster uses
 * ({@see EnvReadScanner}), run over the factory's own source. A name that only
 * appears in a comment is not a read, so it is not demanded of the page.
 *
 * What this does NOT check: that a described effect still happens. That is
 * {@see \SugarCraft\Code\Tests\Commands\KeyBindingDriftTest}'s job for the keys,
 * and the provider blocks are exercised by their own suites.
 */
final class KeysAndProvidersPageDriftTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function liveBindings(): iterable
    {
        foreach (KeyBindingRegistry::live() as $binding) {
            yield $binding->id => [$binding->id, $binding->keys];
        }
    }

    #[DataProvider('liveBindings')]
    public function testEveryLiveKeyBindingIsOnTheKeysPage(string $id, string $keys): void
    {
        $page = self::page('KEYBINDINGS.md');

        foreach (explode("\n", $page) as $line) {
            if (str_contains($line, '`' . $id . '`') && str_contains($line, $keys)) {
                self::assertTrue(true);
                return;
            }
        }

        self::fail(
            "KeyBindingRegistry declares '{$id}' (keys '{$keys}') but docs/KEYBINDINGS.md has no row "
            . 'naming both. Add the row to the section for its context, with the id and the key label as '
            . 'they appear in the registry.',
        );
    }

    public function testTheProviderFactoryReadsTheEnvironmentNamesThisTestExpects(): void
    {
        // The oracle is only as good as its scan. If the scanner stopped seeing
        // the factory's own reads, every name check below would pass on an
        // empty set, so pin a few known reads first.
        $names = self::factoryEnvNames();

        foreach (['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'OPENROUTER_API_KEY'] as $expected) {
            self::assertContains($expected, $names, "the env scan no longer sees the read of {$expected}");
        }
    }

    public function testEveryEnvironmentVariableProviderFactoryReadsIsOnTheProvidersPage(): void
    {
        $page = self::page('PROVIDERS.md');

        $missing = [];
        foreach (self::factoryEnvNames() as $name) {
            if (!str_contains($page, '`' . $name . '`')) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            'ProviderFactory reads these environment variables, and docs/PROVIDERS.md does not name them '
            . '(as `NAME` in backticks): ' . implode(', ', $missing),
        );
    }

    public function testEveryProviderTypeTheFactoryOffersIsOnTheProvidersPage(): void
    {
        $page = self::page('PROVIDERS.md');

        $missing = [];
        foreach ((new ProviderFactory())->availableTypes() as $type) {
            if (!str_contains($page, '`' . $type . '`')) {
                $missing[] = $type;
            }
        }

        self::assertSame(
            [],
            $missing,
            'ProviderFactory::availableTypes() offers these types, and docs/PROVIDERS.md does not name them '
            . '(as `type` in backticks): ' . implode(', ', $missing),
        );
    }

    public function testTheDocsIndexLinksEveryPageThisTestCovers(): void
    {
        $index = self::page('README.md');

        foreach (['KEYBINDINGS.md', 'PROVIDERS.md', 'THEMES.md', 'GOAL-GRIND.md'] as $page) {
            self::assertStringContainsString(
                '](' . $page . ')',
                $index,
                "docs/README.md must link {$page} from its index table.",
            );
        }
    }

    /**
     * Every environment name the provider factory reads, from the factory's
     * own source. Both channels are included: the `CANDY_CODE_*` reads and the
     * ambient provider-credential reads.
     *
     * @return list<string>
     */
    private static function factoryEnvNames(): array
    {
        $source = self::read('src/Providers/ProviderFactory.php');
        $scanner = new EnvReadScanner(['src/Providers/ProviderFactory.php' => $source]);

        $names = [...array_keys($scanner->reads()), ...array_keys($scanner->ambientReads())];
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private static function page(string $name): string
    {
        return self::read('docs/' . $name);
    }

    /** A file under the candy-code package root, read whole. */
    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $text = @file_get_contents($path);
        self::assertIsString($text, "{$relative} is missing or unreadable");

        return $text;
    }
}
