<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsDocGenerator;
use SugarCraft\Crush\Config\Settings\SettingsSchema;

/**
 * The generated settings docs are byte-equal to what the schema produces —
 * `php tools/gen-settings-doc.php --check`, as a test (the `ansi/README.md`
 * pattern). A key added to {@see SettingsSchema} without re-running
 * `--write` reds here, naming the stale page.
 */
final class SettingsSchemaDocDriftTest extends TestCase
{
    private static function package(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return array<string, string> the generator's target pages as they are on disk */
    private static function pages(): array
    {
        $pages = [];
        foreach (SettingsDocGenerator::new()->targets() as $file) {
            $text = file_get_contents(self::package() . '/' . $file);
            self::assertIsString($text, "{$file} is unreadable");
            $pages[$file] = $text;
        }

        return $pages;
    }

    public function testTheGeneratedDocsAreUpToDate(): void
    {
        self::assertSame(
            [],
            SettingsDocGenerator::new()->drift(self::pages()),
            'settings docs are stale — run `php tools/gen-settings-doc.php --write` from sugar-crush/',
        );
    }

    public function testTheEveryKeyTableNamesEverySchemaKeyOnce(): void
    {
        $doc = (string) file_get_contents(self::package() . '/' . SettingsDocGenerator::SETTINGS_DOC);
        self::assertSame(1, preg_match('/<!-- settings:begin -->(.*?)<!-- settings:end -->/s', $doc, $block));

        preg_match_all('/^\| `([A-Za-z.]+)` \|/m', $block[1], $rows);
        self::assertSame(SettingsSchema::keys(), $rows[1]);
    }

    /** The ENVIRONMENT.md "Settings key" column is exactly {@see SettingsSchema::envMap()}. */
    public function testTheEnvironmentSettingsKeyColumnMatchesTheSchema(): void
    {
        $doc = (string) file_get_contents(self::package() . '/' . SettingsDocGenerator::ENVIRONMENT_DOC);
        self::assertStringContainsString('| Variable | Default when unset | Description | Settings key |', $doc);

        preg_match_all('/^\| `(SUGARCRUSH_[A-Z0-9_]+)` \|.*\| (`([A-Za-z.]+)`|—) \|$/m', $doc, $rows, \PREG_SET_ORDER);
        $named = [];
        foreach ($rows as $row) {
            if (($row[3] ?? '') !== '') {
                $named[$row[1]] = $row[3];
            }
        }

        ksort($named);
        self::assertSame(SettingsSchema::envMap(), $named);
    }

    public function testAMissingPageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingsDocGenerator::new()->rendered([SettingsDocGenerator::SETTINGS_DOC => '']);
    }

    public function testRegeneratingIsIdempotent(): void
    {
        $generator = SettingsDocGenerator::new();
        $rendered = $generator->rendered(self::pages());
        $once = $rendered[SettingsDocGenerator::ENVIRONMENT_DOC];

        self::assertSame($once, SettingsDocGenerator::withEnvColumn($once));

        $settings = $rendered[SettingsDocGenerator::SETTINGS_DOC];
        self::assertSame($settings, SettingsDocGenerator::replaceBlock($settings, '', $generator->everyKeyTable()));
    }

    /** @return iterable<string, array{string}> */
    public static function badMarkerPages(): iterable
    {
        yield 'no markers' => ['no markers here'];
        yield 'two pairs' => ["<!-- settings:begin -->\n<!-- settings:end -->\n<!-- settings:begin -->\n<!-- settings:end -->"];
        yield 'end before begin' => ["<!-- settings:end -->\n<!-- settings:begin -->"];
    }

    /** A lost or doubled marker is an error, never a guess about where the table goes. */
    #[DataProvider('badMarkerPages')]
    public function testAMissingOrDoubledMarkerPairIsRefused(string $text): void
    {
        $this->expectException(\RuntimeException::class);
        SettingsDocGenerator::replaceBlock($text, '', 'x');
    }

    public function testTheTiersCellReadsTheDefinitionSources(): void
    {
        $tiers = static fn (string $key): string => SettingsDocGenerator::tiers(
            SettingsSchema::byKey($key) ?? throw new \LogicException($key),
        );

        self::assertSame('P U C', $tiers('theme'));
        self::assertSame('U C', $tiers('permissionMode'));
        self::assertSame('C', $tiers('trustedProjectHooks'));
    }
    /**
     * DH-KEYS: the SETTINGS.md layered table, the README layered roster and the
     * env-split sentence are generated blocks, and the hand-written counts
     * beside them are generated anchors — each derived from the schema, which
     * in turn is asserted equal to the LayeredSettings constants.
     */
    public function testTheLayeredBlocksDeriveFromTheSchema(): void
    {
        $generator = SettingsDocGenerator::new();

        preg_match_all('/^\| `([A-Za-z]+(?:\.[A-Za-z0-9]+)*)` \| .+ \| (yes|\*\*no\*\*) \|$/m', $generator->layeredTable(), $rows);
        self::assertEqualsCanonicalizing(LayeredSettings::LAYERED_KEYS, $rows[1]);
        foreach ($rows[1] as $i => $key) {
            self::assertSame(\in_array($key, LayeredSettings::PROJECT_TIER_KEYS, true), $rows[2][$i] === 'yes', $key);
        }

        $roster = $generator->readmeLayeredRoster();
        self::assertStringStartsWith('Only these ' . SettingsDocGenerator::spell(\count(LayeredSettings::LAYERED_KEYS)) . ' keys are layered', $roster);
        preg_match_all('/`([a-z][A-Za-z0-9]*(?:\.[a-z][A-Za-z0-9]*)*)`/', $roster, $named);
        self::assertEqualsCanonicalizing(LayeredSettings::LAYERED_KEYS, $named[1]);

        self::assertEqualsCanonicalizing(LayeredSettings::userTierOnlyKeys(), SettingsDocGenerator::userTierOnlyKeys());
    }

    /**
     * DH-KEYS, W4 half: the LayeredSettings tier constants are generated from the
     * schema, in schema order, so the merge filters on exactly the rows the docs
     * are generated from.
     */
    public function testTheTierConstantsAreGeneratedFromTheSchema(): void
    {
        self::assertSame(SettingsSchema::layeredKeys(), LayeredSettings::LAYERED_KEYS);
        self::assertSame(SettingsSchema::projectTierKeys(), LayeredSettings::PROJECT_TIER_KEYS);
        self::assertArrayHasKey(SettingsDocGenerator::LAYERED_SETTINGS, SettingsDocGenerator::new()->listBlocks());
    }

    public function testAListBlockIsRewrittenBetweenItsMarkersWithTheBeginIndent(): void
    {
        $source = "    const X = [\n        // settings:x:begin — generated\n        'old',\n        // settings:x:end\n    ];";

        self::assertSame(
            "    const X = [\n        // settings:x:begin — generated\n        'a',\n        'b',\n        // settings:x:end\n    ];",
            SettingsDocGenerator::replaceListBlock($source, 'x', ['a', 'b']),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function badListMarkers(): iterable
    {
        yield 'no markers' => ['const X = [];'];
        yield 'two pairs' => ["// settings:x:begin\n// settings:x:end\n// settings:x:begin\n// settings:x:end"];
        yield 'end before begin' => ["// settings:x:end\n// settings:x:begin"];
    }

    #[DataProvider('badListMarkers')]
    public function testAMissingOrDoubledListMarkerIsRefused(string $text): void
    {
        $this->expectException(\RuntimeException::class);
        SettingsDocGenerator::replaceListBlock($text, 'x', ['a']);
    }

    public function testEveryCountAnchorMatchesExactlyOnceOnItsPage(): void
    {
        $pages = self::pages();
        foreach (SettingsDocGenerator::new()->countAnchors() as [$file, $pattern, $count]) {
            self::assertSame(1, preg_match_all($pattern, $pages[$file], $m), "{$file}: {$pattern}");
            self::assertSame(SettingsDocGenerator::spell($count), $m[1][0]);
        }
    }

    public function testACountAnchorThatNoLongerMatchesIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        SettingsDocGenerator::withCount('no anchor here', '/exactly these ([a-z]+)/', 3);
    }

    public function testSpell(): void
    {
        self::assertSame('six', SettingsDocGenerator::spell(6));
        self::assertSame('twenty', SettingsDocGenerator::spell(20));
        self::assertSame('twenty-four', SettingsDocGenerator::spell(24));
        self::assertSame('ninety-nine', SettingsDocGenerator::spell(99));
        self::assertSame('one hundred', SettingsDocGenerator::spell(100));
        self::assertSame('one hundred and four', SettingsDocGenerator::spell(104));
        self::assertSame('one hundred and twenty-one', SettingsDocGenerator::spell(121));
    }

    /** A block inside a list item keeps the item's indentation, so the item does not end early. */
    public function testAnIndentedBlockStaysIndented(): void
    {
        $text = "- item\n  <!-- settings:x:begin -->\n  old\n  <!-- settings:x:end -->\n";

        self::assertSame(
            "- item\n  <!-- settings:x:begin -->\n  new one\n  new two\n  <!-- settings:x:end -->\n",
            SettingsDocGenerator::replaceBlock($text, 'x', "new one\nnew two"),
        );
    }
}
