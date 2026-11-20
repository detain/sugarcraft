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
        $fragment = \ApiReference::generate($root, $slug);
        $result = \ApiReference::coverage(\ApiReference::collect($root, $slug), $fragment);

        self::assertCount(10, $result['top'], "$slug has fewer than ten public types, so the coverage figure would not mean the same thing");
        self::assertGreaterThanOrEqual(
            \ApiReference::TOP_COVERAGE_TARGET,
            $result['percent'],
            "$slug top-10 coverage fell below the target; uncovered: " . \implode(', ', \array_filter(
                $result['top'],
                static fn (string $fqn): bool => !\str_contains($fragment, \substr($fqn, \strrpos($fqn, '\\') + 1))
            ))
        );
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

    public function testCoverageCountsTheTenLargestTypes(): void
    {
        $root = $this->fixtureRoot(self::sugarCoreFixture());
        $html = \ApiReference::generate($root, 'sugar-core');
        $result = \ApiReference::coverage(\ApiReference::collect($root, 'sugar-core'), $html);

        self::assertCount(3, $result['top'], 'three fixture types, so the top ten is all of them');
        self::assertSame(3, $result['covered'], 'every fixture type is named in the fragment');
        self::assertSame(100.0, $result['percent']);
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
}
