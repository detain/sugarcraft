<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A `{@see}` THAT NAMES A TEST WHICH DOES NOT EXIST IS WORSE THAN NO CITATION.
 *
 * `src/` cites its own test suite 87 times. Those citations are what tells a
 * reader that a doc-block's claim is CHECKED rather than proof-read, and they
 * are the first thing a rename breaks — silently, because nothing compiles a
 * doc-comment. Round 44 shipped one and a reviewer, not a test, found it:
 * {@see \SugarCraft\Crush\Config\LayeredSettings}' `PROJECT_TIER_KEYS`
 * doc-block cited
 * `GlobFigureDriftTest::testTheSettingsPageNamesExactlyTheSourceFilesStillCarryingTheStaleFigure()`
 * in the same commit that renamed that method, so the sentence "that it is the
 * ONLY remaining one is itself asserted, by …" pointed at nothing at all.
 *
 * THE ALPHABET IS DELIBERATELY WIDER THAN THE DEFECT THAT PROMPTED IT. Three
 * shapes carry a test citation in this tree, and a census written to catch only
 * the first would report a clean tree while the other two rotted:
 *
 * - a fully-qualified `{@see \SugarCraft\Crush\Tests\…\FooTest::testBar()}`
 *   in `src/` — 84 of them;
 * - a BARE `{@see FooTest::testBar()}` in `src/`, which resolves through no
 *   `use` statement and is readable only because the basename is unique —
 *   three of them, in `src/Cli/ArgvParser.php` and `src/Cli/Bootstrap.php`;
 * - a backticked `` `FooTest::testBar()` `` in a `docs/*.md` page — one, in
 *   `docs/ARCHITECTURE.md`, which is the mechanism paragraph for the tool
 *   registry.
 *
 * ANYTHING THAT LOOKS LIKE A TEST CITATION AND CANNOT BE PARSED IS REPORTED,
 * NOT SKIPPED. {@see unparseable()} collects them and
 * {@see testEveryCitationOfATestSymbolIsParseable()} asserts the list is empty.
 * A guard that quietly ignores what it cannot read has a hole shaped exactly
 * like the next defect — and this file's whole subject is a citation that read
 * as authoritative while resolving to nothing.
 *
 * WHAT THIS DOES NOT COVER, and it is the larger half: citations of PRODUCTION
 * symbols. `{@see self::foo()}`, `{@see Foo::CONST}`, `{@see $this->bar}` and
 * plain class references are far more numerous and have more shapes than the
 * four here; a census of them is a separate instrument and is recorded as a
 * follow-up rather than half-built. The test-symbol subset is picked because it
 * is the one that crosses a boundary the compiler never checks — `src/` cannot
 * autoload `tests/`, so nothing but prose links them.
 *
 * MEASURED ON PHP 8.3.6, 2026-08-22. `class_exists()`/`method_exists()` and a
 * `preg_match_all()` over prose behave the same on 8.3 and 8.4; this box has no
 * 8.4 while CI runs both, so the stamp is provenance.
 *
 * @internal
 */
final class SymbolCitationDriftTest extends TestCase
{
    /** @var list<string> */
    private array $unparseable = [];

    /**
     * The lib root, resolved from this file rather than from a CWD a runner
     * chooses.
     */
    private function root(): string
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        return $root;
    }

    /**
     * Doc-comment continuations folded away, so a citation wrapped across two
     * lines is one string.
     *
     * A `{@see …}` is routinely broken over a line boundary by the 80-column
     * wrap, and every leading ` * ` in between belongs to the comment rather
     * than to the reference. Folding is applied to the WHOLE file rather than
     * only inside comments: the only false fold would be a source line that
     * begins with `*`, which PSR-12 does not produce, and the alternative — a
     * comment-aware pass — buys nothing a citation census can use.
     */
    private function flatten(string $text): string
    {
        return (string) preg_replace('/\R\s*\*\s*/', ' ', $text);
    }

    /** @return array<string, string> repo-relative label => text */
    private function sources(string $subdir, string $extension): array
    {
        $root = $this->root();
        $out = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $subdir));
        foreach ($walk as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== $extension) {
                continue;
            }
            $text = file_get_contents($file->getPathname());
            self::assertIsString($text, $file->getPathname() . ' is unreadable, so the census over it is void');
            $out[substr($file->getPathname(), \strlen($root) + 1)] = $text;
        }
        ksort($out);

        return $out;
    }

    /**
     * Every citation of a test symbol, as `[label, class-token, method|'']`.
     *
     * A "test symbol" is a class token whose last namespace segment ends in
     * `Test`, or any token under `SugarCraft\Crush\Tests`. That is the alphabet,
     * and it is stated rather than implied: a helper under
     * `Tests\…\Support` that does NOT end in `Test` is caught by the second
     * clause, and a production class ending in `Test` would be a false positive
     * this tree does not contain.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function citations(): array
    {
        $found = [];

        foreach ($this->sources('src', 'php') as $label => $text) {
            $flat = $this->flatten($text);
            preg_match_all('/\{@see\s+([^}]*)\}/', $flat, $matches);
            foreach ($matches[1] as $body) {
                $target = strtok(trim($body), " \t") ?: '';
                if (!$this->looksLikeATestSymbol($target)) {
                    continue;
                }
                $parsed = $this->parse($target);
                if ($parsed === null) {
                    $this->unparseable[] = $label . ': {@see ' . $target . '}';

                    continue;
                }
                $found[] = [$label, $parsed[0], $parsed[1], $parsed[2]];
            }
        }

        foreach ($this->sources('docs', 'md') as $label => $text) {
            preg_match_all('/`([A-Za-z0-9_\\\\]+Test(?:::[A-Za-z0-9_]+\(\))?)`/', $text, $matches);
            foreach ($matches[1] as $target) {
                $parsed = $this->parse($target);
                if ($parsed === null) {
                    $this->unparseable[] = $label . ': `' . $target . '`';

                    continue;
                }
                $found[] = [$label, $parsed[0], $parsed[1], $parsed[2]];
            }
        }

        return $found;
    }

    /**
     * Is this token something the census is claiming to be able to resolve?
     *
     * Deliberately loose. A token that LOOKS like a test citation and turns out
     * to be unparseable is reported by {@see citations()}; one that is not
     * recognised here is silently out of scope, so the loose end is the safer
     * side to err on.
     */
    private function looksLikeATestSymbol(string $target): bool
    {
        if (str_contains($target, 'SugarCraft\\Crush\\Tests\\')) {
            return true;
        }
        $class = explode('::', $target)[0];
        $short = substr($class, (int) strrpos($class, '\\'));

        return str_ends_with($short, 'Test');
    }

    /**
     * A citation token split into a class and, optionally, a member.
     *
     * THREE MEMBER SHAPES, and the third was found by this census on its first
     * run rather than by reading: `src/Cli/Bootstrap.php` cites
     * `BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES`, a class CONSTANT, which the
     * first cut of this method reported as unparseable. That is the
     * report-rather-than-skip rule working — an unreadable citation surfaced
     * instead of being dropped — and the repair is to teach the shape, never to
     * widen an exemption.
     *
     * @return array{0: string, 1: string, 2: string}|null [class, member or '', 'method'|'constant'|'']
     */
    private function parse(string $target): ?array
    {
        $name = '[A-Za-z_][A-Za-z0-9_]*';
        $class = '\\\\?(' . $name . '(?:\\\\' . $name . ')*)';

        if (preg_match('/^' . $class . '$/', $target, $m) === 1) {
            return [$m[1], '', ''];
        }
        if (preg_match('/^' . $class . '::(' . $name . ')\(\)$/', $target, $m) === 1) {
            return [$m[1], $m[2], 'method'];
        }
        if (preg_match('/^' . $class . '::([A-Z][A-Z0-9_]*)$/', $target, $m) === 1) {
            return [$m[1], $m[2], 'constant'];
        }

        return null;
    }

    /**
     * A class token as written in prose, resolved to a class that exists.
     *
     * A fully-qualified token is checked with `class_exists()` — the suite's
     * own autoloader maps `SugarCraft\Crush\Tests\` onto `tests/`, so a name
     * that resolves here is a name a reader can open. A BARE token is resolved
     * by finding exactly one `tests/**\/<Name>.php`: two matches is an ambiguous
     * citation and is reported as unresolvable rather than guessed at, which is
     * the same rule the scanner in {@see Config\Support\EnvReadScanner} applies
     * to a name it cannot place.
     */
    private function resolve(string $class): ?string
    {
        $class = ltrim($class, '\\');

        if (str_contains($class, '\\')) {
            // `docs/SETTINGS.md` writes `Tests\Config\GlobFigureDriftTest`,
            // qualified relative to `SugarCraft\Crush\` rather than from the
            // root. Three citations use that shape and the first cut of this
            // resolver called all three dangling, which would have been a
            // census reporting its own gap as the tree's defect.
            foreach ([$class, 'SugarCraft\\Crush\\' . $class] as $candidate) {
                if (class_exists($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        $hits = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/tests'));
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->getBasename('.php') === $class && $file->getExtension() === 'php') {
                $hits[] = $file->getPathname();
            }
        }
        if (\count($hits) !== 1) {
            return null;
        }

        $relative = substr($hits[0], \strlen($this->root() . '/tests/'), -4);
        $fqn = 'SugarCraft\\Crush\\Tests\\' . str_replace('/', '\\', $relative);

        return class_exists($fqn) ? $fqn : null;
    }

    /**
     * Every test class and method `src/` and `docs/` name actually exists.
     *
     * THE ASSERTION IS A LIST, not a loop of `assertTrue()`, so a run reports
     * every dangling citation at once. A rename typically breaks several.
     */
    public function testEveryCitedTestSymbolExists(): void
    {
        $dangling = [];
        foreach ($this->citations() as [$label, $class, $member, $kind]) {
            $fqn = $this->resolve($class);
            if ($fqn === null) {
                $dangling[] = $label . ': ' . $class . ' — no such test class';

                continue;
            }
            if ($kind === 'method' && !method_exists($fqn, $member)) {
                $dangling[] = $label . ': ' . $fqn . '::' . $member . '() — the class exists, the method does not';
            }
            if ($kind === 'constant' && !(new \ReflectionClass($fqn))->hasConstant($member)) {
                $dangling[] = $label . ': ' . $fqn . '::' . $member . ' — the class exists, the constant does not';
            }
        }
        sort($dangling);

        $this->assertSame(
            [],
            $dangling,
            'a doc-block or page cites a test symbol that does not exist; rename the citation '
            . 'in the same commit as the method, or the sentence it supports is unbacked',
        );
    }

    /**
     * And the census is not vacuously empty.
     *
     * `assertSame([], $dangling)` also passes in a tree where the citation
     * scraper has stopped matching anything at all — the same failure mode the
     * glob census guards with a positive control. Here the control is the
     * population: 87 citations were found when this was written (84
     * fully-qualified in `src/`, three bare in `src/`, one backticked in
     * `docs/`), so a floor well under that reds if the scraper breaks while
     * leaving room for the count to move with ordinary work.
     */
    public function testTheCitationCensusFindsTheCitationsThatExist(): void
    {
        $citations = $this->citations();

        $this->assertGreaterThanOrEqual(
            60,
            \count($citations),
            'the citation scraper found almost nothing, so its verdict that nothing is dangling is worthless',
        );

        $labels = array_unique(array_map(static fn (array $c): string => $c[0], $citations));

        $this->assertContains(
            'src/Config/LayeredSettings.php',
            $labels,
            'the file whose dangling {@see} prompted this census is no longer scraped at all',
        );
        $this->assertContains(
            'src/Cli/ArgvParser.php',
            $labels,
            'the bare-citation shape is no longer being scraped, so two of the three shapes are unguarded',
        );
        $this->assertContains(
            'docs/ARCHITECTURE.md',
            $labels,
            'the docs/ half of the scope produced no citation, so a page could rot unremarked',
        );
    }

    /**
     * Nothing that looks like a test citation was dropped for being unreadable.
     *
     * @see unparseable()
     */
    public function testEveryCitationOfATestSymbolIsParseable(): void
    {
        $this->citations();

        $this->assertSame(
            [],
            $this->unparseable,
            'a citation naming a test symbol could not be parsed into a class and a method; '
            . 'teach this census the shape rather than letting it drop the occurrence',
        );
    }

    /**
     * The reported-not-skipped list, exposed so the doc-block above can cite
     * something real.
     *
     * @return list<string>
     */
    public function unparseable(): array
    {
        return $this->unparseable;
    }

    /**
     * The harness answers a question whose answer is already known.
     *
     * RUN BECAUSE THE HARNESS CAN CARRY THE DEFECT IT IS ABOUT: a scraper that
     * silently matched nothing would make every assertion above pass. These
     * fixtures put a known-good and a known-dangling citation through the same
     * `parse()`/`resolve()` path the real census uses.
     */
    public function testTheResolverAgreesWithAnswersAlreadyKnown(): void
    {
        $this->assertSame(
            self::class,
            $this->resolve('\\' . self::class),
            'a fully-qualified citation of THIS class does not resolve, so the resolver is broken',
        );
        $this->assertSame(
            self::class,
            $this->resolve('SymbolCitationDriftTest'),
            'a bare citation of THIS class does not resolve, so the bare shape is unguarded',
        );
        $this->assertNull(
            $this->resolve('SugarCraft\\Crush\\Tests\\NoSuchTest'),
            'a citation of a class that does not exist resolved anyway',
        );
        $this->assertTrue(method_exists(self::class, 'testEveryCitedTestSymbolExists'));
        $this->assertFalse(method_exists(self::class, 'testNoSuchMethodWasEverWritten'));

        $this->assertNull($this->parse('Foo::$property'), 'a property reference parsed as a citation');
        $this->assertNull($this->parse('Foo::bar'), 'a bare lower-case member parsed as a citation');
        $this->assertSame(['Foo\\BarTest', 'testBaz', 'method'], $this->parse('\\Foo\\BarTest::testBaz()'));
        $this->assertSame(['Foo\\BarTest', 'SOME_CONST', 'constant'], $this->parse('Foo\\BarTest::SOME_CONST'));
        $this->assertSame(['BarTest', '', ''], $this->parse('BarTest'));
    }
}
