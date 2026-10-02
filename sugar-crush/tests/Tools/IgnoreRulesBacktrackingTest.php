<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\IgnoreRules;

/**
 * @see IgnoreRules
 *
 * Audit F-T5: every gitignore glob was compiled straight to a backtracking
 * PCRE, so a hostile `.gitignore` line (`**a**a…**c` → `.*a.*a….*c`) cost
 * ~70 ms per path — `Glob '**\/*'` over 2,000 files took 139 s — and the
 * backtrack-limit error that ended each match was read as "no match", so the
 * rule silently stopped applying (fail OPEN).
 */
final class IgnoreRulesBacktrackingTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-ignore-redos-' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    // =========================================================================
    // Cost — the repro, as a time budget
    // =========================================================================

    public function testGlobUnderAHostileGitignoreFinishesInsideTheBudget(): void
    {
        // The audit's fixture: 20 copies of one backtracking line, and 2,000
        // names long enough to make every `.*` split worth trying.
        $this->write('.gitignore', str_repeat(str_repeat('**a', 14) . "**c\n", 20));
        mkdir($this->root . '/d');
        $name = 'c' . str_repeat('a', 40);
        for ($i = 0; $i < 2000; $i++) {
            touch($this->root . '/d/' . $name . $i);
        }

        $started = microtime(true);
        $result = (new Glob($this->root))->execute(['id' => 'g', 'pattern' => '**/*']);
        $elapsed = microtime(true) - $started;

        $this->assertFalse($result->isError());
        $this->assertLessThan(2.0, $elapsed, sprintf('Glob took %.2fs over 2,000 files', $elapsed));
        // None of the names ends in `c`, so the rule matches none of them.
        $this->assertStringContainsString($name . '0', $result->content());
    }

    public function testTheHostileRuleIsStillDecidedExactly(): void
    {
        $this->write('.gitignore', str_repeat('**a', 14) . "**c\n");
        $rules = IgnoreRules::new($this->root);

        $this->assertFalse($rules->ignores($this->root . '/c' . str_repeat('a', 40) . '7', false));
        $this->assertTrue($rules->ignores($this->root . '/x/' . str_repeat('a', 14) . 'c', false));
        $this->assertSame([], $rules->undecidablePatterns(), 'an expensive rule is not an undecidable one');
    }

    // =========================================================================
    // A PCRE limit error is never read as "no match"
    // =========================================================================

    public function testARuleThatExhaustsTheBacktrackLimitStillHidesItsTarget(): void
    {
        $this->write('.gitignore', "*.log\n");

        // Starving the limit makes PCRE error deterministically — on the
        // unanchored `(?:[^/]+/)*` prefix, before any real backtracking.
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $rules = IgnoreRules::new($this->root);
            $hidden = $rules->ignores($this->root . '/a/b/c/x.log', false);
            $shown = $rules->ignores($this->root . '/a/b/c/x.txt', false);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertTrue($hidden, 'the limit error must not turn into "no match"');
        $this->assertFalse($shown, 'and the fallback is exact, not hide-everything');
        $this->assertSame([], $rules->undecidablePatterns());
    }

    public function testANegationIsStillHonouredWhenTheLimitIsExhausted(): void
    {
        $this->write('.gitignore', "*.log\n!keep.log\n");

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $rules = IgnoreRules::new($this->root);
            $dropped = $rules->ignores($this->root . '/a/drop.log', false);
            $kept = $rules->ignores($this->root . '/a/keep.log', false);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertTrue($dropped);
        $this->assertFalse($kept);
    }

    // =========================================================================
    // Over budget — fail CLOSED, in the direction that hides
    // =========================================================================

    public function testAnUndecidableHideRuleHidesAndIsReportedOnce(): void
    {
        // 2,000+ tokens against a 600-byte path is past the match budget, so
        // the rule cannot be decided; it would not actually match (no `a`).
        $pattern = str_repeat('*a', 1000) . '*c';
        $this->write('.gitignore', $pattern . "\n");
        $rules = IgnoreRules::new($this->root);

        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300);
        $this->assertTrue($rules->ignores($this->root . '/' . $long . '/one.txt', false));
        $this->assertTrue($rules->ignores($this->root . '/' . $long . '/two.txt', false));
        $this->assertFalse($rules->ignores($this->root . '/short.txt', false), 'a decidable path is still decided');

        $this->assertSame([$this->root . '/.gitignore: ' . $pattern], $rules->undecidablePatterns());
    }

    public function testAnUndecidableNegationDoesNotReInclude(): void
    {
        $this->write('.gitignore', "*.log\n!" . str_repeat('*a', 1000) . "*c\n");
        $rules = IgnoreRules::new($this->root);

        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300) . '.log';
        $this->assertTrue($rules->ignores($this->root . '/' . $long, false));
        $this->assertCount(1, $rules->undecidablePatterns());
    }

    // =========================================================================
    // Semantics — unchanged from the regex-only matcher
    // =========================================================================

    /**
     * Expected verdicts were taken from the regex-only implementation this
     * replaced, so a row here failing means the matcher changed meaning, not
     * just cost. Patterns with more than two wildcards, or two that cross `/`,
     * exercise the automaton; the rest the PCRE fast path.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function semanticsProvider(): array
    {
        return [
            'a/**/b, zero dirs' => ['a/**/b', 'a/b', false, true],
            'a/**/b, one dir' => ['a/**/b', 'a/x/b', false, true],
            'a/**/b, two dirs' => ['a/**/b', 'a/x/y/b', false, true],
            'a/**/b is anchored' => ['a/**/b', 'x/a/b', false, false],
            'a/**/b needs the whole name' => ['a/**/b', 'a/bc', false, false],
            '**/foo at top' => ['**/foo', 'foo', false, true],
            '**/foo deep' => ['**/foo', 'x/y/foo', false, true],
            '**/foo is not a prefix' => ['**/foo', 'foobar', false, false],
            '**/foo hides contents' => ['**/foo', 'x/foo/bar', false, true],
            'foo/** below' => ['foo/**', 'foo/x', false, true],
            'foo/** deep below' => ['foo/**', 'foo/x/y', false, true],
            'foo/** not foo itself' => ['foo/**', 'foo', false, false],
            'foo/** not a sibling' => ['foo/**', 'foobar/x', false, false],
            '*.log top' => ['*.log', 'x.log', false, true],
            '*.log deep' => ['*.log', 'a/b/x.log', false, true],
            '*.log suffix only' => ['*.log', 'x.logs', false, false],
            '*.log needs the dot' => ['*.log', 'log', false, false],
            '*** a file' => ['***', 'x', false, true],
            '*** a nested file' => ['***', 'a/b', false, true],
            '***/x one dir' => ['***/x', 'a/x', false, true],
            '***/x two dirs' => ['***/x', 'a/b/x', false, true],
            '***/x needs a dir' => ['***/x', 'x', false, false],
            'a**b adjacent' => ['a**b', 'ab', false, true],
            'a**b crosses /' => ['a**b', 'a/x/b', false, true],
            'a**b at depth' => ['a**b', 'c/axb', false, true],
            'a**b order' => ['a**b', 'ba', false, false],
            '**/*.php top' => ['**/*.php', 'x.php', false, true],
            '**/*.php deep' => ['**/*.php', 'a/b/x.php', false, true],
            '**/*.php suffix' => ['**/*.php', 'x.phpx', false, false],
            '*.min.* top' => ['*.min.*', 'a.min.js', false, true],
            '*.min.* deep' => ['*.min.*', 'd/a.min.js', false, true],
            '*.min.* needs .min.' => ['*.min.*', 'a.js', false, false],
            '**/build/** below' => ['**/build/**', 'build/x', false, true],
            '**/build/** deep' => ['**/build/**', 'a/build/x/y', false, true],
            '**/build/** not build itself' => ['**/build/**', 'build', false, false],
            '**/build/** whole name' => ['**/build/**', 'a/builder/x', false, false],
            'a/**/**/b zero dirs' => ['a/**/**/b', 'a/b', false, true],
            'a/**/**/b two dirs' => ['a/**/**/b', 'a/x/y/b', false, true],
            'a/**/**/b anchored' => ['a/**/**/b', 'b/a/b', false, false],
            '*a*a*a*c spaced' => ['*a*a*a*c', 'xaxaxaxc', false, true],
            '*a*a*a*c packed' => ['*a*a*a*c', 'aaac', false, true],
            '*a*a*a*c too few' => ['*a*a*a*c', 'aac', false, false],
            '*a*a*a*c no crossing' => ['*a*a*a*c', 'a/a/a/c', false, false],
            '**a**a**c crossing' => ['**a**a**c', 'a/a/c', false, true],
            '**a**a**c packed' => ['**a**a**c', 'aac', false, true],
            '**a**a**c too few' => ['**a**a**c', 'ac', false, false],
            '**/cache/ a dir' => ['**/cache/', 'cache', true, true],
            '**/cache/ not a file' => ['**/cache/', 'cache', false, false],
            '**/cache/ hides contents' => ['**/cache/', 'x/cache/y', false, true],
            'negated automaton rule' => ["*.log\n!**/keep*.*", 'a/keep1.log', false, false],
            'negated automaton rule, other' => ["*.log\n!**/keep*.*", 'a/drop.log', false, true],
            'classes and ? with stars' => ['l?g[0-9]*.t*t', 'log7a.txt', false, true],
            'class rejects' => ['l?g[0-9]*.t*t', 'logX.txt', false, false],
        ];
    }

    /** @dataProvider semanticsProvider */
    public function testVerdictsMatchTheRegexOnlyMatcher(string $gitignore, string $path, bool $isDirectory, bool $expected): void
    {
        $this->write('.gitignore', $gitignore . "\n");

        $this->assertSame($expected, IgnoreRules::new($this->root)->ignores($this->root . '/' . $path, $isDirectory));
    }

    /**
     * The same rows with PCRE starved, so (nearly) every match takes the
     * automaton fallback: which engine answers must never change the answer.
     *
     * @dataProvider semanticsProvider
     */
    public function testVerdictsAreTheSameWhenEveryRegexErrors(string $gitignore, string $path, bool $isDirectory, bool $expected): void
    {
        $this->write('.gitignore', $gitignore . "\n");

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $verdict = IgnoreRules::new($this->root)->ignores($this->root . '/' . $path, $isDirectory);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertSame($expected, $verdict);
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link() FIRST: is_dir() follows a symlink, so recursing would
            // empty the link's target instead of removing the link.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }

        rmdir($dir);
    }
}
