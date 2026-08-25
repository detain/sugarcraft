<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A CITATION THAT NAMES A TEST WHICH DOES NOT EXIST IS WORSE THAN NO CITATION.
 *
 * `src/`, `tests/` and `docs/` cite the test suite hundreds of times. Those
 * citations are what tells a reader that a doc-block's claim is CHECKED rather
 * than proof-read, and they are the first thing a rename breaks — silently,
 * because nothing compiles a doc-comment. Round 44 shipped one and a reviewer,
 * not a test, found it: {@see \SugarCraft\Crush\Config\LayeredSettings}'
 * `PROJECT_TIER_KEYS` doc-block named a `GlobFigureDriftTest` method in the
 * same commit that renamed it, so the sentence it supported pointed at nothing.
 *
 * ROUND 57 FOUND THAT THIS CENSUS HAD TWO HOLES THE SIZE OF THE DEFECT IT WAS
 * BUILT FOR, and both were holes of SCOPE rather than of logic:
 *
 * - it read `{@see}` in `src/` and backticks in `docs/`, and nothing else, so
 *   a BACKTICKED citation in a `src/` doc-block was invisible. Two real
 *   dangling ones were sitting in exactly that blind spot — in
 *   `src/Tools/Concerns/TruncatesOutput.php` and in `LayeredSettings.php`
 *   itself, the very file the census was written for;
 * - it never read `tests/` at all, though `tests/` carries more citations than
 *   `src/` and `docs/` combined. Four more dangling ones were there.
 *
 * That is rule 11 in its own instrument: a census reports zero for everything
 * its alphabet and its ROSTER cannot express, and this one's roster could not
 * express most of the tree.
 *
 * THE SHAPES, stated rather than implied, because the alphabet IS the coverage:
 *
 * - a `{@see}` in a `.php` file under `src/` or `tests/`, naming a class,
 *   a class plus `::method()`, a class plus a `::CONSTANT`, or a class plus a
 *   `::testMethod` written without parentheses (four sites use that last one);
 * - a BACKTICKED symbol in the same files — the shape a doc-block reaches for
 *   when the sentence wants prose rather than a tool-resolvable reference;
 * - a backticked symbol in a `docs/*.md` page;
 * - and a BARE `{@see someTestMethod()}` inside a `tests/` file, with no class
 *   at all, which resolves against the classes DECLARED IN THAT FILE. Three
 *   dangling ones were found by adding this shape, one of them in a doc-block
 *   whose whole subject is instruments that answer zero when they are dead.
 *
 * RESOLUTION IS A BASE LIST AND THE ORDER IS PART OF THE CONTRACT. A citation
 * is written the way a human reads it, not the way an autoloader does, so a
 * token is tried as written, then under the citing file's own namespace (which
 * is how PHPDoc itself resolves), then under `SugarCraft\Crush\Tests`, then
 * under `SugarCraft\Crush`. Only a token that resolves under NONE of them falls
 * back to a unique-basename search of `tests/`, and a basename with two matches
 * is reported as unresolvable rather than guessed at. The first cut of this
 * resolver had only the last two bases and called TWELVE live citations
 * dangling, which would have been a census reporting its own gap as the tree's
 * defect.
 *
 * THREE KINDS OF TOKEN ARE PROSE AND NOT SYMBOLS, and each exclusion is pinned
 * rather than asserted:
 *
 * - a token ending in a namespace separator is a namespace PREFIX. It cannot
 *   name a class, and treating it as one made the census report the sentence
 *   that describes its own alphabet as a dangling citation;
 * - the bare word for the suffix every test class carries, backticked as an
 *   English word in four files, is not a class. {@see PLACEHOLDER_CLASSES}
 *   holds it with the metasyntactic names, and
 *   {@see testTheNamesTreatedAsProseAreNotRealClasses()} asserts that NONE of
 *   them is a real class or a real file — so the day one becomes real, this
 *   exclusion reds instead of quietly covering it;
 * - and citations of PRODUCTION symbols are out of scope, which is the larger
 *   half. `{@see self::foo()}`, `{@see $this->bar}` and plain class references
 *   are far more numerous and have more shapes; a census of them is a separate
 *   instrument. The test-symbol subset is picked because it is the one that
 *   crosses a boundary the compiler never checks — `src/` cannot autoload
 *   `tests/`, so nothing but prose links them.
 *
 * THIS FILE DOES NOT SPELL A CITATION IT DOES NOT MEAN (rule 26). It is inside
 * its own roster now, so an illustrative class-and-method pair written out in
 * a paragraph here would be a dangling citation in the file whose subject is
 * dangling citations. Every fixture token below is therefore ASSEMBLED from
 * pieces at run time and never appears literally, and the shapes are described
 * in prose rather than shown.
 *
 * ANYTHING THAT LOOKS LIKE A TEST CITATION AND CANNOT BE PARSED IS REPORTED,
 * NOT SKIPPED. {@see unparseable()} collects them and
 * {@see testEveryCitationOfATestSymbolIsParseable()} asserts the list is empty.
 * A guard that quietly ignores what it cannot read has a hole shaped exactly
 * like the next defect — and this file's whole subject is a citation that read
 * as authoritative while resolving to nothing.
 *
 * MEASURED ON PHP 8.3.6, 2026-08-25. `class_exists()`/`method_exists()` and a
 * `preg_match_all()` over prose behave the same on 8.3 and 8.4; this box has no
 * 8.4 while CI runs both, so the stamp is provenance.
 *
 * @internal
 */
final class SymbolCitationDriftTest extends TestCase
{
    /**
     * Tokens that are English, or a stand-in for a name, and never a class.
     *
     * NOT AN EXEMPTION LIST — an ALPHABET statement, and the difference is that
     * {@see testTheNamesTreatedAsProseAreNotRealClasses()} checks it. Each of
     * these is backticked somewhere in the tree as a word rather than as a
     * reference: the suffix itself in four files, the metasyntactic names in
     * two more. If any of them ever becomes a real class, the pin reds and this
     * list has to be revisited rather than silently swallowing citations of it.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_CLASSES = ['Test', 'FooTest', 'BarTest', 'BazTest'];

    /** @var list<string> */
    private array $unparseable = [];

    /**
     * Backticked tokens that name a NAMESPACE rather than a class.
     *
     * ROUND 57's MERGE IS WHY THIS EXISTS, and the mechanism is rule 33's. The
     * backtick widening this round shipped scraped
     * `SugarCraft\Crush\Tests\Backend` out of four test doubles that were
     * explaining which namespace they had been lifted OUT of — correct prose,
     * correct code — and `resolve()` answered null because no CLASS of that
     * name exists. The guard then offered its blessed remedy: rename the
     * citation. Both the citation and the class were right; the CLASSIFIER was
     * the defect, and it was invisible to either lane alone because one lane
     * wrote the doubles and the other widened the scraper.
     *
     * The negative half of {@see testTheResolverAgreesWithAnswersAlreadyKnown()}
     * already covered the `Foo\Bar\` spelling WITH its trailing separator.
     * The spelling without one reached the dangling list instead.
     *
     * Kept rather than dropped so the count is visible: a namespace citation is
     * not an error, but a scraper that started classifying EVERYTHING as one
     * would empty the dangling list, and this list is where that shows.
     */
    private array $namespaceCitations = [];

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
     * A citation is routinely broken over a line boundary by the 80-column
     * wrap, and every leading ` * ` in between belongs to the comment rather
     * than to the reference. Folding is applied to the WHOLE file rather than
     * only inside comments, and the justification for that is REWRITTEN rather
     * than repeated (rule 7).
     *
     * WHAT IT SAID: "the only false fold would be a source line that begins
     * with `*`, which PSR-12 does not produce". WHAT IS TRUE NOW: PSR-12 has
     * nothing to say about a third of this roster. `docs/*.md` is markdown,
     * where a line beginning with `*` is an ordinary bullet — so the argument
     * was scoped to PHP and applied to markdown. (MEASURED at this commit: no
     * page under `docs/` uses `*` bullets, they all use `-`. That is luck, not
     * the reason.) WHY FOLDING IS SAFE ANYWAY, which is the reason that
     * actually holds: a false fold JOINS a bullet onto the line above it and
     * deletes the marker. Every shape this census matches — a `{@see …}`, a
     * backticked token — is self-contained within one line and is matched by a
     * regex with no line anchor, so a fold can neither destroy one nor invent
     * one. It can only change the whitespace around it. A comment-aware pass
     * would cost a tokeniser per file and buy nothing a citation census reads.
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
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/' . $subdir,
            \FilesystemIterator::SKIP_DOTS,
        ));
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
     * The namespace a `.php` source declares, or `''` for one that declares
     * none — which is what a `.md` page and `bin/` script are treated as.
     */
    private function namespaceOf(string $text): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $text, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * Every class-like symbol a source declares, fully qualified.
     *
     * A test FILE is routinely several classes: the test case plus the spies
     * and fakes it drives. A bare `{@see someMethod()}` in such a file names a
     * method on ONE of them, and which one is not stated, so all of them are
     * candidates. The first cut of this scan took only the first declaration
     * and reported six live citations as dangling for it.
     *
     * @return list<string>
     */
    private function classesDeclaredIn(string $text): array
    {
        $namespace = $this->namespaceOf($text);
        preg_match_all(
            '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m',
            $text,
            $m,
        );

        return array_values(array_map(
            static fn (string $short): string => ($namespace === '' ? '' : $namespace . '\\') . $short,
            $m[1],
        ));
    }

    /**
     * Every citation-shaped token in one source, as scraped rows.
     *
     * THE UNIT THE FIXTURE GOES THROUGH (rule 15). The census below is a loop
     * over the roster calling this; a fixture calling it directly therefore
     * exercises the same scraper, which is the only thing that makes an
     * assertion of "nothing is dangling" mean anything at all.
     *
     * @return list<array{label: string, token: string, namespace: string, selfClasses: list<string>, shape: string}>
     */
    private function scrape(string $label, string $text, bool $markdown): array
    {
        $flat = $this->flatten($text);
        $namespace = $markdown ? '' : $this->namespaceOf($text);
        $selfClasses = $markdown ? [] : $this->classesDeclaredIn($text);
        $scope = explode('/', $label)[0];
        $rows = [];

        if (!$markdown) {
            preg_match_all('/\{@see\s+([^}]*)\}/', $flat, $matches);
            foreach ($matches[1] as $body) {
                $token = strtok(trim($body), " \t") ?: '';

                // A BARE `{@see someMethod()}` — no class at all. Only counted
                // inside `tests/`, and only for a member spelled as a test
                // method: elsewhere the same shape is overwhelmingly a
                // production method on the class being documented, which is the
                // out-of-scope half this census does not claim to cover.
                if ($scope === 'tests' && preg_match('/^(test[A-Za-z0-9_]*)\(\)$/', $token, $self) === 1) {
                    $rows[] = [
                        'label' => $label,
                        'token' => $token,
                        'namespace' => $namespace,
                        'selfClasses' => $selfClasses,
                        'shape' => 'see-self',
                    ];

                    continue;
                }
                if (!$this->looksLikeATestSymbol($token)) {
                    continue;
                }
                $rows[] = [
                    'label' => $label,
                    'token' => $token,
                    'namespace' => $namespace,
                    'selfClasses' => [],
                    'shape' => $scope . '-see',
                ];
            }
        }

        preg_match_all('/`([A-Za-z0-9_\\\\]+(?:::[A-Za-z0-9_]+(?:\(\))?)?)`/', $flat, $matches);
        foreach ($matches[1] as $token) {
            if (!$this->looksLikeATestSymbol($token)) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'token' => $token,
                'namespace' => $namespace,
                'selfClasses' => [],
                'shape' => $scope . '-tick',
            ];
        }

        return $rows;
    }

    /**
     * Every citation of a test symbol in the whole roster.
     *
     * @return list<array{label: string, token: string, namespace: string, selfClasses: list<string>, shape: string}>
     */
    private function citations(): array
    {
        $found = [];
        foreach ([['src', 'php'], ['tests', 'php'], ['docs', 'md']] as [$subdir, $extension]) {
            foreach ($this->sources($subdir, $extension) as $label => $text) {
                foreach ($this->scrape($label, $text, $extension === 'md') as $row) {
                    $found[] = $row;
                }
            }
        }

        return $found;
    }

    /**
     * Is this token something the census is claiming to be able to resolve?
     *
     * Deliberately loose about SHAPE and strict about the three prose cases the
     * class doc-block names. A token that LOOKS like a test citation and turns
     * out to be unparseable is reported by the parse step; one that is not
     * recognised here is silently out of scope, so the loose end is the safer
     * side to err on.
     */
    private function looksLikeATestSymbol(string $target): bool
    {
        $class = ltrim(explode('::', $target)[0], '\\');

        // A namespace PREFIX, not a symbol. It cannot name a class, and reading
        // it as one made this census report its own alphabet paragraph — which
        // backticks the `Tests` prefix with its trailing separator — as a
        // dangling citation.
        if ($class === '' || str_ends_with($class, '\\')) {
            return false;
        }
        $short = ltrim(substr($class, (int) strrpos($class, '\\')), '\\');
        if (\in_array($short, self::PLACEHOLDER_CLASSES, true)) {
            return false;
        }
        if (str_contains($target, 'SugarCraft\\Crush\\Tests\\')) {
            return true;
        }

        return str_ends_with($short, 'Test');
    }

    /**
     * A citation token split into a class and, optionally, a member.
     *
     * FOUR MEMBER SHAPES, AND EACH OF THE LAST THREE WAS FOUND BY THIS CENSUS
     * RATHER THAN BY READING. `src/Cli/Bootstrap.php` cites a class CONSTANT,
     * which the first cut reported as unparseable; round 57's widening to
     * `tests/` surfaced four citations of a method written WITHOUT its
     * parentheses. That is the report-rather-than-skip rule working — an
     * unreadable citation surfaced instead of being dropped — and the repair is
     * to teach the shape, never to widen an exemption.
     *
     * THE NO-PARENTHESES SHAPE IS RESTRICTED TO A MEMBER SPELLED AS A TEST
     * METHOD, deliberately. A lower-case member with no parentheses and no
     * `test` prefix is ambiguous — it reads as a property or an enum case just
     * as well as a method — and staying unparseable is the correct answer for
     * an ambiguous token, not a gap.
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
        if (preg_match('/^' . $class . '::(test[A-Za-z0-9_]*)$/', $target, $m) === 1) {
            return [$m[1], $m[2], 'method'];
        }

        return null;
    }

    /**
     * The directory a namespace token maps to, or null if it maps to none.
     *
     * DELIBERATELY STRUCTURAL. The question "is this a namespace?" is answered
     * by a directory existing and holding at least one `.php` file, never by
     * the token's shape or by prose around it — rule 40, after round 56's HOME
     * census was bought with a sentence. An empty directory does not buy the
     * exemption either, because an empty one is where a deleted class used to
     * live and a citation of it IS dangling.
     */
    private function namespaceDirectoryFor(string $class): ?string
    {
        $class = ltrim($class, '\\');

        $roots = [
            'SugarCraft\\Crush\\Tests\\' => $this->root() . '/tests',
            'SugarCraft\\Crush\\' => $this->root() . '/src',
        ];

        foreach ($roots as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $tail = substr($class, \strlen($prefix));
            if ($tail === '') {
                return null;
            }

            $path = $dir . '/' . str_replace('\\', '/', $tail);
            if (!is_dir($path)) {
                return null;
            }

            return glob($path . '/*.php') === [] ? null : $path;
        }

        return null;
    }

    /**
     * A class token as written in prose, resolved to a symbol that exists.
     *
     * THE BASE LIST IS ORDERED AND THE ORDER IS THE CONTRACT, for the reason
     * the class doc-block gives. `$namespace` is the citing file's own, which
     * is how PHPDoc resolves an unqualified reference and is what makes a
     * `RendererTest` cited from the root test namespace mean the root
     * `RendererTest` rather than the one under `Tui`.
     *
     * THE THREE EXTRA `*_exists()` CALLS ARE THREE DIFFERENT CLAIMS, and the
     * paragraph they replace made one claim and offered evidence for a third of
     * it. WHAT IT SAID: that `trait_exists()` AND `interface_exists()` AND
     * `enum_exists()` "are not tidiness", supported by the trait case alone.
     * WHAT IS TRUE NOW, all MEASURED on PHP 8.3.6:
     *
     * - `trait_exists()` EARNS ITS PLACE AND IS PINNED. `class_exists()`
     *   answers false for a trait, live citations under `tests/Support` name
     *   traits, and dropping this call reds
     *   {@see testTheResolverAgreesWithAnswersAlreadyKnown()};
     * - `interface_exists()` EARNS ITS PLACE AND IS NOW PINNED AT THE UNIT
     *   LEVEL. `class_exists()` answers false for an interface too, but no
     *   citation in this tree resolves to one today, so dropping the call
     *   SURVIVED every guard in this file. It is not speculative — the first
     *   interface-shaped test double cited from a doc-block would be reported
     *   dangling — so the fixture drives a real interface through the same
     *   `resolve()` rather than the clause being left unwatched;
     * - `enum_exists()` IS REDUNDANT ON THIS PHP AND STAYS ANYWAY.
     *   `class_exists()` answers TRUE for an enum on 8.3.6 (measured
     *   directly), so dropping `enum_exists()` is an EQUIVALENT mutation that
     *   no test in this suite can kill, and none is written to pretend
     *   otherwise. It stays because it states the alphabet at the point of
     *   decision, and because the equivalence is a language fact rather than a
     *   contract — CI also runs 8.4, which this box cannot test.
     *
     * A BARE token that no base resolves falls back to finding exactly one
     * `tests/**\/<Name>.php`: two matches is an ambiguous citation and is
     * reported as unresolvable rather than guessed at.
     */
    private function resolve(string $class, string $namespace): ?string
    {
        $class = ltrim($class, '\\');

        $bases = [$class];
        if ($namespace !== '') {
            $bases[] = $namespace . '\\' . $class;
        }
        $bases[] = 'SugarCraft\\Crush\\Tests\\' . $class;
        $bases[] = 'SugarCraft\\Crush\\' . $class;

        foreach ($bases as $candidate) {
            if (
                class_exists($candidate)
                || trait_exists($candidate)
                || interface_exists($candidate)
                || enum_exists($candidate)
            ) {
                return $candidate;
            }
        }

        if (str_contains($class, '\\')) {
            return null;
        }

        $hits = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->root() . '/tests',
            \FilesystemIterator::SKIP_DOTS,
        ));
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
     * Every dangling citation in `$rows`, as reportable strings.
     *
     * SEPARATED FROM THE GUARD so a fixture can drive it over rows it built
     * itself. An assertion that a list is empty is worth exactly nothing if the
     * thing that fills the list is broken.
     *
     * @param list<array{label: string, token: string, namespace: string, selfClasses: list<string>, shape: string}> $rows
     *
     * @return list<string>
     */
    private function danglingIn(array $rows): array
    {
        $dangling = [];

        foreach ($rows as $row) {
            if ($row['shape'] === 'see-self') {
                $member = rtrim($row['token'], '()');
                foreach ($row['selfClasses'] as $candidate) {
                    if (method_exists($candidate, $member)) {
                        continue 2;
                    }
                }
                $dangling[] = $row['label'] . ': ' . $row['token']
                    . ' — no class declared in this file has that method ('
                    . (implode(', ', $row['selfClasses']) ?: 'this file declares none') . ')';

                continue;
            }

            $parsed = $this->parse($row['token']);
            if ($parsed === null) {
                $this->unparseable[] = $row['label'] . ': ' . $row['token'];

                continue;
            }
            [$class, $member, $kind] = $parsed;

            $fqn = $this->resolve($class, $row['namespace']);
            if ($fqn === null && $kind === '' && $this->namespaceDirectoryFor($class) !== null) {
                // A NAMESPACE IS NOT A DANGLING CLASS. Structural, not textual
                // (rule 40): the token has to map to a directory that actually
                // holds PHP files, so a made-up `A\B\C` still reds.
                $this->namespaceCitations[] = $row['label'] . ': ' . $row['token'];

                continue;
            }
            if ($fqn === null) {
                // THE RAW TOKEN AND NOT THE PARSED CLASS. Two citations of the
                // same missing class through different shapes would otherwise
                // produce two identical rows, and neither would say what to
                // grep for.
                $dangling[] = $row['label'] . ': ' . $row['token'] . ' — no such test class';

                continue;
            }
            if ($kind === 'method' && !method_exists($fqn, $member)) {
                $dangling[] = $row['label'] . ': ' . $fqn . '::' . $member . '() — the class exists, the method does not';
            }
            if ($kind === 'constant' && !(new \ReflectionClass($fqn))->hasConstant($member)) {
                $dangling[] = $row['label'] . ': ' . $fqn . '::' . $member . ' — the class exists, the constant does not';
            }
        }
        sort($dangling);

        return $dangling;
    }

    /**
     * Every test class, method and constant `src/`, `tests/` and `docs/` name
     * actually exists.
     *
     * THE ASSERTION IS A LIST, not a loop of `assertTrue()`, so a run reports
     * every dangling citation at once. A rename typically breaks several.
     */
    public function testEveryCitedTestSymbolExists(): void
    {
        $this->assertSame(
            [],
            $this->danglingIn($this->citations()),
            'a doc-block or page cites a test symbol that does not exist; rename the citation '
            . 'in the same commit as the method, or the sentence it supports is unbacked',
        );
    }

    /**
     * And the census is not vacuously empty, in any of its six shapes.
     *
     * `assertSame([], $dangling)` also passes in a tree where the citation
     * scraper has stopped matching anything at all. Two controls sit under it,
     * and THE SHAPE CENSUS IS THE ONE THAT IS ACTUALLY HOLDING — say so plainly,
     * because the floor reads as though it were.
     *
     * WHAT THIS SAID: "the FLOOR — 648 citations at round 57, so a floor well
     * under that". WHAT IS TRUE NOW: no definition of this census's output has
     * ever been 648. Measured with {@see citations()} at the commit that wrote
     * the sentence, it was 1122 rows; unique tokens 729; unique (file, token)
     * pairs 900; rows excluding the bare self-citation shape 636. The number
     * was a cardinality over `tests/` shipped in prose with no generator, which
     * is rules 3 and 18 in one clause — and it made the floor of 300 read as
     * tight when it is in fact loose enough that losing the whole self-citation
     * shape AND the whole `tests/` `{@see}` shape would still clear it. WHY THE
     * FLOOR STILL EARNS ITS PLACE: it is a wholesale-breakage tripwire and
     * nothing more — a scraper that matches nothing at all reds here — and it
     * is deliberately far under any plausible population so ordinary work never
     * has to touch it.
     *
     * THE SHAPE CENSUS is the half that would have caught round 57's two holes:
     * losing the backticked shape in `src/`, or the `tests/` half of the
     * roster, or the bare self-citation, leaves the floor comfortably met and
     * empties a key below. No count of any shape is asserted, for the reason
     * the paragraph above is about.
     */
    public function testTheCitationCensusFindsTheCitationsThatExist(): void
    {
        $citations = $this->citations();

        $this->assertGreaterThanOrEqual(
            300,
            \count($citations),
            'the citation scraper found almost nothing, so its verdict that nothing is dangling is worthless',
        );

        $byShape = [];
        foreach ($citations as $row) {
            $byShape[$row['shape']] = ($byShape[$row['shape']] ?? 0) + 1;
        }
        ksort($byShape);

        $this->assertSame(
            ['docs-tick', 'see-self', 'src-see', 'src-tick', 'tests-see', 'tests-tick'],
            array_keys($byShape),
            'a citation SHAPE stopped being scraped. Every one of these was a real dangling '
            . 'citation somewhere in this tree at round 57, and a shape that scrapes nothing '
            . 'is a roster hole reporting itself as a clean tree',
        );

        $labels = array_unique(array_map(static fn (array $c): string => $c['label'], $citations));

        $this->assertContains(
            'src/Config/LayeredSettings.php',
            $labels,
            'the file whose dangling citation prompted this census is no longer scraped at all',
        );
        $this->assertContains(
            'src/Cli/ArgvParser.php',
            $labels,
            'a src/ file that cites the suite through {@see} is no longer scraped at all. '
            . '(This row does NOT control the bare self-citation shape, whatever an earlier '
            . 'message here said: that shape is only emitted for a tests/ file, so it cannot '
            . 'occur in this one. The shape-keys assertion above is what controls it.)',
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
        $this->danglingIn($this->citations());

        $this->assertSame(
            [],
            $this->unparseable,
            'a citation naming a test symbol could not be parsed into a class and a member; '
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
     * The names this census treats as prose are not, in fact, classes.
     *
     * THE PIN UNDER THE ONE EXCLUSION THAT COULD BECOME A LICENCE. Skipping a
     * token because it is "obviously a placeholder" is exactly the shape of
     * hole the next real citation hides in, so the claim is checked in both
     * directions: none of these names resolves as a symbol, and none of them
     * is a file under `tests/`. The day somebody writes one, this reds and the
     * exclusion has to be argued again rather than silently swallowing every
     * citation of it.
     */
    public function testTheNamesTreatedAsProseAreNotRealClasses(): void
    {
        foreach (self::PLACEHOLDER_CLASSES as $name) {
            $this->assertNull(
                $this->resolve($name, 'SugarCraft\\Crush\\Tests'),
                $name . ' resolves to a real symbol, so treating it as prose now hides real citations',
            );
            $this->assertFileDoesNotExist(
                $this->root() . '/tests/' . $name . '.php',
                $name . ' is a real test file now, so this census is skipping citations of it',
            );
        }

        // AND THE POSITIVE HALF, because `assertNull()` is also what a broken
        // resolver returns for everything (E228). A name that IS real must
        // still resolve through the same call.
        $this->assertNotNull(
            $this->resolve('SymbolCitationDriftTest', 'SugarCraft\\Crush\\Tests'),
            'the resolver answers null for a class that exists, so the nulls above prove nothing',
        );
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
        $suffix = 'Test';
        $here = 'SugarCraft\\Crush\\Tests';

        $this->assertSame(
            self::class,
            $this->resolve('\\' . self::class, $here),
            'a fully-qualified citation of THIS class does not resolve, so the resolver is broken',
        );
        $this->assertSame(
            self::class,
            $this->resolve('SymbolCitationDrift' . $suffix, $here),
            'a bare citation of THIS class does not resolve, so the bare shape is unguarded',
        );
        $this->assertNull(
            $this->resolve('SugarCraft\\Crush\\Tests\\NoSuch' . $suffix, $here),
            'a citation of a class that does not exist resolved anyway',
        );

        // THE NAMESPACE BASE, which is what makes an unqualified citation mean
        // what a reader takes it to mean. `Renderer` + the suffix names two
        // files — one at the test root and one under `Tui` — so the bare
        // fallback CANNOT resolve it, and only the citing namespace can.
        $this->assertSame(
            'SugarCraft\\Crush\\Tests\\Renderer' . $suffix,
            $this->resolve('Renderer' . $suffix, $here),
            'the citing namespace is not being tried, so every unqualified citation of an '
            . 'ambiguous basename is reported dangling',
        );
        $this->assertSame(
            'SugarCraft\\Crush\\Tests\\Tui\\Renderer' . $suffix,
            $this->resolve('Renderer' . $suffix, 'SugarCraft\\Crush\\Tests\\Tui'),
            'the citing namespace is being ignored in favour of a fixed base, so a citation '
            . 'resolves to the wrong one of two same-named classes',
        );

        // THE `Tests` BASE, which is how `src/` cites the suite: a file under
        // `SugarCraft\Crush\…` writes the path relative to the test root.
        $this->assertSame(
            'SugarCraft\\Crush\\Tests\\Renderer' . $suffix,
            $this->resolve('Renderer' . $suffix, 'SugarCraft\\Crush'),
            'a src/ file can no longer cite the suite by its test-root-relative path',
        );

        // TRAITS AND INTERFACES, which `class_exists()` alone answers false for.
        $this->assertSame(
            'SugarCraft\\Crush\\Tests\\Support\\HomeSandboxTrait',
            $this->resolve('Support\\HomeSandboxTrait', $here),
            'a citation of a trait is reported dangling, which it was until round 57',
        );

        // AND THE INTERFACE ARM, WHICH THE REAL CENSUS CANNOT EXERCISE. No
        // citation in this tree resolves to an interface today, so dropping
        // `interface_exists()` from the resolver SURVIVED every guard in this
        // file — MEASURED. `class_exists()` answers false for an interface on
        // PHP 8.3.6 (measured directly), so the clause is load-bearing the day
        // a doc-block cites an interface-shaped test double, and this drives it
        // through the same call rather than leaving it unwatched (rule 41: a
        // survivor's neighbours are not covered by the survivor's excuse).
        $this->assertSame(
            'SugarCraft\\Crush\\Providers\\ProviderInterface',
            $this->resolve('Providers\\ProviderInterface', 'SugarCraft\\Crush'),
            'the resolver cannot resolve an interface, so the first interface a doc-block '
            . 'cites will be reported as a dangling citation',
        );

        $this->assertTrue(method_exists(self::class, 'testEveryCitedTestSymbolExists'));
        $this->assertFalse(method_exists(self::class, 'testNoSuchMethodWasEverWritten'));

        // THE PARSER'S ALPHABET, both polarities. Tokens are assembled rather
        // than spelled so this file never carries a citation it does not mean.
        $foo = 'Foo';
        $bar = 'Bar' . $suffix;
        $this->assertNull($this->parse($foo . '::$property'), 'a property reference parsed as a citation');
        $this->assertNull($this->parse($foo . '::bar'), 'a bare lower-case member parsed as a citation');
        $this->assertSame(
            [$foo . '\\' . $bar, 'testBaz', 'method'],
            $this->parse('\\' . $foo . '\\' . $bar . '::testBaz()'),
        );
        $this->assertSame(
            [$foo . '\\' . $bar, 'SOME_CONST', 'constant'],
            $this->parse($foo . '\\' . $bar . '::SOME_CONST'),
        );
        $this->assertSame([$bar, '', ''], $this->parse($bar));
        $this->assertSame(
            [$bar, 'testBazWithNoParentheses', 'method'],
            $this->parse($bar . '::testBazWithNoParentheses'),
            'the parenthesis-less shape four live citations use is unparseable again',
        );

        // AND THE THREE PROSE CASES, which must stay OUT of the alphabet.
        $this->assertFalse(
            $this->looksLikeATestSymbol('SugarCraft\\Crush\\' . $suffix . 's\\'),
            'a namespace prefix is being read as a class, which made this census report its '
            . 'own alphabet paragraph as a dangling citation',
        );
        $this->assertFalse($this->looksLikeATestSymbol($suffix), 'the bare suffix word is being read as a class');
        $this->assertFalse($this->looksLikeATestSymbol($foo . $suffix), 'the metasyntactic name is being read as a class');
        $this->assertTrue(
            $this->looksLikeATestSymbol('Renderer' . $suffix),
            'nothing is in the alphabet any more, so the exclusions above prove nothing',
        );
    }

    /**
     * An EMPTY directory does not buy a namespace exemption.
     *
     * WRITTEN BECAUSE THE CLAUSE SURVIVED A MUTATION. Deleting the
     * `glob(...) === []` arm of {@see namespaceDirectoryFor()} left all six
     * tests in this file green, because no empty directory exists under
     * `tests/` or `src/` for the census to trip over — the survivor was a fact
     * about the tree, not about the guard. Rule 41: a survivor's excuse does
     * not transfer, and the honest answer to an unpinnable clause is to make it
     * pinnable rather than to write a paragraph about why it is not.
     *
     * The directory an emptied namespace leaves behind is exactly where a
     * citation of a DELETED class points, which is the case the clause is for.
     */
    public function testAnEmptyNamespaceDirectoryIsNotANamespace(): void
    {
        $dir = $this->root() . '/tests/EmptyNamespaceProbe';
        self::assertDirectoryDoesNotExist($dir, 'the probe directory is left over from an earlier run');
        self::assertTrue(mkdir($dir), 'could not create the probe directory');

        try {
            $tick = '`';
            $token = 'SugarCraft\\Crush\\Tests\\EmptyNamespaceProbe';
            $source = "<?php\nnamespace SugarCraft\\Crush\\Tests;\n/**\n * "
                . $tick . $token . $tick . "\n */\nfinal class EmptyProbeCiter {}";

            $rows = $this->scrape('tests/EmptyProbe.php', $source, false);
            self::assertCount(1, $rows, 'the probe citation was not scraped, so this test proves nothing');

            self::assertSame(
                ['tests/EmptyProbe.php: ' . $token . ' — no such test class'],
                $this->danglingIn($rows),
                'an EMPTY directory bought a namespace exemption; a citation of a class whose '
                . 'namespace has been emptied is exactly the dangling case this clause exists for',
            );

            // AND THE OTHER POLARITY, through the same call: one `.php` file in
            // the same directory flips it, so the clause is keyed on the
            // directory's CONTENTS and not on its existence.
            self::assertTrue((bool) file_put_contents($dir . '/Placeholder.php', "<?php\n"));
            self::assertSame(
                [],
                $this->danglingIn($this->scrape('tests/EmptyProbe.php', $source, false)),
                'a namespace directory holding a PHP file is still being reported dangling',
            );
        } finally {
            @unlink($dir . '/Placeholder.php');
            @rmdir($dir);
        }
    }

    /**
     * A source this scraper has never seen, whose every citation is known.
     *
     * RULE 15, AND IT IS THE ASSERTION THE REST OF THIS FILE RESTS ON. Every
     * guard above asserts an ABSENCE, and an absence is exactly what a dead
     * scanner reports. So a synthetic source carrying one citation of EVERY
     * shape — three that resolve and three that cannot — goes through
     * `scrape()` and `danglingIn()`, the same two calls the census makes, and
     * both the found-shapes and the dangling list are asserted exactly.
     *
     * THE FIXTURE IS ASSEMBLED AND NEVER SPELLED (rule 26). This file is inside
     * its own roster: a literal citation marker written here would be scraped
     * out of the fixture heredoc by the real census and reported against this
     * file. Every marker below is therefore built from pieces at run time.
     */
    public function testTheScraperFindsEveryShapeInASourceItHasNeverSeen(): void
    {
        $suffix = 'Test';
        $open = '{@' . 'see ';
        $tick = '`';
        $real = 'SymbolCitationDrift' . $suffix;
        $realMethod = 'testEveryCitedTestSymbolExists';
        $dead = 'NoSuchCitation' . $suffix;

        $fixture = implode("\n", [
            '<?php',
            'namespace SugarCraft\\Crush\\Tests;',
            '/**',
            ' * ' . $open . '\\SugarCraft\\Crush\\Tests\\' . $real . '::' . $realMethod . '()}',
            ' * ' . $open . $dead . '}',
            ' * ' . $open . $realMethod . '()}',
            ' * ' . $open . 'testNoMethodOfThisNameExistsHere()}',
            ' * ' . $tick . $real . '::' . $realMethod . '()' . $tick,
            ' * ' . $tick . $dead . '::testWhatever()' . $tick,
            // ROUND 57's MERGE, PINNED IN BOTH DIRECTIONS. The first names a
            // namespace that really is one; the second is spelled identically
            // and maps to no directory. A classifier that waved both through
            // reds on the second, and one that waved neither reds on the first.
            ' * ' . $tick . 'SugarCraft\\Crush\\Tests\\Backend' . $tick,
            ' * ' . $tick . 'SugarCraft\\Crush\\Tests\\NoSuchNamespace' . $tick,
            ' */',
            // THE FIXTURE DECLARES THIS TEST CLASS'S OWN NAME, deliberately.
            // A self-citation can only be checked against a class that is
            // actually loaded, so a made-up host would make the RESOLVING
            // self-cite below unresolvable and the fixture would prove only
            // that the scraper reports everything.
            'final class ' . $real . ' {}',
        ]);

        $rows = $this->scrape('tests/Fixture.php', $fixture, false);

        $this->assertSame(
            ['tests-see', 'tests-see', 'see-self', 'see-self', 'tests-tick', 'tests-tick', 'tests-tick', 'tests-tick'],
            array_map(static fn (array $r): string => $r['shape'], $rows),
            'the scraper did not find one row of each shape in a source that carries exactly '
            . 'one of each; with this red, every "nothing is dangling" assertion above is void',
        );

        $this->assertSame(
            [
                'tests/Fixture.php: ' . $dead . ' — no such test class',
                'tests/Fixture.php: ' . $dead . '::testWhatever() — no such test class',
                // THE NAMESPACE-SHAPED TOKEN THAT MAPS TO NOTHING IS STILL
                // DANGLING. Its sibling one line above it in the fixture —
                // spelled the same way, but a real directory — is absent from
                // this list, and that absence is the other polarity.
                'tests/Fixture.php: SugarCraft\\Crush\\Tests\\NoSuchNamespace — no such test class',
                'tests/Fixture.php: testNoMethodOfThisNameExistsHere()'
                    . ' — no class declared in this file has that method ('
                    . 'SugarCraft\\Crush\\Tests\\' . $real . ')',
            ],
            $this->danglingIn($rows),
            'the four dangling citations in the fixture were not all reported, so the census '
            . 'over the real tree cannot be trusted to report the next one',
        );

        // AND THE NEGATIVE HALF, because a scraper that reported EVERYTHING
        // would satisfy the assertion above too. Prose that merely mentions the
        // words is not a citation.
        $prose = "<?php\nnamespace SugarCraft\\Crush\\Tests;\n/**\n * A "
            . $tick . $suffix . $tick . " suffix, and a " . $tick . 'Foo' . $suffix . $tick
            . " placeholder, and the " . $tick . 'SugarCraft\\Crush\\' . $suffix . 's\\' . $tick
            . " prefix.\n */\nfinal class ProseOnly {}";

        $this->assertSame(
            [],
            $this->scrape('tests/Prose.php', $prose, false),
            'prose that merely spells the suffix, a metasyntactic name or a namespace prefix '
            . 'is being scraped as a citation, which is how this census reported its own '
            . 'alphabet paragraph as the tree\'s defect',
        );
    }
}
