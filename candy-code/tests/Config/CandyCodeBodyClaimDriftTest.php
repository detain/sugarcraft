<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookEvent;
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
    private const SOURCE = __DIR__ . '/../../src';
    private const LANG = __DIR__ . '/../../lang';

    /**
     * Class-shaped names the body puts in `<code>`. The suffixes are the
     * naming conventions the source already follows; a bare `Bash` or `Read`
     * is a tool name, not a class, and is deliberately not matched.
     */
    private const CLASS_TOKEN = '#<code>([A-Z]\w*(?:Hook|Interface|Engine|Backend|Gate|Store|Manager|Registry|Client|Preset|Factory|Mode))</code>#';

    /** @var array<string, int> */
    private const COUNT_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
        'nineteen' => 19, 'twenty' => 20,
    ];

    /** @var array<string, list<string>>|null short name => declaring FQNs */
    private static ?array $declarations = null;

    public function testKeyClassesTableNamesOnlyClassesAndMethodsThatExistInSource(): void
    {
        self::assertSame(
            1,
            preg_match('#<table class="api-table">(.*?)</table>#s', self::body(), $table),
            'the candy-code body no longer carries the Key Classes table'
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
        preg_match_all(self::CLASS_TOKEN, self::body(), $tokens);
        $names = array_values(array_unique($tokens[1]));
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
            preg_match('#<tr><td>PermissionGate</td><td>mode\(\)[^<]*</td><td>(.*?)</td></tr>#s', self::body(), $row),
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

        $body = strip_tags(self::body());
        preg_match_all('/\b(' . self::countPattern() . ')\s+(hook events?|locales?)\b/i', $body, $quoted, PREG_SET_ORDER);

        foreach ($quoted as $match) {
            $noun = strtolower($match[2]);
            $kind = str_starts_with($noun, 'hook') ? 'hook event' : 'locale';
            self::assertSame(
                $live[$kind],
                self::countValue($match[1]),
                "the candy-code body quotes '{$match[1]} {$match[2]}' while the live count is {$live[$kind]}"
            );
        }
    }

    public function testAMethodDeclaredOnlyPrivatelyIsNotAPublicMethod(): void
    {
        self::assertFalse(self::publicMethodMissing(self::class, 'testAMethodDeclaredOnlyPrivatelyIsNotAPublicMethod'));
        self::assertTrue(self::publicMethodMissing(self::class, 'declaredClass'), 'a private method must not count as public API');
        self::assertTrue(self::publicMethodMissing(self::class, 'noSuchMethod'));
    }

    public function testPermissionModeCountFormsAreAllCaptured(): void
    {
        self::assertSame(
            ['seven', 'six', 'five'],
            self::quotedPermissionModeCounts('seven permission-mode gates, six modes, five permission modes')
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
        preg_match_all('/\b(' . self::countPattern() . ')(?:-| )(?:permission(?:-| ))?(?:mode|gate)s?\b/i', $text, $found);

        return $found[1];
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
     * Records a missing reference in $missing and returns null, or returns the
     * one FQN the short name declares.
     *
     * @param list<string> $missing
     */
    private static function declaredClass(string $short, array &$missing): ?string
    {
        $candidates = self::declarations()[$short] ?? [];
        if (\count($candidates) !== 1) {
            $missing[] = $short . (
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
     * match "the locales" or "a mode" in future prose and call it a count.
     */
    private static function countPattern(): string
    {
        return implode('|', array_keys(self::COUNT_WORDS)) . '|\d+';
    }

    private static function countValue(string $word): int
    {
        $lower = strtolower($word);
        if (ctype_digit($lower)) {
            return (int) $lower;
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
