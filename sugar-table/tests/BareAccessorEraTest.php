<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * Accessor-era guard (owner ruling 3b, 2026-10-24).
 *
 * The house convention is bare accessors: property reads ride public
 * `readonly` state or bare methods, never a `getFoo()`-form getter. sugar-table
 * shipped zero such accessors when the window opened — the pre-era audit row
 * ("getStyle() 25-file rename") counts repo-wide `getStyle` SITES, all of
 * them other libraries' own classes (sugar-dashboard/sugar-widgets/sugar-forms);
 * no getStyle() ever existed in this lib's history. This test keeps that
 * verdict from rotting: it walks the shipped public API on every run and
 * fails closed on any re-introduced `getFoo()` accessor, on the removal of a
 * ruled exception, and on drift between the roster here and the README
 * "Accessors" section.
 *
 * Ruled exceptions (see README "Accessors"): `RowData::get($key)` and
 * `RowData::has($key)` are keyed map lookups (fleet single-word lookup
 * vocabulary — `Form::get`, `LruMap::get`), not `getProp()` accessors;
 * `Table::isExpanded($rowIndex)` is a page-indexed predicate with no bare
 * accessor to duplicate.
 */
final class BareAccessorEraTest extends TestCase
{
    /** @var list<string> the ONLY public methods allowed to carry get/has/is vocabulary */
    private const DECLARED_KEEPERS = [
        'SugarCraft\\Table\\RowData::get',
        'SugarCraft\\Table\\RowData::has',
        'SugarCraft\\Table\\Table::isExpanded',
    ];

    public function testNoGetPrefixedPropertyAccessorIsShipped(): void
    {
        $violations = self::getPrefixedAccessors(self::publicApiMethods());

        self::assertSame(
            [],
            $violations,
            'A getFoo()-form public accessor was added to sugar-table. The bare-accessor law forbids it; expose readonly state or a bare method instead (ruling 3b).',
        );
    }

    public function testTheCensusCatchesAGetAccessorWhenOneExists(): void
    {
        // Positive control: prove the walk+filter pair is not vacuous. A
        // sentinel class carrying exactly the forbidden shape must be caught
        // by the same code path that judges the shipped API.
        $sentinel = new class {
            public function getWidget(): int
            {
                return 1;
            }

            public function widget(): int
            {
                return 1;
            }
        };

        $caught = self::getPrefixedAccessors(array_map(
            static fn (ReflectionMethod $m): string => 'Sentinel::' . $m->getName(),
            (new ReflectionClass($sentinel))->getMethods(ReflectionMethod::IS_PUBLIC),
        ));

        self::assertSame(['Sentinel::getWidget'], $caught);
    }

    public function testDeclaredKeepersAreExactlyTheRosteredMethods(): void
    {
        $derived = self::prefixedVocabularyMethods(self::publicApiMethods());
        sort($derived);
        $roster = self::DECLARED_KEEPERS;
        sort($roster);

        self::assertSame(
            $roster,
            $derived,
            'The get/has/is-vocabulary surface drifted from the ruled roster. Adding one requires a ruling + README Accessors entry; removing one is a v2.0.0+ decision, not a drive-by.',
        );
    }

    public function testTheDeprecationWindowIsEmptyForThisLib(): void
    {
        $marked = [];
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $count = substr_count((string) file_get_contents($file), '@deprecated');
            if ($count > 0) {
                $marked[] = basename($file) . ' (' . $count . ')';
            }
        }

        self::assertSame(
            [],
            $marked,
            'A @deprecated accessor appeared in sugar-table. It must be named in the README Accessors deprecation list and mirrored in the roster; nothing here is deprecated today.',
        );
    }

    public function testReadmeAccessorsSectionNamesEveryKeeper(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        self::assertSame(
            1,
            preg_match('/^## Accessors$/m', $readme),
            'README must carry exactly one "## Accessors" section (ruling 3b window note).',
        );

        $section = self::readmeSection($readme, 'Accessors');
        foreach (['RowData::get', 'RowData::has', 'Table::isExpanded'] as $keeper) {
            self::assertSame(
                1,
                substr_count($section, '`' . $keeper),
                'Ruled keeper ' . $keeper . ' must be named exactly once in the README Accessors section — roster and docs move together.',
            );
        }
    }

    /**
     * BC pin for the ruled keepers: each still answers identically to the
     * bare state it queries (forwarder-free, but the equivalence is the law).
     */
    #[DataProvider('keeperEquivalenceCases')]
    public function testKeepersReturnIdenticalValuesToBareState(array $data, string $key): void
    {
        $row = RowData::from($data);
        $all = $row->all();

        self::assertSame($all[$key] ?? null, $row->get($key));
        self::assertSame(array_key_exists($key, $all), $row->has($key));
    }

    /** @return array<string, array{array<string,mixed>, string}> */
    public static function keeperEquivalenceCases(): array
    {
        return [
            'hit'            => [['id' => '1', 'name' => 'Alice'], 'id'],
            'empty-string'   => [['note' => ''], 'note'],
            'null-value'     => [['gone' => null], 'gone'],
            'miss'           => [['id' => '1'], 'absent'],
        ];
    }

    public function testIsExpandedPredicateMatchesTheExpandedSetItQueries(): void
    {
        $table = Table::fromColumns([
            Column::new('name', 'Name', 12),
        ])->withRows([
            Row::new(RowData::from(['name' => 'first'])),
            Row::new(RowData::from(['name' => 'second'])),
        ]);

        $expanded = $table->toggleExpanded(0);

        self::assertTrue($expanded->isExpanded(0));
        self::assertFalse($expanded->isExpanded(1));
        self::assertFalse($table->isExpanded(0), 'toggle returns a new instance; the source must stay unexpanded.');
    }

    // -------------------------------------------------------------------------
    // Walk helpers (pure)
    // -------------------------------------------------------------------------

    /** @return list<string> every public method shipped by this lib as FQCN::method */
    private static function publicApiMethods(): array
    {
        $methods = [];
        foreach (self::shippedClasses() as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getDeclaringClass()->getName(), 'SugarCraft\\Table\\')) {
                    continue; // inherited surface is ruled by its own lib's era walk
                }
                if (str_starts_with($method->getName(), '__')) {
                    continue; // magic methods (__construct/__toString) carry no accessor name
                }
                $methods[] = $class . '::' . $method->getName();
            }
        }
        return $methods;
    }

    /** @return list<class-string> every type declared in src/ */
    private static function shippedClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                self::fail('src file without a namespace declaration: ' . $file);
            }
            if (preg_match_all('/^(?:final |abstract )?(?:class|enum|interface|trait)\s+(\w+)/m', $source, $names) < 1) {
                self::fail('src file declares no type: ' . $file);
            }
            foreach ($names[1] as $name) {
                $fqcn = trim($ns[1]) . '\\' . $name;
                if (!class_exists($fqcn) && !enum_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
                    self::fail('declared type is not autoloadable: ' . $fqcn);
                }
                $classes[] = $fqcn;
            }
        }
        self::assertNotEmpty($classes, 'the shipped-class walk found nothing — guard would run vacuously');
        sort($classes);
        return $classes;
    }

    /** @param list<string> $fqcnMethods @return list<string> the getFoo()-form subset */
    private static function getPrefixedAccessors(array $fqcnMethods): array
    {
        return array_values(array_filter(
            $fqcnMethods,
            static fn (string $m): bool => (bool) preg_match('/::get[A-Z_]/', $m),
        ));
    }

    /** @param list<string> $fqcnMethods @return list<string> the get/has/is-vocabulary subset */
    private static function prefixedVocabularyMethods(array $fqcnMethods): array
    {
        return array_values(array_filter(
            $fqcnMethods,
            static fn (string $m): bool => (bool) preg_match('/::(get|has|is)/', $m),
        ));
    }

    private static function readmeSection(string $document, string $heading): string
    {
        $start = strpos($document, '## ' . $heading);
        self::assertIsInt($start);
        $end = strpos($document, "\n## ", $start + 1);

        return $end === false ? substr($document, $start) : substr($document, $start, $end - $start);
    }
}
