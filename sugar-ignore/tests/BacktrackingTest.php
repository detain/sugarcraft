<?php

declare(strict_types=1);

namespace SugarCraft\Ignore\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ignore\IgnoreRules;

/**
 * Hostile-pattern defence (candy-code audit F-T5): every glob compiled straight
 * to a backtracking PCRE let `**a**a…**c` cost ~70 ms per path, and the
 * backtrack-limit error that ended each match was read as "no match" (fail
 * OPEN). Ported from candy-code's IgnoreRulesBacktrackingTest.
 */
final class BacktrackingTest extends TestCase
{
    private static function rules(string $gitignore): IgnoreRules
    {
        return IgnoreRules::fromPaths(['.gitignore' => $gitignore]);
    }

    /** Run $fn with PCRE starved so every regex match errors. */
    private static function starved(callable $fn): mixed
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            return $fn();
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function testAHostileRuleOverManyPathsFinishesInsideTheBudget(): void
    {
        $rules = self::rules(str_repeat(str_repeat('**a', 14) . "**c\n", 20));
        $name = 'c' . str_repeat('a', 40);

        $started = microtime(true);
        for ($i = 0; $i < 2000; $i++) {
            $this->assertFalse($rules->isIgnored('d/' . $name . $i, false));
        }
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(2.0, $elapsed, sprintf('2,000 paths took %.2fs', $elapsed));
    }

    public function testTheHostileRuleIsStillDecidedExactly(): void
    {
        $rules = self::rules(str_repeat('**a', 14) . "**c\n");

        $this->assertFalse($rules->isIgnored('c' . str_repeat('a', 40) . '7', false));
        $this->assertTrue($rules->isIgnored('x/' . str_repeat('a', 14) . 'c', false));
        $this->assertSame([], $rules->undecidablePatterns(), 'an expensive rule is not an undecidable one');
    }

    public function testARuleThatExhaustsTheBacktrackLimitStillHidesItsTarget(): void
    {
        [$hidden, $shown, $undecidable] = self::starved(function (): array {
            $rules = self::rules("*.log\n");

            return [$rules->isIgnored('a/b/c/x.log', false), $rules->isIgnored('a/b/c/x.txt', false), $rules->undecidablePatterns()];
        });

        $this->assertTrue($hidden, 'the limit error must not turn into "no match"');
        $this->assertFalse($shown, 'and the fallback is exact, not hide-everything');
        $this->assertSame([], $undecidable);
    }

    public function testANegationIsStillHonouredWhenTheLimitIsExhausted(): void
    {
        [$dropped, $kept] = self::starved(function (): array {
            $rules = self::rules("*.log\n!keep.log\n");

            return [$rules->isIgnored('a/drop.log', false), $rules->isIgnored('a/keep.log', false)];
        });

        $this->assertTrue($dropped);
        $this->assertFalse($kept);
    }

    public function testAnUndecidableHideRuleHidesAndIsReportedOnce(): void
    {
        $pattern = str_repeat('*a', 1000) . '*c';
        $rules = self::rules($pattern . "\n");

        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300);
        $this->assertSame('.gitignore: ' . $pattern, $rules->ignores($long . '/one.txt', false));
        $this->assertTrue($rules->isIgnored($long . '/two.txt', false));
        $this->assertFalse($rules->isIgnored('short.txt', false), 'a decidable path is still decided');

        $this->assertSame(['.gitignore: ' . $pattern], $rules->undecidablePatterns());
        $this->assertSame([['.gitignore', $pattern]], $rules->undecidableRules());
    }

    public function testAnUndecidableNegationDoesNotReInclude(): void
    {
        $rules = self::rules("*.log\n!" . str_repeat('*a', 1000) . "*c\n");

        $long = str_repeat('x', 300) . '/' . str_repeat('y', 300) . '.log';
        $this->assertTrue($rules->isIgnored($long, false));
        $this->assertCount(1, $rules->undecidablePatterns());
    }

    /**
     * Expected verdicts were taken from the regex-only implementation, so a row
     * failing means the matcher changed meaning, not just cost.
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
    public function testVerdictsMatchTheRegexOnlyMatcher(string $gitignore, string $path, bool $isDir, bool $expected): void
    {
        $this->assertSame($expected, self::rules($gitignore . "\n")->isIgnored($path, $isDir));
    }

    /**
     * The same rows with PCRE starved, so (nearly) every match takes the
     * automaton fallback: which engine answers must never change the answer.
     *
     * @dataProvider semanticsProvider
     */
    public function testVerdictsAreTheSameWhenEveryRegexErrors(string $gitignore, string $path, bool $isDir, bool $expected): void
    {
        $verdict = self::starved(fn (): bool => self::rules($gitignore . "\n")->isIgnored($path, $isDir));

        $this->assertSame($expected, $verdict);
    }
}
