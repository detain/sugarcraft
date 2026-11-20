<?php

declare(strict_types=1);

/**
 * Guard for docs/_data/<slug>.api.html (PD2 option A, plan_doc.md section 9).
 *
 * THE FRAGMENT MUST REPRODUCE FROM THE SOURCE. The committed fragment is a pure
 * function of <slug>/src, so this guard regenerates it and compares bytes. A
 * fragment edited by hand, or a PHPDoc or signature change that nobody
 * regenerated, fails here with the command that fixes it. The check reads only
 * the working tree: it does not consult git, so it needs no history and no
 * network.
 *
 * The fixture cases pin the parser's rules, so a change to which members count
 * as public API, or to how a signature is printed, shows up as a deliberate
 * diff here instead of as silent drift in a published page.
 *
 * HOW TO RUN: `phpunit --no-configuration tools/tests/` (CI), or locally
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests/GenApiReferenceTest.php`.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../gen-api-reference.php';

final class GenApiReferenceTest extends TestCase
{
    use TmpTree;

    private const FIXTURE_HOLDER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Fixture;

        /**
         * Holds the `n` value for the demo.
         *
         * A second paragraph that the summary must not include.
         *
         * @see Other
         */
        final class Holder
        {
            /** Builds a holder. */
            public function __construct(public readonly int $n = 0)
            {
            }

            /**
             * Returns a copy with a new value of <b>n</b>.
             *
             * @param int $n the value
             */
            public function withN(int $n): static
            {
                return new self($n);
            }

            /**
             * @internal test seam
             */
            public function seam(): void
            {
            }

            private function hidden(): void
            {
            }

            /** Builds the default holder. */
            public static function make(): self
            {
                $f = static fn (): int => 1;

                return new self($f());
            }

            public function label(): string
            {
                return self::class;
            }
        }

        PHP;

    private const FIXTURE_MODE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Fixture\Sub;

        /** Two modes. */
        enum Mode: string
        {
            case Fast = 'f';
            case Slow = 's';

            /** Human label. */
            public function label(): string
            {
                return 'x';
            }
        }

        PHP;

    private const FIXTURE_CONTRACT = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Fixture;

        interface Contract
        {
            /** Runs the contract. */
            public function run(int $x): ?string;
        }

        PHP;

    private const FIXTURE_INTERNAL = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Fixture;

        /**
         * @internal not part of the public surface
         */
        final class Hidden
        {
            public function x(): void
            {
            }
        }

        PHP;

    /** @var list<string> fixture roots removed in tearDown() */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $dir) {
            $this->removeDir($dir);
        }
        $this->fixtureRoots = [];
        parent::tearDown();
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @param array<string, string> $files relative path => PHP source */
    private function fixtureRoot(array $files): string
    {
        $root = $this->makeTmpTree('apiref-');
        $this->fixtureRoots[] = $root;
        foreach ($files as $rel => $source) {
            $path = $root . '/' . $rel;
            if (!\is_dir(\dirname($path))) {
                \mkdir(\dirname($path), 0777, true);
            }
            \file_put_contents($path, $source);
        }

        return $root;
    }

    /** @return array<string, string> */
    private static function sugarCoreFixture(): array
    {
        return [
            'sugar-core/src/Holder.php' => self::FIXTURE_HOLDER,
            'sugar-core/src/Sub/Mode.php' => self::FIXTURE_MODE,
            'sugar-core/src/Contract.php' => self::FIXTURE_CONTRACT,
            'sugar-core/src/Hidden.php' => self::FIXTURE_INTERNAL,
        ];
    }

    /**
     * A trait with one public and one private method, and a class that uses it
     * through an import of its namespace.
     *
     * @return array<string, string>
     */
    private static function traitFixture(): array
    {
        return [
            'sugar-core/src/Concerns/Counts.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture\\Concerns;\n\ntrait Counts\n{\n    /** Counts one more. */\n    public function tick(): int\n    {\n        return 1;\n    }\n\n    private function hush(): void\n    {\n    }\n}\n",
            'sugar-core/src/Ticker.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nuse Demo\\Fixture\\Concerns\\Counts;\n\n/** Ticks. */\nfinal class Ticker\n{\n    use Counts;\n\n    /** Own method. */\n    public function own(): void\n    {\n    }\n}\n",
        ];
    }

    /**
     * One case per lib in ApiReference::ENABLED, so a fragment a later PR adds
     * is held to the same staleness and coverage gates as sugar-core.
     *
     * @return iterable<string, array{string}>
     */
    public static function enabledSlugs(): iterable
    {
        foreach (\ApiReference::ENABLED as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('enabledSlugs')]
    public function testCommittedFragmentIsCurrent(string $slug): void
    {
        $path = \ApiReference::fragmentPath(self::root(), $slug);
        self::assertFileExists($path, "docs/_data/$slug.api.html is missing; run php tools/gen-api-reference.php $slug");

        $committed = (string) \file_get_contents($path);
        $generated = \ApiReference::generate(self::root(), $slug);

        self::assertSame(
            $generated,
            $committed,
            "docs/_data/$slug.api.html is stale against $slug/src. Regenerate with: php tools/gen-api-reference.php $slug"
        );
    }

    public function testEveryEnabledLibHasOneMarkerInItsBody(): void
    {
        foreach (\ApiReference::ENABLED as $slug) {
            $body = (string) \file_get_contents(self::root() . '/docs/_data/' . $slug . '.body.html');
            self::assertSame(
                1,
                \substr_count($body, \ApiReference::MARKER),
                "docs/_data/$slug.body.html must carry exactly one " . \ApiReference::MARKER
            );
        }
    }

    #[DataProvider('enabledSlugs')]
    public function testTopTenCoverageMeetsTheTarget(string $slug): void
    {
        $root = self::root();
        // Measured on the page the splice produces, the same text gen-docs.php publishes.
        $fragment = \ApiReference::generate($root, $slug);
        $body = (string) \file_get_contents($root . '/docs/_data/' . $slug . '.body.html');
        $page = \str_replace(\ApiReference::MARKER, $fragment, $body);
        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, $slug), $page);

        self::assertCount(10, $result['top'], "$slug has fewer than ten public types, so the coverage figure would not mean the same thing");
        self::assertGreaterThanOrEqual(
            \ApiReference::TOP_COVERAGE_TARGET,
            $result['percent'],
            "$slug top-10 coverage fell below the target: {$result['covered']} of 10 top types have a heading in the page"
        );
    }

    public function testCoverageReadsHeadingsNotProse(): void
    {
        // The prose of a page names most public types, so a name match would read 100 percent
        // with no API reference at all.
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, 'sugar-core'), '<p>Holder Mode Contract Hidden</p>');

        self::assertSame(0, $result['covered']);
        self::assertSame(0.0, $result['percent']);
    }

    public function testCoverageFallsWhenAPublicHeadingIsMissingFromThePage(): void
    {
        // The ranking is built from the source, not from what the page renders, so a heading
        // the page drops must lower the figure. Ranking from the rendered types made this gate
        // report 100 percent whatever the page held.
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');
        $dropped = \str_replace('<h4 id="api-demo-fixture-holder">', '<h4>', $html);
        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, 'sugar-core'), $dropped);

        self::assertCount(3, $result['top']);
        self::assertSame(2, $result['covered']);
        self::assertSame(66.7, $result['percent']);
    }

    public function testTheRankingLeavesOutInternalTypesAndKeepsPublicOnes(): void
    {
        // Both sources read @internal from the docblock. A disagreement between them fails the run
        // (see testADeclarationTheParserCannotSeeFailsTheRun), so the ranking cannot drift from collect().
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $ranked = \array_column(\ApiReference::declaredPublic($root, 'sugar-core'), 'fqn');

        self::assertContains('Demo\Fixture\Holder', $ranked);
        self::assertNotContains('Demo\Fixture\Hidden', $ranked);
    }

    public function testADeclarationTheParserCannotSeeFailsTheRun(): void
    {
        // The line scan finds `class Gated` at column zero, but the token parser only reads
        // top-level declarations, so it skips this one. Silently dropping it would leave the
        // page and the coverage figure both without the type, and the gate would stay green.
        $root = $this->fixtureRoot([
            'sugar-core/src/Gated.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                if (!\function_exists('demo_gate')) {
                class Gated
                {
                }
                }

                PHP,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared but not parsed: [Demo\Fixture\Gated]');
        \ApiReference::generate($root, 'sugar-core');
    }

    public function testTraitMethodsAreListedOnTheClassThatUsesThem(): void
    {
        $root = $this->fixtureRoot(self::traitFixture());
        $html = \ApiReference::generate($root, 'sugar-core');

        // The row appears twice: once in the trait's own table, once under Ticker. Its private method does not appear.
        self::assertSame(2, \substr_count($html, '<tr><td><code>tick(): int</code></td><td>Counts one more.</td></tr>'));
        self::assertStringNotContainsString('hush', $html);
        self::assertStringContainsString('<tr><td><code>own(): void</code></td><td>Own method.</td></tr>', $html);
    }

    public function testATraitImportedUnderAnAliasResolvesThroughTheImport(): void
    {
        $root = $this->fixtureRoot(self::traitFixture() + [
            'sugar-core/src/Clicker.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                use Demo\Fixture\Concerns\Counts as Ticks;

                final class Clicker
                {
                    use Ticks;
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        // Three tables carry it: the trait's own, then Ticker's and Clicker's, which reach it through the alias.
        self::assertSame(3, \substr_count($html, 'tick(): int'), 'the aliased trait feeds Clicker as well as Ticker');
    }

    public function testATraitThatIsNotDeclaredFailsTheRun(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Lost.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Lost\n{\n    use Nowhere;\n}\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a trait declared under sugar-core/src');
        \ApiReference::generate($root, 'sugar-core');
    }

    public function testBackedAndPureEnumsSayWhatPhpProvides(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture() + [
            'sugar-core/src/Plain.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nenum Plain\n{\n    case Only;\n}\n",
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<p>PHP also provides <code>cases()</code>, <code>from()</code> and <code>tryFrom()</code>.</p>', $html);
        self::assertStringContainsString('<p>PHP also provides <code>cases()</code>.</p>', $html);
    }

    public function testAFinalReadonlyClassKeepsBothWords(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Pair.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal readonly class Pair\n{\n}\n",
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<h4 id="api-demo-fixture-pair"><code>Pair</code> <small>final readonly class</small></h4>', $html);
    }

    public function testASizeIsTheTypesOwnSpanNotItsFilesLength(): void
    {
        // A small public type sharing a file with a large @internal one must rank by its own
        // declaration, or the top-10 set describes the file rather than the type.
        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n"
            . "/** @internal */\nfinal class Big\n{\n    public function fill(): string\n    {\n        return str_repeat('x', 400);\n    }\n}\n\n"
            . "final class Small\n{\n}\n";
        $root = $this->fixtureRoot(['sugar-core/src/Shared.php' => $source]);
        $types = \ApiReference::collect($root, 'sugar-core');

        self::assertCount(1, $types, 'the @internal class is not public, so only Small is listed');
        self::assertSame(\strlen("class Small\n{\n}"), $types[0]['size']);
    }

    public function testTwoNamesThatFlattenToOneAnchorAreRefused(): void
    {
        // `Foo_Bar` and `Foo\Bar` both flatten to api-demo-fixture-foo-bar, so an in-page link
        // would reach only one of the two headings.
        $root = $this->fixtureRoot([
            'sugar-core/src/Underscored.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Foo_Bar\n{\n}\n",
            'sugar-core/src/Foo/Bar.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture\\Foo;\n\nfinal class Bar\n{\n}\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('both take the anchor id api-demo-fixture-foo-bar');
        \ApiReference::generate($root, 'sugar-core');
    }

    public function testFragmentIsMarkedGeneratedAndNamesItsSource(): void
    {
        $fragment = \ApiReference::generate(self::root(), 'sugar-core');
        self::assertStringStartsWith('<!-- GENERATED by tools/gen-api-reference.php from sugar-core/src. Do not edit by hand.', $fragment);
    }

    public function testSingleLineInternalMarkerIsLeftOut(): void
    {
        // `/** @internal */` on one line is the form sugar-image and candy-sql use, so it
        // must hide a type and a method exactly as the multi-line form does.
        $root = $this->fixtureRoot([
            'sugar-core/src/Gate.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** @internal */
                final class Leak
                {
                    public function inside(): void
                    {
                    }
                }

                PHP,
            'sugar-core/src/Open.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Public. */
                final class Open
                {
                    /** @internal */
                    public function seam(): void
                    {
                    }

                    /** Visible. */
                    public function visible(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringNotContainsString('Leak', $html);
        self::assertStringNotContainsString('inside', $html);
        self::assertStringNotContainsString('seam', $html);
        self::assertStringContainsString('<code>Open</code>', $html);
        self::assertStringContainsString('visible(): void', $html);
    }

    public function testAnInternalTypeWithBracketedAttributeArgumentsIsLeftOut(): void
    {
        // Attribute arguments may hold brackets, a list spanning lines, or a string with a `]`.
        // The token parser reads the tag through the attribute, so the line scan must read it
        // too, or the run refuses on a disagreement that is not the scan's fault.
        $root = $this->fixtureRoot([
            'sugar-core/src/Seam.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** @internal */
                #[Route([1, 2])]
                #[Tags(
                    ['a', 'b'],
                    "close ] here"
                )]
                final class Seam
                {
                    public function inside(): void
                    {
                    }
                }

                PHP,
            'sugar-core/src/Open.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Public. */
                #[Route([1, 2])]
                final class Open
                {
                    /** Visible. */
                    public function visible(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringNotContainsString('Seam', $html);
        self::assertStringNotContainsString('inside', $html);
        self::assertStringContainsString('<code>Open</code>', $html);
        self::assertSame(['Demo\Fixture\Open'], \array_column(\ApiReference::declaredPublic($root, 'sugar-core'), 'fqn'));
    }

    public function testProseMentioningInternalDoesNotHideAPublicType(): void
    {
        // The word `@internal` in the middle of a sentence is not the tag. The line scan and the
        // parser must both read the type as public, so the run does not refuse on a disagreement.
        $root = $this->fixtureRoot([
            'sugar-core/src/Holder.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Holds a value. A later release may drop it; see the @internal policy notes. */
                final class Holder
                {
                    public function get(): int
                    {
                        return 1;
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<h4 id="api-demo-fixture-holder"><code>Holder</code> <small>final class</small></h4>', $html);
        self::assertContains('Demo\Fixture\Holder', \array_column(\ApiReference::declaredPublic($root, 'sugar-core'), 'fqn'));
    }

    public function testFixtureListsOnlyPublicTypesAndMembersGroupedByNamespace(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<h3>Demo\Fixture</h3>', $html);
        self::assertStringContainsString('<h3>Demo\Fixture\Sub</h3>', $html);
        self::assertStringContainsString('<h4 id="api-demo-fixture-holder"><code>Holder</code> <small>final class</small></h4>', $html);
        self::assertStringContainsString('<h4 id="api-demo-fixture-sub-mode"><code>Mode</code> <small>enum</small></h4>', $html);
        self::assertStringContainsString('<h4 id="api-demo-fixture-contract"><code>Contract</code> <small>interface</small></h4>', $html);

        // @internal types are not public API
        self::assertStringNotContainsString('Hidden', $html);
        // @internal and private methods are left out
        self::assertStringNotContainsString('seam', $html);
        self::assertStringNotContainsString('hidden', $html);
        // `self::class` in a method body is an expression, not a declaration of a type named `class`
        self::assertStringNotContainsString('<code>class</code>', $html);
    }

    public function testFixtureSignaturesAndSummaries(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<tr><td><code>__construct(public readonly int $n = 0)</code></td><td>Builds a holder.</td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>withN(int $n): static</code></td><td>Returns a copy with a new value of &lt;b&gt;n&lt;/b&gt;.</td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>static make(): self</code></td><td>Builds the default holder.</td></tr>', $html);
        // the summary stops at the first blank line, and a {@see} reference is reduced to its target
        self::assertStringContainsString('<p>Holds the <code>n</code> value for the demo.</p>', $html);
        self::assertStringNotContainsString('second paragraph', $html);
    }

    public function testFixtureEnumCasesAndInterfaceMethodsArePublic(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<p>Cases: <code>Fast</code> · <code>Slow</code></p>', $html);
        self::assertStringContainsString('<tr><td><code>run(int $x): ?string</code></td><td>Runs the contract.</td></tr>', $html);
    }

    public function testAParentsPublicMethodsAreListedOnTheChild(): void
    {
        // The child inherits the parent's public method and not its private one. An override
        // appears once per table: the parent's table keeps its own text, the child shows its own.
        $root = $this->fixtureRoot([
            'sugar-core/src/Base.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Base. */
                abstract class Base
                {
                    /** Names the base. */
                    public function name(): string
                    {
                        return 'base';
                    }

                    /** Overridden below. */
                    public function label(): string
                    {
                        return 'base';
                    }

                    private function secret(): void
                    {
                    }
                }

                PHP,
            'sugar-core/src/Child.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Child. */
                final class Child extends Base
                {
                    /** Own label. */
                    public function label(): string
                    {
                        return 'child';
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertSame(2, \substr_count($html, 'Names the base.'), 'the parent row appears under Base and under Child');
        self::assertSame(1, \substr_count($html, 'Overridden below.'), 'the overridden row appears once, under Base');
        self::assertSame(1, \substr_count($html, 'Own label.'), 'the child lists its own override');
        self::assertStringNotContainsString('secret', $html);
    }

    public function testAnInterfaceListsTheMethodsOfTheInterfacesItExtends(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Readable.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                interface Readable
                {
                    /** Reads one line. */
                    public function read(): string;
                }

                PHP,
            'sugar-core/src/Stream.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                interface Stream extends Readable
                {
                    /** Writes one line. */
                    public function write(string $line): void;
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertSame(2, \substr_count($html, 'Reads one line.'), 'read() is listed under Readable and under Stream');
        self::assertStringContainsString('<tr><td><code>read(): string</code></td><td>Reads one line.</td></tr>', $html);
    }

    public function testAnInheritdocSummaryTakesTheSummaryOfTheInterfaceItImplements(): void
    {
        // The class documents the method only with the tag, so its row reads the interface's
        // summary. The tag itself must not reach the page.
        $root = $this->fixtureRoot([
            'sugar-core/src/Gauge.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                interface Gauge
                {
                    /** Reports whether the bit is set. */
                    public function has(int $bit): bool;
                }

                PHP,
            'sugar-core/src/Dial.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                final class Dial implements Gauge
                {
                    /**
                     * {@inheritdoc}
                     */
                    public function has(int $bit): bool
                    {
                        return $bit > 0;
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertSame(2, \substr_count($html, 'Reports whether the bit is set.'), 'Gauge and Dial both show the summary');
        self::assertStringContainsString('<tr><td><code>has(int $bit): bool</code></td><td>Reports whether the bit is set.</td></tr>', $html);
        self::assertStringNotContainsString('inheritdoc', $html);
    }

    public function testAnInheritdocKeepsTheProseTheMethodAddsAfterTheParentSummary(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Base.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Base. */
                class Base
                {
                    /** Names the base. */
                    public function name(): string
                    {
                        return 'base';
                    }
                }

                PHP,
            'sugar-core/src/Child.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Child. */
                final class Child extends Base
                {
                    /** {@inheritdoc} Trims the result. */
                    public function name(): string
                    {
                        return 'child';
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertSame(1, \substr_count($html, '<td>Names the base.</td>'), 'Base keeps its own summary');
        self::assertSame(1, \substr_count($html, '<td>Names the base. Trims the result.</td>'), 'Child reads the parent summary, then its own prose');
        self::assertStringNotContainsString('inheritdoc', $html);
    }

    public function testAnInheritdocWithNoDocumentedAncestorLeavesNoTagInThePage(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Lonely.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Stands alone. */
                final class Lonely
                {
                    /** {@inheritdoc} */
                    public function own(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<tr><td><code>own(): void</code></td><td></td></tr>', $html);
        self::assertStringNotContainsString('inheritdoc', $html);
    }

    /** @return iterable<string, array{string, string}> a file whose published PHPDoc carries an internal reference, and the token the run must name */
    public static function internalReferences(): iterable
    {
        yield 'an audit ID in a method summary' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Leaky\n{\n    /** Reads the bit (sc-23). */\n    public function own(): void\n    {\n    }\n}\n",
            'sc-23',
        ];
        yield 'a finding ID in a type summary' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n/** Holds a value (sugar-core-073). */\nfinal class Leaky\n{\n}\n",
            'sugar-core-073',
        ];
        yield 'a citation of a file outside the repo' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Leaky\n{\n    /** Draws the line (ansicode.txt:223). */\n    public function own(): void\n    {\n    }\n}\n",
            'ansicode.txt',
        ];
        yield 'a review-tracker ID in a method summary' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Leaky\n{\n    /** Fleet-convention factory (lib-reviews-1102 M37). */\n    public function own(): void\n    {\n    }\n}\n",
            'lib-reviews-1102',
        ];
        yield 'an audit ID of the NNb-NN form in a type summary' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n/** Strips bidi overrides (audit 15b-28). */\nfinal class Leaky\n{\n}\n",
            '15b-28',
        ];
    }

    /** @return iterable<string, array{string}> a summary whose only words look like an ID but are not one */
    public static function ordinarySummaries(): iterable
    {
        yield 'a 32-bit width in prose' => ["Reads a 32-bit word."];
        yield 'a plain bare number after a dash' => ["Splits on 15-28 pairs."];
        yield 'a lib name with no ID' => ["Renders the sugar-core frame."];
    }

    #[DataProvider('ordinarySummaries')]
    public function testAnOrdinarySummaryWithADigitAndADashPassesTheGuard(string $summary): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Plain.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n/** $summary */\nfinal class Plain\n{\n}\n",
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString($summary, $html);
    }

    #[DataProvider('internalReferences')]
    public function testAnInternalReferenceInAPublishedSummaryFailsTheRun(string $source, string $token): void
    {
        $root = $this->fixtureRoot(['sugar-core/src/Leaky.php' => $source]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($token);
        \ApiReference::generate($root, 'sugar-core');
    }

    public function testAParentOutsideTheTreeDoesNotFailTheRun(): void
    {
        // A built-in parent has no source under src/, so its members are not listed. The child
        // still renders from its own declarations, and the run does not fail on the parent.
        $root = $this->fixtureRoot([
            'sugar-core/src/Oops.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /** Wraps a failure. */
                final class Oops extends \RuntimeException
                {
                    /** Own method. */
                    public function own(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<tr><td><code>own(): void</code></td><td>Own method.</td></tr>', $html);
        self::assertStringNotContainsString('getMessage', $html);
    }

    public function testFixtureFragmentGoesStaleWhenTheSourceChanges(): void
    {
        $files = self::sugarCoreFixture();
        $root = $this->fixtureRoot($files);
        $committed = \ApiReference::generate($root, 'sugar-core');

        // A public method added after the fragment was written must make the committed text stale.
        \file_put_contents(
            $root . '/sugar-core/src/Holder.php',
            \str_replace('public static function make(): self', "public static function make(): self\n    {\n        return new self(0);\n    }\n\n    public function extra(): int\n    {\n        return 1;\n    }\n\n    public static function unused(): self", $files['sugar-core/src/Holder.php'])
        );
        $regenerated = \ApiReference::generate($root, 'sugar-core');

        self::assertNotSame($committed, $regenerated, 'a new public method must change the fragment, or the drift guard cannot see it');
        self::assertStringContainsString('extra(): int', $regenerated);
    }

    public function testAnUndocumentedTopTypeDoesNotCountAsCovered(): void
    {
        // render() writes a heading for every public type, so a heading-only count would read 100
        // percent over an undocumented API. Bare has a heading and one undocumented method row, so
        // it is not covered, and three covered types out of four falls below the target.
        $root = $this->fixtureRoot(self::sugarCoreFixture() + [
            'sugar-core/src/Bare.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                final class Bare
                {
                    public function go(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');
        self::assertStringContainsString('<h4 id="api-demo-fixture-bare">', $html, 'the heading is rendered, so only the documentation check can fail the type');

        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, 'sugar-core'), $html);

        self::assertCount(4, $result['top']);
        self::assertSame(3, $result['covered']);
        self::assertSame(75.0, $result['percent']);
        self::assertLessThan(\ApiReference::TOP_COVERAGE_TARGET, $result['percent']);
    }

    public function testATypeDocumentedOnlyThroughAMethodRowIsCovered(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Terse.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                final class Terse
                {
                    /** Does the work. */
                    public function work(): void
                    {
                    }
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');
        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, 'sugar-core'), $html);

        self::assertSame(1, $result['covered'], 'a documented method row is enough, as the table shows it');
        self::assertSame(100.0, $result['percent']);
    }

    public function testCoverageCountsTheTenLargestTypes(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');
        $result = \ApiReference::coverage(\ApiReference::declaredPublic($root, 'sugar-core'), $html);

        self::assertCount(3, $result['top'], 'three fixture types, so the top ten is all of them');
        self::assertSame(3, $result['covered'], 'every fixture type is named in the fragment');
        self::assertSame(100.0, $result['percent']);
    }

    public function testAnInlineRenameKeepAnnotationStaysOutOfTheSummary(): void
    {
        // The annotation is a note for the rename tooling. The prose after it is the summary, so
        // it must read as one paragraph with no tag left in the published page.
        $root = $this->fixtureRoot([
            'sugar-core/src/Legacy.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                /**
                 * Imports the old names into the new ones. // @rename-keep
                 * Never overwrites a value the user set.
                 *
                 * @see Other
                 */
                final class Legacy
                {
                }

                PHP,
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('<p>Imports the old names into the new ones. Never overwrites a value the user set.</p>', $html);
        self::assertStringNotContainsString('rename-keep', $html);
        self::assertStringNotContainsString('//', $html);
    }

    public function testSignaturesKeepStringLiteralDefaultsAndByReferenceSigils(): void
    {
        // The default values hold the text a whitespace pattern would rewrite: `' ,'` and `'( y'`.
        // A by-reference method prints its `&` with the name, as PHP writes it.
        $root = $this->fixtureRoot([
            'sugar-core/src/Pads.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Demo\Fixture;

                final class Pads
                {
                    private array $items = [];

                    public function pad(string $fill = ' ,', string $x = '( y',): string
                    {
                        return $fill . $x;
                    }

                    public function &ref(): array
                    {
                        return $this->items;
                    }

                    public function multi(
                        int $a,
                        ?string $b = null,
                    ): ?int {
                        return $a;
                    }
                }

                PHP,
        ]);
        $types = \ApiReference::collect($root, 'sugar-core');

        self::assertSame(
            ["pad(string \$fill = ' ,', string \$x = '( y'): string", '&ref(): array', 'multi(int $a, ?string $b = null): ?int'],
            \array_column($types[0]['methods'], 'signature')
        );
    }

    public function testBracedNamespaceIsRefusedRatherThanSilentlyDropped(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Braced.php' => "<?php\nnamespace Demo {\n    final class Braced {}\n}\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('braced namespace');
        \ApiReference::generate($root, 'sugar-core');
    }

    public function testSlugOutsideThePilotIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not enabled');
        \ApiReference::generate(self::root(), 'sugar-style');
    }

    /**
     * A page built from the fragment must say which inherited methods it leaves out. Without
     * that line a reader checking for getMessage() on ProgramException finds nothing and
     * concludes the method does not exist.
     */
    public function testThePageSaysOutOfTreeParentMethodsAreNotListed(): void
    {
        $root = $this->fixtureRoot([
            'sugar-core/src/Oops.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n/** Wraps a failure. */\nfinal class Oops extends \\RuntimeException\n{\n    /** Own method. */\n    public function own(): void\n    {\n    }\n}\n",
        ]);
        $html = \ApiReference::generate($root, 'sugar-core');

        self::assertStringContainsString('Only methods declared in <code>sugar-core/src</code>', $html);
        self::assertStringContainsString('<code>\RuntimeException</code>', $html);
        self::assertStringContainsString('does not show the methods of that class', $html);
    }

    /** A committed-fragment copy of the CLI, so its mode tests run against a throwaway tree. */
    private function cliRoot(array $files): string
    {
        $root = $this->fixtureRoot($files);
        \mkdir($root . '/tools', 0777, true);
        \mkdir($root . '/docs/_data', 0777, true);
        \copy(self::root() . '/tools/gen-api-reference.php', $root . '/tools/gen-api-reference.php');

        return $root;
    }

    /**
     * Runs the copied CLI in a child PHP process, so its exit codes and its stdout and
     * stderr are the real ones. The script resolves its root from its own location, so
     * the copy under $root reads and writes only the fixture tree.
     *
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(string $root, array $args): array
    {
        $proc = \proc_open(
            [\PHP_BINARY, $root . '/tools/gen-api-reference.php', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        self::assertIsResource($proc, 'could not start the gen-api-reference CLI');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['exit' => \proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, string> one documented public type, so top-10 coverage is 100 percent */
    private static function documentedCoreFixture(): array
    {
        return [
            'sugar-core/src/Holder.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\n/** Holds a value. */\nfinal class Holder\n{\n    /** Own method. */\n    public function own(): void\n    {\n    }\n}\n",
        ];
    }

    public function testCliCheckExitsZeroOnAFreshFragment(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());
        \file_put_contents(\ApiReference::fragmentPath($root, 'sugar-core'), \ApiReference::generate($root, 'sugar-core'));

        $r = $this->runCli($root, ['sugar-core', '--check']);
        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertStringContainsString('ok:', $r['stdout']);
    }

    public function testCliCheckExitsOneOnDriftAndNamesTheFix(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());
        \file_put_contents(\ApiReference::fragmentPath($root, 'sugar-core'), "<!-- hand edited -->\n");

        $r = $this->runCli($root, ['sugar-core', '--check']);
        self::assertSame(1, $r['exit'], 'a stale fragment must fail the check, not pass it');
        self::assertStringContainsString('drift:', $r['stderr']);
        self::assertStringContainsString('php tools/gen-api-reference.php sugar-core', $r['stderr']);
        self::assertSame("<!-- hand edited -->\n", \file_get_contents(\ApiReference::fragmentPath($root, 'sugar-core')), '--check must not write');
    }

    public function testCliCheckExitsOneWhenTheFragmentIsMissing(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());

        $r = $this->runCli($root, ['sugar-core', '--check']);
        self::assertSame(1, $r['exit']);
        self::assertStringContainsString('drift:', $r['stderr']);
    }

    public function testCliWriteModeReplacesAStaleFragmentAndThePassingCheckFollows(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());
        $target = \ApiReference::fragmentPath($root, 'sugar-core');
        \file_put_contents($target, "<!-- stale -->\n");

        $written = $this->runCli($root, ['sugar-core']);
        self::assertSame(0, $written['exit'], $written['stderr']);
        self::assertStringContainsString('wrote', $written['stdout']);
        self::assertSame(\ApiReference::generate($root, 'sugar-core'), \file_get_contents($target));

        $checked = $this->runCli($root, ['sugar-core', '--check']);
        self::assertSame(0, $checked['exit'], $checked['stderr']);
    }

    public function testCliReportExitsZeroAtTheTargetAndOneBelowIt(): void
    {
        $documented = $this->cliRoot(self::documentedCoreFixture());
        $ok = $this->runCli($documented, ['sugar-core', '--report']);
        self::assertSame(0, $ok['exit'], $ok['stderr']);
        self::assertStringContainsString('top-10 coverage 1/1 (100.0%)', $ok['stdout']);

        $bare = $this->cliRoot([
            'sugar-core/src/Bare.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Fixture;\n\nfinal class Bare\n{\n    public function go(): void\n    {\n    }\n}\n",
        ]);
        $below = $this->runCli($bare, ['sugar-core', '--report']);
        self::assertSame(1, $below['exit'], 'an undocumented API is below the target and must exit 1');
        self::assertStringContainsString('(0.0%)', $below['stdout']);
    }

    public function testCliUsageErrorsExitTwo(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());

        foreach ([[], ['bogus-slug'], ['sugar-core', '--bogus'], ['sugar-core', '--check', '--report']] as $args) {
            $r = $this->runCli($root, $args);
            self::assertSame(2, $r['exit'], 'args ' . \json_encode($args) . ' must be a usage error');
            self::assertStringContainsString('usage:', $r['stderr']);
        }

        $outside = $this->runCli($root, ['candy-nope']);
        self::assertSame(2, $outside['exit']);
        self::assertStringContainsString('not enabled', $outside['stderr']);
    }

    public function testCliHelpPrintsTheUsageAndExitsZero(): void
    {
        $root = $this->cliRoot(self::documentedCoreFixture());

        $r = $this->runCli($root, ['--help']);
        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('usage: gen-api-reference.php', $r['stdout']);
    }
}
