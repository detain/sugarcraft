<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;

/**
 * N-18 (docs-reviews-1104): the candy-code story is told on FOUR surfaces the
 * lib's own guards never read — the monorepo root README.md product-table row,
 * docs/MATCHUPS.md status row, docs/_data/candy-code.json metadata and
 * docs/_data/candy-code.body.html site body. All four carried a stale
 * "nine providers / thirty-one tools" figure while the shipped README had long
 * moved on, because every existing documentation guard (DocFigureProseDrift,
 * ReadmeRosterDrift and friends) derives only from candy-code/README.md and
 * candy-code/docs/. This class closes the gap: it re-derives the live provider
 * type keys from ProviderFactory::availableTypes(), the distinct engine
 * classes the factory itself returns or constructs, the per-launch tool count
 * from ToolCatalog::builtUnconditionally() and the built-in skill count from
 * the SKILL.md files under src/Skills/BuiltIn/, then pins each spelled or
 * numeric figure on every root surface to those live values — so a new key,
 * engine, tool or skill must move the root pages in the same commit that adds
 * it, exactly like the lib-local arms already force for the shipped README.
 *
 * The root paths resolve from the repository root (dirname(__DIR__, 3)) and
 * are asserted present fail-closed, matching the monorepo-tier precedent in
 * tests/Skills/BuiltInSkillsTest.php — a run without them is broken checkout,
 * not a state to skip quietly.
 */
final class RootSurfaceDriftTest extends TestCase
{
    /**
     * Spelled counts understood on the root surfaces, lowercase; the
     * card-heading arm ucfirst()s what it compares.
     *
     * @var array<string, int>
     */
    private const COUNT_WORDS = [
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
        'thirteen' => 13,
        'fourteen' => 14,
        'thirty-six' => 36,
        'thirty-seven' => 37,
        'thirty-eight' => 38,
        'thirty-nine' => 39,
        'forty' => 40,
        'forty-one' => 41,
        'forty-two' => 42,
        'forty-three' => 43,
        'forty-four' => 44,
    ];

    /**
     * @return array<string, int>
     */
    private static function liveFigures(): array
    {
        $factorySource = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Providers/ProviderFactory.php');

        $engines = [];
        if (preg_match_all('/function create\w+\(array \$config\): (\w*Provider)\b/', $factorySource, $typed) === false) {
            self::fail('the ProviderFactory source no longer lexes — re-pin this derivation with the file');
        }
        foreach ($typed[1] as $class) {
            $engines[$class] = true;
        }
        if (preg_match_all('/new (\w*Provider)\(/', $factorySource, $built) === false) {
            self::fail('the ProviderFactory construction shape no longer lexes — re-pin this derivation with the file');
        }
        foreach ($built[1] as $class) {
            $engines[$class] = true;
        }

        // chr(42) concatenation: a bare '*' inside one string literal here would
        // be harvested as a glob-shaped token and drift the PathGlob corpus figure.
        $skillManifests = glob(\dirname(__DIR__, 2) . '/src/Skills/BuiltIn/' . \chr(42) . '/SKILL.md');
        self::assertIsArray($skillManifests, 'the built-in skill tree is missing — this is not a skip');

        return [
            'typeKeys' => \count((new ProviderFactory())->availableTypes()),
            'engines' => \count($engines),
            'tools' => \count(ToolCatalog::builtUnconditionally()),
            'skills' => \count($skillManifests),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function rootSurfaces(): array
    {
        $repo = \dirname(__DIR__, 3);
        foreach (['/README.md', '/docs/MATCHUPS.md', '/docs/_data/candy-code.json', '/docs/_data/candy-code.body.html'] as $relative) {
            self::assertFileExists($repo . $relative, "the root surface {$relative} is missing from the repository root");
        }

        return [
            (string) file_get_contents($repo . '/README.md'),
            (string) file_get_contents($repo . '/docs/MATCHUPS.md'),
            (string) file_get_contents($repo . '/docs/_data/candy-code.json'),
            (string) file_get_contents($repo . '/docs/_data/candy-code.body.html'),
        ];
    }

    /**
     * @param  array<string, int>  $figures
     */
    private static function spelledFigure(string $word, int $live, string $what): void
    {
        // The caller extracted the word from the surface itself via regex, so
        // containment needs no re-check; the map and the live count are the pin.
        self::assertArrayHasKey($word, self::COUNT_WORDS, "unknown count word '{$word}' pinning {$what} — the arm's map needs the new spelling");
        self::assertSame(
            $live,
            self::COUNT_WORDS[$word],
            "the root surface now spells {$what} as '{$word}' while the live figure is {$live} — flip the page and this pin together"
        );
    }

    public function testTheRootReadmeCandyCodeRowSpellsTheLiveProviderAndToolCounts(): void
    {
        [$readme] = self::rootSurfaces();
        $figures = self::liveFigures();

        self::assertSame(
            1,
            preg_match('/\*\*\[CandyCode\]\(candy-code\/\)\*\*[^\n]*/', $readme, $row),
            'the root README no longer carries the CandyCode product-table row'
        );
        $line = $row[0];

        // SD-S20 shortened the intro-table gist cell: the row now spells the
        // engine and tool counts only ("nine provider engines, thirty-eight
        // built-in tools"); the type-key count and the parenthesised engine
        // roster live on MATCHUPS.md and the site page, pinned below.
        self::assertSame(
            1,
            preg_match('/([a-z-]+) provider engines/', $line, $m),
            'the CandyCode row no longer spells its engine figure — re-pin this arm with the page'
        );
        self::spelledFigure($m[1], $figures['engines'], 'the engine count');

        self::assertSame(
            1,
            preg_match('/([a-z-]+) built-in tools/', $line, $tools),
            'the CandyCode row no longer spells its tool count — re-pin this arm with the page'
        );
        self::spelledFigure($tools[1], $figures['tools'], 'the per-launch tool count');
    }

    public function testTheMatchupsRowCarriesTheSameFiguresWithDigits(): void
    {
        [, $matchups] = self::rootSurfaces();
        $figures = self::liveFigures();

        self::assertSame(
            1,
            preg_match('/\*\*CandyCode\*\* \(`candy-code\/`\)[^\n]*/', $matchups, $row),
            'MATCHUPS.md no longer carries the CandyCode status row'
        );
        $line = $row[0];

        self::assertSame(
            1,
            preg_match('/([a-z-]+) provider type keys over ([a-z-]+) engines, (\d+) built-in tools per launch/', $line, $m),
            'the MATCHUPS CandyCode row no longer spells its keys/engines and digits its tool count — re-pin this arm with the page'
        );
        self::spelledFigure($m[1], $figures['typeKeys'], 'the MATCHUPS provider type-key count');
        self::spelledFigure($m[2], $figures['engines'], 'the MATCHUPS engine count');
        self::assertSame($figures['tools'], (int) $m[3], 'the MATCHUPS CandyCode row miscounts the per-launch tools');
    }

    public function testTheDataJsonDescriptionsCarryTheSameFigures(): void
    {
        [, , $json] = self::rootSurfaces();
        $figures = self::liveFigures();

        $data = json_decode($json, true);
        self::assertIsArray($data, 'docs/_data/candy-code.json does not parse');
        self::assertIsString($data['description'] ?? null, 'docs/_data/candy-code.json lost its description');
        self::assertIsString($data['ogDescription'] ?? null, 'docs/_data/candy-code.json lost its ogDescription');

        foreach (['description', 'ogDescription'] as $field) {
            self::assertSame(
                1,
                preg_match('/([a-z-]+) provider type keys over ([a-z-]+) engines, (\d+) built-in tools per launch/', $data[$field], $m),
                "the candy-code site {$field} no longer spells its provider figures and digits its tool count — fix the upstream _data, not the generated page"
            );
            self::spelledFigure($m[1], $figures['typeKeys'], "the {$field} provider type-key count");
            self::spelledFigure($m[2], $figures['engines'], "the {$field} engine count");
            self::assertSame($figures['tools'], (int) $m[3], "the candy-code site {$field} miscounts the per-launch tools");
        }

        self::assertSame(
            $data['description'],
            'CandyCode: ' . $data['ogDescription'],
            'the candy-code description and ogDescription drifted apart — they tell the same figure sentence'
        );
    }

    public function testTheDataBodyHtmlEnumeratesTheProviderKeysAndSpellsTheCardCounts(): void
    {
        [, , , $body] = self::rootSurfaces();
        $figures = self::liveFigures();

        $types = (new ProviderFactory())->availableTypes();
        $enumerated = '<code>' . implode('</code>, <code>', \array_slice($types, 0, -1)) . '</code> or <code>' . $types[\count($types) - 1] . '</code>';
        self::assertStringContainsString(
            'accepts ' . $enumerated . ' — ',
            $body,
            'docs/_data/candy-code.body.html misenumerates the CANDY_CODE_PROVIDER type keys (factory order, code-tagged)'
        );

        self::assertSame(
            1,
            preg_match('/([A-Z][a-z-]+) type keys, ([a-z-]+) engines/', $body, $card),
            'the provider card heading no longer spells its key and engine counts — re-pin this arm with the page'
        );
        self::spelledFigure(strtolower($card[1]), $figures['typeKeys'], 'the provider card key count');
        self::spelledFigure($card[2], $figures['engines'], 'the provider card engine count');

        self::assertSame(
            1,
            preg_match('/([A-Z][a-z-]+) built-in tools on every launch/', $body, $tools),
            'the tools card heading no longer spells its count — re-pin this arm with the page'
        );
        self::spelledFigure(strtolower($tools[1]), $figures['tools'], 'the tools card per-launch count');

        self::assertSame(
            1,
            preg_match('/([A-Z][a-z-]+) built-ins/', $body, $skills),
            'the skills card no longer spells its built-in count — re-pin this arm with the page'
        );
        self::spelledFigure(strtolower($skills[1]), $figures['skills'], 'the skills card built-in count');
    }
}
