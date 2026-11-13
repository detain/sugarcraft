<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard: every shipped translation must cover the full English
 * key set with non-empty values, byte-identical {placeholder} multisets, and
 * no residue of the retired product name. This is the genre that caught the
 * era when the 15 non-en locales each carried 1 translated key of 21.
 */
final class LangParityTest extends TestCase
{
    /** @return array<string, array<string,string>> */
    private static function allLocales(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
            $out[basename($file, '.php')] = include $file;
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> */
    private static function nonEnglish(): array
    {
        return array_values(array_filter(
            array_keys(self::allLocales()),
            static fn(string $loc): bool => $loc !== 'en',
        ));
    }

    public static function localeProvider(): array
    {
        $rows = [];
        foreach (self::nonEnglish() as $locale) {
            $rows[$locale] = [$locale];
        }
        return $rows;
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleCarriesExactlyTheEnglishKeySet(string $locale): void
    {
        $locales = self::allLocales();
        $english = array_keys($locales['en']);
        sort($english);
        $keys = array_keys($locales[$locale]);
        sort($keys);
        $this->assertSame(
            $english,
            $keys,
            "locale {$locale} diverges from the English key set",
        );
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleValuesAreTranslatedAndPlaceholderFaithful(string $locale): void
    {
        $locales = self::allLocales();
        foreach ($locales[$locale] as $key => $value) {
            $this->assertIsString($value, "{$locale}/{$key} is not a string");
            $this->assertNotSame('', trim($value), "{$locale}/{$key} is empty");
            preg_match_all('/\{([a-z_]+)\}/', $value, $mine);
            preg_match_all('/\{([a-z_]+)\}/', $locales['en'][$key], $theirs);
            sort($mine[1]);
            sort($theirs[1]);
            $this->assertSame(
                $theirs[1],
                $mine[1],
                "{$locale}/{$key} mutates the placeholder set",
            );
        }
    }

    public function testTheRetiredProductNameIsGoneFromEveryString(): void
    {
        foreach (self::allLocales() as $locale => $messages) {
            foreach ($messages as $key => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\btick\b/i',
                    $value,
                    "{$locale}/{$key} still speaks of a 'tick'",
                );
            }
        }
    }

    /**
     * The eight `cli.usage` command rows, in file order. The verb names and
     * argument syntaxes are deliberately untranslated, so they address the
     * rows identically in every locale.
     *
     * @return array<string, array<string,string>> locale => verb => row
     */
    private static function usageRows(): array
    {
        $rows = [];
        foreach (self::allLocales() as $locale => $messages) {
            $lines = explode("\n", (string) $messages['cli.usage']);
            $start = null;
            foreach ($lines as $index => $line) {
                if (str_starts_with($line, '  dashboard')) {
                    $start = $index;
                    break;
                }
            }
            self::assertNotNull($start, "locale {$locale}: usage lost its command block");
            $described = [];
            // Block-bounded, not a fixed row count (CT-S42): the command
            // block ends at the first blank line, so a new verb rides the
            // guard without a re-pin.
            foreach (array_slice($lines, (int) $start) as $line) {
                if (trim($line) === '') {
                    break;
                }
                $verb = explode(',', substr($line, 2))[0];
                $described[trim(explode(' ', trim($verb))[0])] = $line;
            }
            $rows[$locale] = $described;
        }
        return $rows;
    }

    /**
     * Where a row's untranslated command column ends and its translated
     * description begins, per verb. Rows without syntax start right after the
     * fixed 25-cell name column.
     *
     * @return array<string, string> verb => syntax (empty when the row has none)
     */
    private const USAGE_SYNTAX = [
        'dashboard' => '',
        'push' => '[<project> <language>] <file> [duration] [tags] | --',
        'milestone' => 'add <name> [description] | list',
        'export' => '<csv|json|ics> [days | from [to]]',
        'gaps' => '[days | from [to]] [--min=<secs>]',
        'summary' => '[days | from [to]] [--format=text|md]',
        'backup' => '',
        'forget' => '--project=<name> [--file-prefix=<path>] [--apply]',
        'help' => '',
        '--version' => '',
    ];

    /**
     * The shape that survived the 2026-11 audit: 15 of the 16 locales had
     * their whole description column slid up one row, so `dashboard` printed
     * push's help and `--version` printed the `--backend` default fragment.
     * Key sets, placeholder multisets and non-emptiness all stayed green
     * through it — so alignment needs its own pin. The anchors are the parts
     * of a description a translation cannot drop (the seconds threshold, the
     * archive age, the export window, the product word in the dashboard row),
     * which is precisely what a rotation moves onto the wrong verb.
     */
    public function testEveryLocaleDescribesTheCommandItsRowNames(): void
    {
        $anchors = [
            'export' => '7',
            'gaps' => '300',
            'backup' => '30',
            'dashboard' => 'TUI',
        ];
        // The two help/version rows have no numbers of their own, and the row
        // that the rotation left them with does: the `--backend` default reads
        // "(default: file)" in every locale.
        $digitless = ['forget', 'help', '--version'];

        foreach (self::usageRows() as $locale => $commands) {
            $this->assertSame(
                ['dashboard', 'push', 'milestone', 'export', 'gaps', 'summary', 'backup', 'forget', 'help', '--version'],
                array_keys($commands),
                "locale {$locale}: the usage command rows drifted out of shape",
            );
            foreach ($anchors as $verb => $needle) {
                $this->assertStringContainsString(
                    $needle,
                    self::description($commands[$verb], $verb),
                    "locale {$locale}: the '{$verb}' row no longer carries its own wording",
                );
            }
            foreach ($digitless as $verb) {
                $this->assertMatchesRegularExpression(
                    '/^[^0-9:]+$/u',
                    self::description($commands[$verb], $verb),
                    "locale {$locale}: the '{$verb}' row carries digits or a default value — a slid row",
                );
            }
        }
    }

    /**
     * A translation may not invent, drop or reorder a documented spelling: the
     * command column (verb aliases plus argument syntax) stays English in all
     * 16 files, so `push` can never advertise the milestone arguments again.
     */
    public function testEveryLocaleAdvertisesTheEnglishCommandColumn(): void
    {
        $rows = self::usageRows();
        foreach ($rows as $locale => $commands) {
            foreach (self::USAGE_SYNTAX as $verb => $syntax) {
                $column = self::commandColumn($commands[$verb], $syntax);
                $this->assertSame(
                    self::commandColumn($rows['en'][$verb], $syntax),
                    $column,
                    "locale {$locale}: the '{$verb}' command column diverges from English",
                );
            }
        }
    }

    /** The untranslated name + syntax prefix of a row, padded cells included. */
    private static function commandColumn(string $line, string $syntax): string
    {
        $width = 25 + ($syntax === '' ? 0 : strlen($syntax) + 2);
        return substr($line, 0, $width);
    }

    /** The translated tail of a row, command column stripped. */
    private static function description(string $line, string $verb): string
    {
        $syntax = self::USAGE_SYNTAX[$verb] ?? null;
        self::assertNotNull($syntax, "test fixture names an undocumented verb: {$verb}");
        return substr($line, strlen(self::commandColumn($line, $syntax)));
    }

    public function testSixteenLocalesShip(): void
    {
        $this->assertCount(16, self::allLocales());
    }
}
