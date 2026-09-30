<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The façade guard for sugar-prompt's `class_alias` re-exports.
 *
 * sugar-prompt is a maintained back-compat façade over candy-forms (plus one
 * cross-library alias into candy-fuzzy). Per the project façade law
 * (AGENTS.md §"Façade = alias smoke-test only", precedent #1275/#1312/#1314)
 * this library ships NO copy of candy-forms' behavioural suites — behaviour
 * is tested once against the canonical implementation. What stays worth
 * asserting here is the façade contract itself:
 *
 *  1. every `class_alias()` call site in `src/` still resolves to a live type
 *     (`class_exists` alone is interface-blind — the root `Field` alias points
 *     at an INTERFACE, and `SugarCraft\Prompt\Field\Field` shipped broken for
 *     two rounds because a class-only check could not see through it);
 *  2. each alias resolves to the canonical FQN its shim file declares —
 *     `ReflectionClass::getName()` normalises an alias to its original, so a
 *     renamed or wrong target fails the assertSame;
 *  3. the roster is not hand-maintained: it is walked token-by-token out of
 *     `src/`, so a new shim file joins the contract automatically (and a shim
 *     the token parser cannot read reddens the double-methodology census
 *     instead of silently vanishing);
 *  4. candy-forms classes are either aliased here or explicitly ruled out
 *     (see https://github.com/sugarcraft/sugar-prompt/issues/20).
 *
 * The only non-`class_alias` shims are the `HasDynamicLabels` / `HasHideFunc`
 * stub CLASSES — PHP cannot alias traits, so those are name-keepers whose
 * declared asymmetry is pinned by {@see testTraitStubsAreNameKeepersNotReExports()}.
 */
final class AliasResolutionTest extends TestCase
{
    /**
     * The house spelling of a real call: `class_alias(\Canonical::class, ...)`.
     * The two trait-stub docblocks mention `class_alias()` in prose — the
     * trailing backslash keeps the string census from accusing comments, so
     * both census methodologies count exactly the executed calls.
     */
    private const CALL_MARKER = 'class_alias(\\';

    /**
     * Floor on the roster size. The façade re-exports every root, Field,
     * Validator and Fuzzy shim (23 at the time of writing); a walk that went
     * blind on the tree would drop far below this and must fail loudly rather
     * than go green off an empty provider.
     */
    private const MIN_ALIAS_COUNT = 20;

    /**
     * Every `class_alias()` call in sugar-prompt/src must denote a live type.
     */
    #[DataProvider('aliasProvider')]
    public function testAliasResolvesToALiveType(string $alias, string $target): void
    {
        self::assertTrue(
            \class_exists($alias) || \interface_exists($alias) || \enum_exists($alias),
            "Façade alias {$alias} is not a defined type — the shim's class_alias() target "
            . "(declared as {$target}) does not exist, so the alias never registers.",
        );
    }

    /**
     * Each alias must normalise to exactly the canonical FQN its shim declares.
     */
    #[DataProvider('aliasProvider')]
    public function testAliasResolvesToTheDeclaredCanonicalTarget(string $alias, string $target): void
    {
        self::assertTrue(
            \class_exists($target) || \interface_exists($target) || \enum_exists($target),
            "Canonical target {$target} declared by the {$alias} shim does not exist.",
        );

        self::assertSame(
            $target,
            (new \ReflectionClass($alias))->getName(),
            "{$alias} no longer resolves to {$target} — the façade drifted from its declared target.",
        );
    }

    /**
     * Cross-check: the set of src files carrying a `class_alias(\` literal
     * must equal the set of files that yielded roster rows. If the token
     * parser ever goes blind on a new call spelling, the file still counts as
     * a literal and this reddens — the roster cannot silently shrink.
     */
    public function testEveryClassAliasLiteralWasParsedIntoTheRoster(): void
    {
        $literalFiles = [];
        foreach (self::srcFiles() as $file) {
            if (\str_contains((string) \file_get_contents($file), self::CALL_MARKER) === TRUE) {
                $literalFiles[(string) \realpath($file)] = true;
            }
        }

        $parsedFiles = [];
        foreach (self::roster() as $row) {
            $parsedFiles[$row[0]] = true;
        }

        self::assertSame(
            \array_keys($literalFiles),
            \array_keys($parsedFiles),
            'Files carrying a class_alias() literal and files yielding roster rows disagree — '
            . 'the token walk missed (or invented) a call site.',
        );
    }

    /**
     * The roster must be large enough to be the whole façade, and must name
     * the shapes the old hand-list forgot: the cross-library Fuzzy alias, the
     * same-name interface alias at the namespace root, and the late field
     * widgets that shipped without ever joining the manual provider.
     */
    public function testRosterCoversTheWholeFacadeSurface(): void
    {
        $roster = self::roster();

        self::assertGreaterThanOrEqual(
            self::MIN_ALIAS_COUNT,
            count($roster),
            'The src walk found suspiciously few class_alias() call sites — it went blind?',
        );

        $aliases = \array_column($roster, 1);
        foreach ([
            \SugarCraft\Prompt\Field::class,               // same-name INTERFACE alias (audit: class_exists was blind to it)
            \SugarCraft\Prompt\AsyncValidatable::class,    // root-level shim the hand-list missed
            \SugarCraft\Prompt\Field\Date::class,          // late joiners the hand-list missed
            \SugarCraft\Prompt\Field\Color::class,
            \SugarCraft\Prompt\Field\Slider::class,
            \SugarCraft\Prompt\Validator\Required::class,
            \SugarCraft\Prompt\Fuzzy\FuzzyMatcher::class,  // the ONLY alias whose target leaves candy-forms
        ] as $required) {
            self::assertContains($required, $aliases, "The walk must cover {$required} without a hand-list.");
        }

        self::assertSame(
            \SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher::class,
            $roster[\SugarCraft\Prompt\Fuzzy\FuzzyMatcher::class][2],
            'FuzzyMatcher must keep pointing at the candy-fuzzy SSOT, not a candy-forms fossil.',
        );
    }

    /**
     * The honest replacement for the deleted `FieldAliasTest::testPromptFieldClassDoesNotExist`:
     * the root `Field` alias IS live, as an INTERFACE re-export. `class_exists`
     * reports false for it — PHP's interface blindness, not a missing shim —
     * so asserting either polarity alone once hid a broken alias in one
     * direction and lied about this one in the other.
     */
    public function testRootFieldAliasIsTheInterfaceReExportItself(): void
    {
        self::assertFalse(
            \class_exists(\SugarCraft\Prompt\Field::class),
            'SugarCraft\Prompt\Field aliases an interface: class_exists must stay false — flip this with the language, not the shim.',
        );
        self::assertTrue(
            \interface_exists(\SugarCraft\Prompt\Field::class),
            'SugarCraft\Prompt\Field must resolve as the interface re-export of SugarCraft\Forms\Field.',
        );
        self::assertSame(
            \SugarCraft\Forms\Field::class,
            (new \ReflectionClass(\SugarCraft\Prompt\Field::class))->getName(),
        );
        // A concrete façade field satisfies the same-named interface contract.
        self::assertInstanceOf(
            \SugarCraft\Forms\Field::class,
            \SugarCraft\Prompt\Field\Input::new('probe'),
        );
    }

    /**
     * `HasDynamicLabels` / `HasHideFunc` are the two src shims that are NOT
     * `class_alias` calls: PHP cannot alias traits, so these are empty stub
     * CLASSES that keep the old FQN loadable without re-exporting behaviour.
     * Pin the declared asymmetry so nobody "fixes" the walk's silence about
     * them — and so a stub never accretes copy-pasted trait logic here.
     */
    public function testTraitStubsAreNameKeepersNotReExports(): void
    {
        foreach ([
            ['SugarCraft\\Prompt\\HasDynamicLabels', 'SugarCraft\\Forms\\HasDynamicLabels'],
            ['SugarCraft\\Prompt\\HasHideFunc', 'SugarCraft\\Forms\\HasHideFunc'],
        ] as [$stub, $canonicalTrait]) {
            self::assertTrue(\class_exists($stub), "{$stub} must stay loadable for back-compat.");
            self::assertTrue(\trait_exists($canonicalTrait), "{$canonicalTrait} is the canonical trait.");

            $reflection = new \ReflectionClass($stub);
            self::assertSame(
                [],
                $reflection->getMethods(),
                "{$stub} is a name-keeper: it must stay empty and never re-implement the trait.",
            );
            self::assertSame([], $reflection->getProperties());
        }
    }

    /**
     * Reverse contract: every class/interface/enum under candy-forms' src must
     * either be aliased here (and alias BACK to its exact FQN) or sit in one
     * of the ruled-out namespaces below. New candy-forms symbols in active
     * namespaces redden this until someone decides — explicitly — whether the
     * façade re-exports them.
     */
    public function testEveryCanonicalFormsClassIsAliasedOrRuledOut(): void
    {
        $formsDir = \dirname(__DIR__) . '/vendor/sugarcraft/candy-forms/src';
        $roster = self::roster();

        $ruledOut = [
            'Viewport\\',
            'TextArea\\',
            'Spinner\\',     // sugar-prompt ships its own lib-local Spinner (its one real implementation)
            'Scrollbar\\',
            'ItemList\\',
            'FilePicker\\',  // widget namespace; the Field\FilePicker ALIAS is covered under 'Field\\'
            'Vim\\',
            'TextInput\\',
            'Cursor\\',
            'Util\\',        // RenderSafe — not part of the façade surface
            'Lang',          // i18n utility — not part of the façade surface
        ];

        $missing = [];
        foreach (self::formsClasses($formsDir) as $formsClass) {
            $shortName = \substr($formsClass, \strlen('SugarCraft\\Forms\\'));

            if (\str_starts_with($shortName, 'Fuzzy\\') === TRUE) {
                continue; // deprecated inside candy-forms itself; the façade points at candy-fuzzy instead.
            }

            $promptAlias = 'SugarCraft\\Prompt\\' . $shortName;

            if (\array_key_exists($promptAlias, $roster) === FALSE) {
                foreach ($ruledOut as $ruled) {
                    if (\str_starts_with($shortName, $ruled) === TRUE) {
                        continue 2;
                    }
                }
                $missing[] = $shortName;
                continue;
            }

            // The roster row must agree with the canonical file name, so an
            // alias cannot hide under the wrong target and still pass.
            self::assertSame(
                $formsClass,
                $roster[$promptAlias][2],
                "{$promptAlias} is in the roster but not pointed at {$formsClass}.",
            );
        }

        self::assertEmpty(
            $missing,
            'New candy-forms classes found without a sugar-prompt re-export or a ruling: ' . \implode(', ', $missing),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aliasProvider(): iterable
    {
        foreach (self::roster() as [$file, $alias, $target]) {
            yield $alias => [$alias, $target];
        }
    }

    /**
     * Walk sugar-prompt/src and parse every `class_alias(\Canonical::class, Bare::class)`
     * call into a roster keyed by alias FQN: [realpath, aliasFqn, targetFqn].
     *
     * Fail-closed: a file that carries the call marker but yields no parsed
     * row aborts with a loud error rather than shrinking the roster.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function roster(): array
    {
        static $roster = null;
        if ($roster !== null) {
            return $roster;
        }

        $roster = [];
        foreach (self::srcFiles() as $file) {
            $code = (string) \file_get_contents($file);
            $rows = self::parseAliasCalls($code, (string) \realpath($file));
            if ($rows === [] && \str_contains($code, self::CALL_MARKER) === TRUE) {
                throw new \LogicException("{$file} carries a class_alias() call marker but the token walk parsed nothing — extend the parser; do not let the roster go blind.");
            }
            foreach ($rows as [$alias, $target]) {
                $roster[$alias] = [(string) \realpath($file), $alias, $target];
            }
        }

        return $roster;
    }

    /**
     * @return list<string> every PHP file under sugar-prompt/src, sorted
     */
    private static function srcFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        \sort($files);

        return $files;
    }

    /**
     * @return list<string> every declared type FQN (traits excluded — they
     *         cannot ride a class_alias) under the candy-forms src dir
     */
    private static function formsClasses(string $formsDir): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($formsDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $className = 'SugarCraft\\Forms\\' . \str_replace('/', '\\', \substr($file->getPathname(), \strlen($formsDir) + 1, -4));
            // PSR-4 shape only; multi-class files or drifted names are not
            // part of the façade contract and resolve to nothing here.
            if (\trait_exists($className) === TRUE) {
                continue;
            }
            if (\class_exists($className) || \interface_exists($className) || \enum_exists($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Token-level parse of one shim file. Recognises the house spelling
     * `class_alias(\Canonical\Path::class, Bare::class);` — a fully-qualified
     * first argument and a bare second argument in the file's own namespace.
     * Anything else fails CLOSED: a parser that guesses widens the façade
     * silently, which is the failure class this whole rewrite exists to end.
     *
     * @return list<array{0: string, 1: string}>  [aliasFqn, targetFqn]
     */
    private static function parseAliasCalls(string $code, string $file): array
    {
        $tokens = \PhpToken::tokenize($code);
        $count = \count($tokens);
        $namespace = self::namespaceOf($tokens, $count, $file);

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_STRING || $tokens[$i]->text !== 'class_alias') {
                continue;
            }

            $cursor = self::skipTrivia($tokens, $i + 1, $count, $file);
            if ($tokens[$cursor]->text !== '(') {
                throw new \LogicException("class_alias without a readable '(' in {$file}.");
            }

            // Argument 1: \Canonical\Path::class
            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            if ($tokens[$cursor]->id !== T_NAME_FULLY_QUALIFIED) {
                throw new \LogicException("class_alias target in {$file} is not a fully-qualified name — the parser knows ONE spelling; extend it deliberately.");
            }
            $target = \ltrim($tokens[$cursor]->text, '\\');

            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            self::expect($tokens, $cursor, T_DOUBLE_COLON, '::', $file);
            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            // The `class` of `::class` lexes as T_CLASS, not T_STRING.
            self::expect($tokens, $cursor, T_CLASS, 'class', $file);

            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            self::expect($tokens, $cursor, null, ',', $file);

            // Argument 2: Bare::class (resolved against the file's namespace)
            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            if ($tokens[$cursor]->id !== T_STRING) {
                throw new \LogicException("class_alias alias name in {$file} is not a bare ::class reference.");
            }
            $alias = $namespace . '\\' . $tokens[$cursor]->text;

            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            self::expect($tokens, $cursor, T_DOUBLE_COLON, '::', $file);
            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            self::expect($tokens, $cursor, T_CLASS, 'class', $file);

            $cursor = self::skipTrivia($tokens, $cursor + 1, $count, $file);
            self::expect($tokens, $cursor, null, ')', $file);

            $rows[] = [$alias, $target];
        }

        return $rows;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function namespaceOf(array $tokens, int $count, string $file): string
    {
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_NAMESPACE) {
                continue;
            }
            $parts = [];
            for ($j = $i + 1; $j < $count; $j++) {
                // Single-char tokens carry no T_* constants — compare text.
                if ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
                    break;
                }
                if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                    $parts[] = $tokens[$j]->text;
                }
            }
            if ($parts === []) {
                throw new \LogicException("No namespace readable in {$file}.");
            }

            return \implode('\\', $parts);
        }

        throw new \LogicException("{$file} declares no namespace.");
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function skipTrivia(array $tokens, int $from, int $count, string $file): int
    {
        for ($j = $from; $j < $count; $j++) {
            if ($tokens[$j]->id === T_WHITESPACE || $tokens[$j]->id === T_COMMENT || $tokens[$j]->id === T_DOC_COMMENT) {
                continue;
            }

            return $j;
        }

        throw new \LogicException("Truncated class_alias call in {$file}.");
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param ?int $id expected token id, or null to compare only the literal text
     */
    private static function expect(array $tokens, int $cursor, ?int $id, string $text, string $file): void
    {
        if ($tokens[$cursor]->text !== $text || ($id !== null && $tokens[$cursor]->id !== $id)) {
            throw new \LogicException("class_alias call in {$file} deviates from the house spelling near '{$text}'.");
        }
    }
}
