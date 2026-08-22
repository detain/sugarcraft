<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * `SkillRegistry::$compiledPathPatterns` used to be an unbounded static cache
 * (E99), and the bound everyone reasoned from was a property of its callers.
 *
 * THE OLD REASONING, AND WHY IT WAS NOT A BOUND. The doc-block said the pattern
 * set is "bounded by the skills installed on the box", which is TRUE of every
 * caller in `src/` today — the only production route into
 * {@see SkillRegistry::pathMatches()} is `getForPaths()`, iterating each
 * registered skill's frontmatter `paths:`. But `pathMatches()` is `public
 * static`. A `/skill` verb taking a glob, or a user-supplied `paths:` filter,
 * would feed it distinct patterns per request, and the cache would grow for the
 * life of the process with nothing to stop it. A convention is not a bound.
 *
 * THE DECISION, AND THE ALTERNATIVE THAT WAS MEASURED AND REJECTED. "Drop the
 * memo" is the other way to bound this, and it does not survive contact with a
 * measurement: the translation is a character walk at ~1.46 us against ~0.17 us
 * for the match it feeds, so removing the cache makes this matcher 8.5x slower
 * — slower than the `str_replace` predicate it replaced. The generator and the
 * three-run figures are on `$compiledPathPatterns`. So: a cap, set high enough
 * that no real skill roster can reach it, with an empty-and-refill eviction.
 *
 * WHAT THIS FILE PINS.
 *  - The cap is enforced, not merely declared.
 *  - The cap is far above the shipped roster, DERIVED from that roster rather
 *    than compared against a number typed here — so a tree that grew 300 skills
 *    finds out from this test rather than from a memory graph.
 *  - The hit path still caches, which is the property the cap must not have
 *    cost. (`SkillPathPatternTest::testTheCompiledPatternIsCachedUnderItsRawPattern()`
 *    asserts the same thing from the other end and is deliberately left alone.)
 *  - {@see SkillRegistry::legacyPathMatch()} is STILL REACHABLE after the
 *    rewrite. This is the one that would otherwise rot quietly: the fallback is
 *    reached only through an uncompilable pattern, and a change to the lookup
 *    around `compilePathPattern()` is exactly the sort of edit that could route
 *    every pattern to a compiled regex and leave an unpinnable method behind.
 *
 * THE CACHE IS SHARED PROCESS STATE, so every test here saves and restores it
 * rather than clearing it. A sibling test that had warmed a pattern must find
 * it warm afterwards.
 *
 * @internal
 */
final class CompiledPatternCacheBoundTest extends TestCase
{
    /** @var array<string, string> */
    private array $saved = [];

    private ReflectionProperty $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ReflectionProperty(SkillRegistry::class, 'compiledPathPatterns');
        $this->cache->setAccessible(true);
        /** @var array<string, string> $current */
        $current = $this->cache->getValue();
        $this->saved = $current;
    }

    protected function tearDown(): void
    {
        $this->cache->setValue(null, $this->saved);

        parent::tearDown();
    }

    private function cap(): int
    {
        return (int) (new ReflectionClassConstant(SkillRegistry::class, 'MAX_COMPILED_PATTERNS'))->getValue();
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        /** @var array<string, string> $v */
        $v = $this->cache->getValue();

        return $v;
    }

    /**
     * The cap is enforced. Feed it well past the cap and the map never exceeds
     * it — checked on every insertion, not only at the end, because an
     * eviction that fired once at the finish line would pass an end-state
     * assertion while the peak was unbounded.
     */
    public function testTheCacheNeverGrowsPastItsCap(): void
    {
        $cap = $this->cap();
        $this->cache->setValue(null, []);

        $peak = 0;
        $overruns = 0;
        for ($i = 0; $i < $cap * 2 + 5; $i++) {
            SkillRegistry::pathMatches("e99-probe-{$i}/**/*.php", 'x.php');
            $n = count($this->entries());
            $peak = max($peak, $n);
            if ($n > $cap) {
                $overruns++;
            }
        }

        self::assertSame(
            0,
            $overruns,
            "the compiled-pattern cache exceeded MAX_COMPILED_PATTERNS ({$cap}) on {$overruns} of "
            . ($cap * 2 + 5) . ' insertions. It is a `public static` entry point; an unbounded process-'
            . 'lifetime cache behind one is E99.',
        );
        self::assertSame(
            $cap,
            $peak,
            'the cache never actually reached its cap over ' . ($cap * 2 + 5) . ' distinct patterns, so '
            . 'this test is not exercising the eviction it exists to pin. Either the cap moved or '
            . 'something is clearing the map underneath it.',
        );
    }

    /**
     * The cap must stay unreachable by a real skill roster, and "real" is read
     * off the shipped built-ins rather than assumed.
     *
     * DERIVED, NOT TYPED. If this tree ever ships enough distinct `paths:`
     * globs to come within an order of magnitude of the cap, the cap is no
     * longer "unreachable in practice" and the sentence saying so on
     * `MAX_COMPILED_PATTERNS` has to be rewritten — which is what this reds
     * for.
     */
    public function testTheCapIsFarAboveTheShippedPatternRoster(): void
    {
        $dir = __DIR__ . '/../../src/Skills/BuiltIn';
        $distinct = [];
        $skills = 0;

        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }
            $file = $dir . '/' . $entry . '/SKILL.md';
            if (!is_file($file)) {
                continue;
            }
            $skills++;
            foreach (Skill::fromFile($file)->paths as $pattern) {
                $distinct[$pattern] = true;
            }
        }

        self::assertGreaterThan(0, $skills, 'no built-in skills found; the roster derivation is broken');

        $shipped = count($distinct);
        $cap = $this->cap();

        self::assertGreaterThanOrEqual(
            $shipped * 10,
            $cap,
            sprintf(
                'MAX_COMPILED_PATTERNS is %d against %d distinct `paths:` globs across %d shipped '
                . 'built-ins — less than 10x headroom. The cap is documented as a number no real '
                . 'roster can reach; either raise it or rewrite that reasoning.',
                $cap,
                $shipped,
                $skills,
            ),
        );
    }

    /**
     * The hit path still memoises — the property the cap had to not cost.
     */
    public function testAWarmPatternIsNotRecompiled(): void
    {
        $this->cache->setValue(null, []);
        $pattern = 'e99-warm-' . __FUNCTION__ . '/**/*.php';

        SkillRegistry::pathMatches($pattern, 'nope');
        $first = $this->entries();
        self::assertArrayHasKey($pattern, $first, 'the first call did not populate the cache at all');

        // Poison the cached value. If the second call recompiles, it overwrites
        // the poison; if it memoises, the poison is what gets used and stays.
        $poison = '#^never-matches-anything$#D';
        $this->cache->setValue(null, [$pattern => $poison]);
        SkillRegistry::pathMatches($pattern, 'nope');

        self::assertSame(
            $poison,
            $this->entries()[$pattern] ?? null,
            'pathMatches() recompiled a pattern that was already cached, so the memo is doing nothing. '
            . 'The translation costs ~8.5x what the match does (figures on $compiledPathPatterns); '
            . 're-take them before deciding that is acceptable.',
        );
    }

    /**
     * `legacyPathMatch()` is still reachable, and reached by the shape the
     * class documents as reaching it.
     *
     * THREE STEPS, because only the third is the claim. (1) The pattern really
     * does fail to compile — otherwise the route is closed and the rest is
     * theatre. (2) The old predicate answers it. (3) `pathMatches()` returns
     * that answer, including for the pattern where the fallback's `**`
     * rewrites are load-bearing rather than incidental.
     */
    public function testTheUncompilableShapeStillRoutesToTheOldPredicate(): void
    {
        $uncompilable = 'a[\]]b';

        $compile = new ReflectionMethod(SkillRegistry::class, 'compilePathPattern');
        $compile->setAccessible(true);
        $regex = (string) $compile->invoke(null, $uncompilable);

        self::assertFalse(
            @preg_match($regex, 'a]b'),
            "compilePathPattern('{$uncompilable}') now emits a regex PCRE accepts ({$regex}). That "
            . 'closes the only route to legacyPathMatch(), which the never-remove rule then leaves as '
            . 'an unpinnable method and which the M6-class mutations stop being caught by. If the '
            . 'bracket scan was deliberately made escape-aware, find a NEW uncompilable shape and put '
            . 'it here — see SkillRegistry::compileClassBody()\'s doc-block.',
        );

        $legacy = new ReflectionMethod(SkillRegistry::class, 'legacyPathMatch');
        $legacy->setAccessible(true);
        self::assertTrue(
            (bool) $legacy->invoke(null, $uncompilable, 'a]b'),
            'the old predicate no longer claims the shape it is kept for',
        );

        self::assertTrue(
            SkillRegistry::pathMatches($uncompilable, 'a]b'),
            'pathMatches() no longer answers an uncompilable pattern from legacyPathMatch()',
        );
        self::assertTrue(
            SkillRegistry::pathMatches('src/**/x[\]]y.php', 'src/x]y.php'),
            'the fallback answered without its `**` rewrites — a bare fnmatch() would return false '
            . 'here, so the rewrites are part of the answer rather than decoration around it',
        );
    }

    /**
     * An eviction must not corrupt what it keeps: after a wipe the next lookup
     * of an evicted pattern recompiles to the SAME regex it had before.
     *
     * Cheap to state and the reason the wipe is safe at all — the cache is a
     * pure function of the pattern, so losing an entry costs time and never an
     * answer.
     */
    public function testAnEvictedPatternRecompilesToTheSameRegex(): void
    {
        $cap = $this->cap();
        $this->cache->setValue(null, []);

        $victim = 'e99-victim-' . __FUNCTION__ . '/**/*.php';
        SkillRegistry::pathMatches($victim, 'x.php');
        $before = $this->entries()[$victim] ?? null;
        self::assertIsString($before);

        for ($i = 0; $i <= $cap; $i++) {
            SkillRegistry::pathMatches("e99-filler-{$i}/**/*.php", 'x.php');
        }
        self::assertArrayNotHasKey(
            $victim,
            $this->entries(),
            'filling past the cap did not evict the pattern cached before it, so this test is not '
            . 'exercising an eviction',
        );

        SkillRegistry::pathMatches($victim, 'x.php');
        self::assertSame(
            $before,
            $this->entries()[$victim] ?? null,
            'a pattern recompiled after eviction produced a different regex than it had before. The '
            . 'translation is supposed to be pure; if it is not, the cache was hiding it.',
        );
    }
}
