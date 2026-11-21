<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\MCP\Serve\McpServeRoster;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * `docs/_data/candy-code.body.html` is the public candy-code page. It is
 * generated into `docs/lib/candy-code.html`, and nothing on the generator side
 * reads its factual claims. Two of the three errors that reached the live site
 * (a method named `getStatus()` that does not exist, and a Key Classes row
 * pointing at the wrong method) were claims about source that no test could
 * see.
 *
 * THIS CLASS CHECKS THE BODY'S CLAIMS AGAINST SOURCE, in four legs:
 *
 * - Every `Class` and `method()` named in the Key Classes table resolves to a
 *   class under `candy-code/src` and to a method that class declares. The
 *   table is the only structured class reference in the body; the inline
 *   `Class::method` scan is there so a future prose reference is held to the
 *   same rule.
 * - Every class-shaped `<code>` token in the body (`…Hook`, `…Engine`, `…Gate`
 *   and the other suffixes below) names a class that exists in `src`.
 * - Every spelled permission-mode count in the body equals the
 *   {@see PermissionMode} case count, and the mode list the Key Classes table
 *   gives for `PermissionGate::mode()` is exactly the enum's values.
 * - Every hook-event or locale count the body quotes equals its live figure.
 *   The body quotes neither today; the leg is there so the first such figure
 *   is pinned when it lands.
 *
 * The provider type keys, engines, per-launch tools and built-in skills are
 * pinned by {@see RootSurfaceDriftTest} and
 * {@see EnvRosterDriftTest}, which read the same body, so they are not repeated
 * here.
 *
 * Class resolution reads the declarations under `src/` rather than trusting
 * the autoloader alone: a short name must map to exactly one declaration, or
 * the test reports the ambiguity instead of guessing which class the page
 * meant.
 *
 * @internal
 */
final class CandyCodeBodyClaimDriftTest extends TestCase
{
    private const BODY = __DIR__ . '/../../../docs/_data/candy-code.body.html';
    private const COMPARISON = __DIR__ . '/../../COMPARISON.md';
    private const SOURCE = __DIR__ . '/../../src';
    private const LANG = __DIR__ . '/../../lang';
    private const EXAMPLES = __DIR__ . '/../../examples';
    private const VHS = __DIR__ . '/../../.vhs';

    /**
     * Class-shaped names the body puts in `<code>`: a bare name, a namespaced
     * path (`Tools\Tool`), or either followed by `()` (`ConfirmRemoveHook()`).
     * {@see self::classTokens()} keeps a token only when it is namespaced or
     * ends in one of the suffixes below; a bare `Bash` or `Read` is a tool
     * name, not a class, and is deliberately not matched.
     */
    private const CLASS_TOKEN = '#<code>((?:[A-Z]\w*\\\\)*[A-Z]\w*)(?:\(\))?</code>#';

    /** The naming conventions the source already follows for classes. */
    private const CLASS_SUFFIX = '#(?:Hook|Interface|Engine|Backend|Gate|Store|Manager|Registry|Client|Preset|Factory|Mode)$#';

    /** @var array<string, int> */
    private const COUNT_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
        'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
        'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80,
        'ninety' => 90,
    ];

    /**
     * The tens and units of a compound count, hyphenated ("twenty-six") or
     * spaced ("twenty six"). The compound is matched whole and valued as one
     * number: read as bare words, "twenty-six" would surface as "six" and
     * "thirty one" as "one".
     */
    private const COMPOUND_TENS = 'twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety';
    private const COMPOUND_UNITS = 'one|two|three|four|five|six|seven|eight|nine';

    /** @var array<string, list<string>>|null short name => declaring FQNs */
    private static ?array $declarations = null;

    public function testKeyClassesTableNamesOnlyClassesAndMethodsThatExistInSource(): void
    {
        self::assertSame(
            1,
            preg_match('#<h3 id="key-classes"[^>]*>.*?<table class="api-table">(.*?)</table>#s', self::body(), $table),
            'the candy-code body no longer carries the Key Classes table under its key-classes heading'
        );

        $rows = self::keyClassRows($table[1]);
        self::assertNotSame([], $rows, 'the Key Classes table parsed to no rows — the row shape changed, re-pin this scan');

        $missing = [];
        $checked = 0;
        foreach ($rows as [$short, $methods]) {
            $fqn = self::declaredClass($short, $missing);
            if ($fqn === null) {
                continue;
            }

            self::assertMethodList($fqn, strip_tags($methods), $missing);
            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'no Key Classes row resolved to a class — the source scan is broken');
        self::assertSame([], $missing, 'the candy-code body names a class or method that the source does not declare: ' . implode('; ', $missing));
    }

    public function testInlineClassMethodReferencesInTheBodyExist(): void
    {
        $missing = [];
        preg_match_all('/\b([A-Z]\w*)::([a-z]\w*)\b/', strip_tags(self::body()), $refs, PREG_SET_ORDER);

        foreach ($refs as $ref) {
            $fqn = self::declaredClass($ref[1], $missing);
            if ($fqn !== null && self::publicMethodMissing($fqn, $ref[2])) {
                $missing[] = "{$fqn}::{$ref[2]}()";
            }
        }

        self::assertSame([], $missing, 'the candy-code body cites a Class::method the source does not declare: ' . implode('; ', $missing));
    }

    public function testEveryClassShapedCodeTokenInTheBodyNamesAShippedClass(): void
    {
        $names = self::classTokens(self::body());
        self::assertNotEmpty($names, 'the body no longer carries any class-shaped <code> token — the pattern no longer matches the page');

        $missing = [];
        foreach ($names as $short) {
            self::declaredClass($short, $missing);
        }

        self::assertSame([], $missing, 'the candy-code body names a class-shaped token with no class behind it: ' . implode('; ', $missing));
    }

    public function testEverySpelledPermissionModeCountInTheBodyMatchesTheEnum(): void
    {
        $body = strip_tags(self::body());
        $live = \count(PermissionMode::cases());

        $quoted = self::quotedPermissionModeCounts($body);
        self::assertNotEmpty($quoted, 'the body no longer quotes a permission-mode count — re-pin this leg with the page');

        foreach ($quoted as $word) {
            self::assertSame(
                $live,
                self::countValue($word),
                "the candy-code body quotes '{$word}' permission modes while the PermissionMode enum has {$live}"
            );
        }

        self::assertSame(
            1,
            preg_match('#<tr><td>(?:<code>)?[\w\\\\]*PermissionGate(?:</code>)?</td><td>(?:<code>)?mode\(\)[^<]*(?:</code>)?</td><td>(.*?)</td></tr>#s', self::body(), $row),
            'the Key Classes table no longer lists PermissionGate::mode() — re-pin the mode list leg'
        );
        preg_match_all('#<code>([^<]+)</code>#', $row[1], $listed);

        $values = array_map(static fn (PermissionMode $mode): string => $mode->value, PermissionMode::cases());
        sort($values);
        $named = array_values(array_unique($listed[1]));
        sort($named);
        self::assertSame($values, $named, 'the PermissionGate::mode() row names a mode list that differs from the PermissionMode enum');
    }

    public function testEveryHookEventOrLocaleCountTheBodyQuotesMatchesItsGenerator(): void
    {
        $live = [
            'hook event' => \count(HookEvent::cases()),
            'locale' => \count(glob(self::LANG . '/*.php') ?: []),
        ];
        self::assertGreaterThan(0, $live['locale'], 'the lang catalog directory is empty or unreadable');

        foreach (self::quotedNounCounts(strip_tags(self::body()), 'hook events?|locales?') as [$word, $noun]) {
            $kind = str_starts_with(strtolower($noun), 'hook') ? 'hook event' : 'locale';
            self::assertSame(
                $live[$kind],
                self::countValue($word),
                "the candy-code body quotes '{$word} {$noun}' while the live count is {$live[$kind]}"
            );
        }
    }

    /**
     * Every candy-code hook-event count on COMPARISON.md is pinned, not just the
     * one comparison phrase. Each spelling below is anchored to its row or
     * sentence, because the page also quotes other events (Claude Code's 33,
     * the serve protocol's 38 event types, OpenCode's 35): a bare "N events"
     * pattern would call those defects. Each label must match at least once, so
     * a rewritten sentence cannot drop out of the check silently.
     *
     * @return array<string, string> label => pattern with the count as group 1
     */
    private static function comparisonHookEventPatterns(): array
    {
        $count = '(' . self::countPattern() . ')';

        return [
            'comparison against Claude Code' => '/\b' . $count . '\s+hook events\s+vs\s+Claude Code/i',
            'breadth bullet' => '/\bhooks \(' . $count . ' events;/i',
            'HK-02 scoreboard row' => '/\|\s*HK-02\s*\|[^\n]*?✅\s*' . $count . ' events:/u',
            'HK-02 detail bullet' => '/\bAll ' . $count . ' events have production dispatch sites/i',
            'hook comparison table' => '/\|\s*Events\s*\|\s*' . $count . ' \(Pre\/PostToolUse/i',
        ];
    }

    public function testTheComparisonPageQuotesTheLiveHookEventCountForCandyCode(): void
    {
        $text = (string) file_get_contents(self::COMPARISON);
        $live = \count(HookEvent::cases());

        foreach (self::comparisonHookEventPatterns() as $label => $pattern) {
            preg_match_all($pattern, $text, $found);
            self::assertNotEmpty($found[1], "COMPARISON.md no longer states the candy-code hook event count in its {$label}");

            foreach ($found[1] as $word) {
                self::assertSame(
                    $live,
                    self::countValue($word),
                    "COMPARISON.md quotes '{$word}' hook events in its {$label} while the HookEvent enum has {$live}"
                );
            }
        }
    }

    /**
     * REGRESSION: each pinned spelling must read a stale figure. Without this, a
     * pattern that stopped matching its row would leave the live assertion above
     * checking nothing, and the page could keep "16" in that row forever.
     */
    public function testEachComparisonHookCountSpellingReadsAStaleFigure(): void
    {
        $fixtures = [
            'comparison against Claude Code' => '15 hook events vs Claude Code',
            'breadth bullet' => '- **Breadth.** hooks (15 events; command, HTTP and prompt kinds fire)',
            'HK-02 scoreboard row' => '| HK-02 | Events supported (list them) | ✅ 15 events: Pre/PostToolUse, Stop |',
            'HK-02 detail bullet' => '- **candy-code** ✅ — All 15 events have production dispatch sites; UserPromptSubmit',
            'hook comparison table' => '| Events | 15 (Pre/PostToolUse, Stop, SubagentStop) | 33 |',
        ];

        $patterns = self::comparisonHookEventPatterns();
        self::assertSame(array_keys($patterns), array_keys($fixtures), 'the pinned hook-count spellings and their fixtures have drifted apart');

        foreach ($fixtures as $label => $line) {
            self::assertSame(1, preg_match($patterns[$label], $line, $found), "the {$label} pattern no longer reads its row");
            self::assertSame(15, self::countValue($found[1]), "the {$label} pattern does not read the count it captures");
        }
    }

    public function testTheMcpServeRosterOnThePageIsTheRosterTheServerServes(): void
    {
        self::assertSame(
            1,
            preg_match('/read-only tool roster \(([^)]*)\)/', strip_tags(self::body()), $listed),
            'the body no longer names the mcp serve read-only roster — re-pin this leg with the page'
        );

        $named = array_map('trim', explode(',', $listed[1]));
        $served = McpServeRoster::NAMES;
        sort($named);
        sort($served);
        self::assertSame($served, $named, 'the body names an MCP read-only roster that differs from McpServeRoster::NAMES');
    }

    public function testEveryShippedExampleHasADemoGifOnThePage(): void
    {
        $examples = array_map('basename', glob(self::EXAMPLES . '/*.php') ?: []);
        self::assertNotEmpty($examples, 'the examples directory is empty or unreadable');

        // The claim is scoped to the PHP examples, and the glob above is too. The
        // workflow YAML under examples/workflows/ has no tape, so an unqualified
        // "every example" would be false; the scope sentence must stay on the page.
        self::assertStringContainsString(
            'every PHP example shipped with the app',
            self::body(),
            'the body no longer scopes its demo claim to the PHP examples this leg checks'
        );
        self::assertStringContainsString(
            '<code>examples/workflows/</code> ships without a recording',
            self::body(),
            'the body no longer says the workflow YAML ships without a recording'
        );

        $tapes = glob(self::VHS . '/*.tape') ?: [];
        $tapeText = implode("\n", array_map(static fn (string $tape): string => (string) file_get_contents($tape), $tapes));

        $unrecorded = [];
        foreach ($examples as $example) {
            if (!str_contains($tapeText, 'examples/' . $example)) {
                $unrecorded[] = $example;
            }
        }
        self::assertSame([], $unrecorded, 'the body says every shipped example has a demo, but no .vhs tape runs these: ' . implode(', ', $unrecorded));

        preg_match_all('#\.vhs/([\w-]+)\.gif#', self::body(), $shown);
        $gifs = array_values(array_unique($shown[1]));
        $recorded = array_map(static fn (string $tape): string => basename($tape, '.tape'), $tapes);
        sort($gifs);
        sort($recorded);
        self::assertSame($recorded, $gifs, 'the demo grid on the page does not show exactly one GIF per .vhs tape');
    }

    public function testHyphenatedCompoundCountsAreReadWholeAndValuedAsOneNumber(): void
    {
        self::assertSame(
            ['twenty-six', 'forty-two'],
            self::quotedPermissionModeCounts('twenty-six permission modes, forty-two modes')
        );
        self::assertSame(
            [['twenty-one', 'hook events'], ['thirty-one', 'locales'], ['one', 'locale']],
            self::quotedNounCounts('twenty-one hook events, thirty-one locales, one locale', 'hook events?|locales?')
        );

        // The spaced spelling is a compound too: "Twenty six permission modes" is 26, not the unit "six".
        self::assertSame(
            ['Twenty six', 'forty two'],
            self::quotedPermissionModeCounts('Twenty six permission modes, forty two modes')
        );
        self::assertSame(
            [['Twenty one', 'hook events'], ['forty two', 'hook events']],
            self::quotedNounCounts('Twenty one hook events and forty two hook events', 'hook events?|locales?')
        );

        self::assertSame(21, self::countValue('twenty-one'));
        self::assertSame(26, self::countValue('Twenty-Six'));
        self::assertSame(21, self::countValue('twenty one'));
        self::assertSame(26, self::countValue('Twenty Six'));
        self::assertSame(30, self::countValue('thirty'));
        self::assertSame(-1, self::countValue('one-two'), 'only a tens word takes a hyphenated unit');
        self::assertSame(-1, self::countValue('one two'), 'only a tens word takes a spaced unit');
    }

    public function testClassTokenScanReadsNamespacedAndCallFormTokensAndResolvesThemAgainstTheirNamespace(): void
    {
        self::assertSame(
            ['Tools\Tool', 'ConfirmRemoveHook', 'Tools\Gone'],
            self::classTokens('<code>Tools\Tool</code> <code>ConfirmRemoveHook()</code> <code>Tools\Gone</code> <code>Bash</code> <code>Model</code>')
        );

        $missing = [];
        self::assertNotNull(self::declaredClass('Tools\Tool', $missing));
        self::assertSame([], $missing);

        // The short name exists, but not under the namespace the body names.
        self::assertNull(self::declaredClass('Other\Tool', $missing));
        self::assertNull(self::declaredClass('Tools\Gone', $missing));
        self::assertSame(
            ['Other\Tool (no declaration under src/)', 'Tools\Gone (no declaration under src/)'],
            $missing
        );
    }

    public function testAMethodDeclaredOnlyPrivatelyIsNotAPublicMethod(): void
    {
        self::assertFalse(self::publicMethodMissing(self::class, 'testAMethodDeclaredOnlyPrivatelyIsNotAPublicMethod'));
        self::assertTrue(self::publicMethodMissing(self::class, 'declaredClass'), 'a private method must not count as public API');
        self::assertTrue(self::publicMethodMissing(self::class, 'noSuchMethod'));
    }

    public function testPermissionModeCountFormsAreAllCapturedAndGateProseIsNotCounted(): void
    {
        self::assertSame(
            ['seven', 'six', 'five'],
            self::quotedPermissionModeCounts('seven permission-mode gates, six modes, five permission modes')
        );

        // "gate" alone names a different thing: the body's gates guard the write path, they are not modes.
        self::assertSame(
            [],
            self::quotedPermissionModeCounts('Two gates guard the write path. Three permission gates cover the tools.')
        );
    }

    public function testKeyClassRowsReadMarkupInTheClassCellAndRefuseARowItCannotRead(): void
    {
        $header = '<tr><th>Class</th><th>Method</th><th>Description</th></tr>';

        self::assertSame(
            [['SessionStore', 'getSession(id)']],
            self::keyClassRows($header . '<tr><td><code>SessionStore</code></td><td>getSession(id)</td><td>Gloss</td></tr>')
        );

        $this->expectException(AssertionFailedError::class);
        self::keyClassRows($header . '<tr class="row"><td>SessionStore</td><td>getSession(id)</td><td>Gloss</td></tr>');
    }

    /**
     * Every data row of the Key Classes table as [class short name, method
     * cell]. A data row the scan cannot read fails the test rather than being
     * skipped: the table's data-row count must equal the count parsed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function keyClassRows(string $table): array
    {
        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#s', $table, $all);
        $dataRows = array_filter($all[1], static fn (string $inner): bool => !str_contains($inner, '<th'));

        preg_match_all('#<tr><td>(.*?)</td><td>(.*?)</td><td>.*?</td></tr>#s', $table, $matches, PREG_SET_ORDER);
        $rows = [];
        foreach ($matches as $row) {
            $rows[] = [trim(strip_tags($row[1])), $row[2]];
        }

        self::assertSame(
            \count($dataRows),
            \count($rows),
            'the Key Classes table holds ' . \count($dataRows) . ' data rows but ' . \count($rows) . ' parsed — a row changed shape, re-pin this scan'
        );

        return $rows;
    }

    /**
     * Every spelled permission-mode count in the text, as written: "six-mode",
     * "six modes", "seven permission-mode gates", "five permission modes".
     *
     * @return list<string>
     */
    private static function quotedPermissionModeCounts(string $text): array
    {
        preg_match_all('/\b(' . self::countPattern() . ')(?:-| )(?:permission(?:-| ))?modes?\b/i', $text, $found);

        return $found[1];
    }

    /**
     * Every "<count> <noun>" pair in the text, as written: [count, noun].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function quotedNounCounts(string $text, string $nouns): array
    {
        preg_match_all('/\b(' . self::countPattern() . ')\s+(' . $nouns . ')\b/i', $text, $found, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1], $match[2]], $found);
    }

    /**
     * method_exists() also answers yes to private and protected methods, which
     * the page must not present as public API.
     */
    private static function publicMethodMissing(string $fqn, string $method): bool
    {
        if (!method_exists($fqn, $method)) {
            return true;
        }

        return !(new \ReflectionMethod($fqn, $method))->isPublic();
    }

    /**
     * Every class-shaped `<code>` token in the text that is either namespaced
     * or ends in a class suffix, in first-seen order.
     *
     * @return list<string>
     */
    private static function classTokens(string $html): array
    {
        preg_match_all(self::CLASS_TOKEN, $html, $found);
        $kept = array_filter(
            $found[1],
            static fn (string $name): bool => str_contains($name, '\\') || preg_match(self::CLASS_SUFFIX, $name) === 1
        );

        return array_values(array_unique($kept));
    }

    /**
     * Records a missing reference in $missing and returns null, or returns the
     * one FQN the name declares. A namespaced name (`Tools\Tool`) must match a
     * declaration whose FQN equals it or ends with it, so a wrong namespace is
     * reported rather than resolved by its short name.
     *
     * @param list<string> $missing
     */
    private static function declaredClass(string $name, array &$missing): ?string
    {
        $pos = strrpos($name, '\\');
        $short = $pos === false ? $name : substr($name, $pos + 1);

        $candidates = self::declarations()[$short] ?? [];
        if (str_contains($name, '\\')) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (string $fqn): bool => $fqn === $name || str_ends_with($fqn, '\\' . $name)
            ));
        }

        if (\count($candidates) !== 1) {
            $missing[] = $name . (
                $candidates === []
                    ? ' (no declaration under src/)'
                    : ' (ambiguous: ' . implode(', ', $candidates) . ')'
            );

            return null;
        }

        $fqn = $candidates[0];
        if (!class_exists($fqn) && !interface_exists($fqn) && !enum_exists($fqn)) {
            $missing[] = "{$fqn} (declared but does not load)";

            return null;
        }

        return $fqn;
    }

    /**
     * @param list<string> $missing
     */
    private static function assertMethodList(string $fqn, string $cell, array &$missing): void
    {
        preg_match_all('/([A-Za-z_]\w*)\s*\(/', $cell, $calls);
        $names = array_values(array_unique($calls[1]));
        if ($names === []) {
            $missing[] = "{$fqn}: the method cell lists no method call — re-pin the row shape";

            return;
        }

        foreach ($names as $method) {
            if (self::publicMethodMissing($fqn, $method)) {
                $missing[] = "{$fqn}::{$method}()";
            }
        }
    }

    /**
     * Only the spellings COUNT_WORDS knows, or digits. An open word class would
     * match "the locales" or "a mode" in future prose and call it a count. The
     * compound alternative comes first so the regex never settles for its tens
     * word alone, nor for its unit read as a count of its own.
     */
    private static function countPattern(): string
    {
        return '(?:(?:' . self::COMPOUND_TENS . ')[- ](?:' . self::COMPOUND_UNITS . ')|'
            . implode('|', array_keys(self::COUNT_WORDS)) . '|\d+)';
    }

    private static function countValue(string $word): int
    {
        $lower = strtolower($word);
        if (ctype_digit($lower)) {
            return (int) $lower;
        }

        if (preg_match('/^(' . self::COMPOUND_TENS . ')[- ](' . self::COMPOUND_UNITS . ')$/', $lower, $parts) === 1) {
            return self::COUNT_WORDS[$parts[1]] + self::COUNT_WORDS[$parts[2]];
        }

        return self::COUNT_WORDS[$lower] ?? -1;
    }

    private static function body(): string
    {
        self::assertFileExists(self::BODY, 'docs/_data/candy-code.body.html is missing from the repository');

        return (string) file_get_contents(self::BODY);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function declarations(): array
    {
        if (self::$declarations !== null) {
            return self::$declarations;
        }

        $map = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $namespace) !== 1) {
                continue;
            }
            if (preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $types) === 0) {
                continue;
            }

            foreach ($types[1] as $short) {
                $map[$short][] = $namespace[1] . '\\' . $short;
            }
        }

        self::assertNotEmpty($map, 'no class declarations were found under candy-code/src — the source scan is broken');

        return self::$declarations = $map;
    }
}
