<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\Glob;
use SugarCraft\Code\Tools\IgnoreRules;

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
        // Decided with a one-name pattern, not the 2,000-file listing: the big
        // listing rides the tool's own byte cap, and WHICH names survive that
        // cut depends on readdir order — a co-shard shape, not a rules verdict.
        $one = (new Glob($this->root))->execute(['id' => 'g2', 'pattern' => '**/' . $name . '0']);
        $this->assertFalse($one->isError());
        $this->assertStringContainsString($name . '0', $one->content());
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

        // S-T06: the engine reports the ignore file root-relative.
        $this->assertSame(['.gitignore: ' . $pattern], $rules->undecidablePatterns());
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

    // S-T06: the verdict-equivalence matrix (regex path vs automaton fallback)
    // moved with the engine to sugar-ignore's BacktrackingTest.

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
