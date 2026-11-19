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
        if (self::keysRowMatches(self::page('KEYBINDINGS.md'), $id, $keys)) {
            self::assertTrue(true);
            return;
        }

        self::fail(
            "KeyBindingRegistry declares '{$id}' (keys '{$keys}') but docs/KEYBINDINGS.md has no row "
            . 'naming both. Add the row to the section for its context, with the id and the key label as '
            . 'they appear in the registry.',
        );
    }

    /**
     * The row must carry the id and the key label in their own Id and Keys
     * cells. A bare substring test passes when the label is a single character
     * found elsewhere in the row (`e` in "session" for `p`), so the cells are
     * matched whole.
     */
    private static function keysRowMatches(string $page, string $id, string $keys): bool
    {
        $row = '| `' . $id . '` | `' . $keys . '` |';

        foreach (explode("\n", $page) as $line) {
            if (str_starts_with($line, $row)) {
                return true;
            }
        }

        return false;
    }

    public function testTheKeysRowMatcherRefusesAWrongLabelInsideAnotherCell(): void
    {
        // The page documents picker.pin as `p`. A registry that moved it to `e`
        // must not be satisfied by the `e` in the row's description.
        $page = "| `picker.pin` | `p` | Pin or unpin the session (or Ctrl+F) |\n";

        self::assertTrue(self::keysRowMatches($page, 'picker.pin', 'p'));
        self::assertFalse(self::keysRowMatches($page, 'picker.pin', 'e'));
        self::assertFalse(self::keysRowMatches($page, 'picker.pin', 'Ctrl+F'));
    }

    public function testEveryIdOnTheKeysPageIsARegistryRow(): void
    {
        // The forward check above only catches a registry row the page lacks.
        // A row the registry dropped or renamed would otherwise stay on the
        // page, documenting a key nothing answers.
        $known = array_map(static fn($binding): string => $binding->id, KeyBindingRegistry::all());

        $unknown = [];
        foreach (explode("\n", self::page('KEYBINDINGS.md')) as $line) {
            if (preg_match('/^\| `([^`]+)` \| `/', $line, $match) === 1 && !in_array($match[1], $known, true)) {
                $unknown[] = $match[1];
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($unknown)),
            'docs/KEYBINDINGS.md names these ids in its Keys tables, and KeyBindingRegistry::all() does not declare them',
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

        // AWS_REGION is read through REQUIRED_KEY_ENVS, not a literal getenv(),
        // so the scan cannot see it. It must still be demanded of the page.
        self::assertContains('AWS_REGION', $names, 'the bedrock region variable is no longer in the factory env oracle');
    }

    public function testTheTableRowMatcherRefusesANameOnlyInProse(): void
    {
        // A variable named in a sentence is not documented in the env table.
        // The check must take the table row, not the sentence, as the entry.
        $page = "Set `AZURE_OPENAI_API_KEY` for azure.\n| `OPENAI_API_KEY` | `openai` | The key. | empty |\n";

        $rows = self::tableRowNames($page);
        self::assertContains('OPENAI_API_KEY', $rows);
        self::assertNotContains('AZURE_OPENAI_API_KEY', $rows);
    }

    public function testEveryEnvironmentVariableProviderFactoryReadsIsOnTheProvidersPage(): void
    {
        // Demanded as the first cell of a table row, so deleting the env-table
        // row fails even when the name is still mentioned in prose.
        $rows = self::tableRowNames(self::page('PROVIDERS.md'));

        $missing = [];
        foreach (self::factoryEnvNames() as $name) {
            if (!in_array($name, $rows, true)) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            'ProviderFactory reads these environment variables, and docs/PROVIDERS.md has no table row for them '
            . '(a row whose first cell is `NAME`): ' . implode(', ', $missing),
        );
    }

    public function testEveryProviderTypeTheFactoryOffersIsOnTheProvidersPage(): void
    {
        // Demanded as the first cell of a table row, for the same reason as the
        // environment check: a type named only in prose is not in the type table.
        $rows = self::tableRowNames(self::page('PROVIDERS.md'));

        $missing = [];
        foreach ((new ProviderFactory())->availableTypes() as $type) {
            if (!in_array($type, $rows, true)) {
                $missing[] = $type;
            }
        }

        self::assertSame(
            [],
            $missing,
            'ProviderFactory::availableTypes() offers these types, and docs/PROVIDERS.md has no table row for them '
            . '(a row whose first cell is `type`): ' . implode(', ', $missing),
        );
    }

    /**
     * The first-cell names of a page's table rows, as `| `NAME` |`. Only a
     * row at the start of a line counts, so a name mentioned inside a sentence
     * or a later cell does not satisfy the check.
     *
     * @return list<string>
     */
    private static function tableRowNames(string $page): array
    {
        preg_match_all('/^\| `([^`]+)` \|/m', $page, $matches);

        return array_values(array_unique($matches[1]));
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

        // An env read the scanner cannot place is absent from reads() and
        // ambientReads(), so the oracle would silently miss it. Refuse here.
        self::assertSame(
            [],
            $scanner->unresolved(),
            'ProviderFactory has an environment read the env scanner cannot place; the env oracle would miss it',
        );

        $names = [
            ...array_keys($scanner->reads()),
            ...array_keys($scanner->ambientReads()),
            ...self::requiredKeyEnvNames(),
        ];
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The environment names the factory's missing-key refusals point at. The
     * factory reads them through its REQUIRED_KEY_ENVS map rather than a literal
     * getenv(), so the token scan never sees them; the map itself is the source.
     *
     * @return list<string>
     */
    private static function requiredKeyEnvNames(): array
    {
        $map = (new \ReflectionClassConstant(ProviderFactory::class, 'REQUIRED_KEY_ENVS'))->getValue();

        $names = [];
        foreach ($map as $keys) {
            foreach ($keys as $env) {
                $names[] = $env;
            }
        }

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
