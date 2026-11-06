<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Stats;

/**
 * Accessor-era census guard (W-R get* sweep GS-13, owner ruling D3b).
 *
 * candy-git-server is a bare-accessor lib by construction: the sweep of
 * 2026-11-06 renamed the only two public get* members that ever shipped —
 * AccessControl::getInstance() and Stats::getInstance() became the bare
 * static factories instance() (their bare slots were free, both carriers
 * are final with no parents/interfaces/traits to collide with, and the
 * census proved zero consumers outside the lib). No @deprecated
 * REMOVED-IN forwarder was minted for them: this lib is a "project"
 * package and every caller lives in this tree, so the old spelling has
 * no consumer to protect until the 2.0.0 window.
 *
 * This test keeps that verdict from rotting. The discovery leg walks every
 * type shipped under src/ on each run and fails closed on any re-grown
 * public `getFoo()` accessor; the sentinel leg proves the walk is not
 * blind; the shape legs pin the renamed singleton pair to its bare
 * spelling and keep the one legal get*vocabulary member (the private
 * header resolver on the HTTP server) from laundering itself public.
 */
final class AccessorCensusTest extends TestCase
{
    /** The renamed singleton pair: bare static factory each, no get* twin. */
    private const RENAMED_FACTORIES = [
        AccessControl::class . '::instance',
        Stats::class . '::instance',
    ];

    /** Get-vocabulary members that stay non-public by ruling (out of scope). */
    private const PRIVATE_KEEPERS = [
        Server::class . '::getUserFromHeaders',
    ];

    public function testNoPublicGetPrefixedAccessorIsShipped(): void
    {
        $violations = self::getPrefixedAccessors(self::publicApiMethods());

        self::assertSame(
            [],
            $violations,
            'A public getFoo()-form accessor appeared in candy-git-server. The bare-accessor law (D3b sweep) forbids it — expose readonly state, a bare method, or a bare static factory instead.',
        );
    }

    public function testTheCensusCatchesAPublicGetAccessorWhenOneExists(): void
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

    public function testRenamedSingletonFactoriesStayBare(): void
    {
        foreach (self::RENAMED_FACTORIES as $fqn) {
            [$class, $bare] = explode('::', $fqn);

            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->hasMethod($bare), "{$class} lost its bare {$bare}() factory");

            $method = $reflection->getMethod($bare);
            self::assertTrue($method->isPublic(), "{$fqn} must stay public");
            self::assertTrue($method->isStatic(), "{$fqn} must stay static");
            self::assertSame('self', (string) $method->getReturnType(), "{$fqn} must keep returning self");

            self::assertFalse(
                $reflection->hasMethod('getInstance'),
                "{$class} grew a getInstance() twin back — the D3b sweep retired that spelling; restore the bare call site instead",
            );
        }

        // The set mutator beside each factory is not get vocabulary; it
        // survives the sweep untouched, so pin its presence to keep the
        // factory pair's docblock contract honest.
        self::assertTrue((new ReflectionClass(AccessControl::class))->hasMethod('setInstance'));
        self::assertTrue((new ReflectionClass(Stats::class))->hasMethod('setInstance'));
    }

    public function testPrivatesCarryingGetVocabularyStayPrivate(): void
    {
        // Bidirectional leg: the zero-census verdict holds only while the
        // rostered keepers remain non-public and still exist — a visibility
        // flip or a drive-by removal of these re-derives the census by force.
        foreach (self::PRIVATE_KEEPERS as $fqn) {
            [$class, $method] = explode('::', $fqn);

            $reflection = new ReflectionClass($class);
            self::assertTrue(
                $reflection->hasMethod($method),
                "rostered keeper {$fqn} is gone from disk — re-derive the census (it may now be a rename candidate)",
            );

            $reflected = $reflection->getMethod($method);
            self::assertFalse($reflected->isPublic(), "{$fqn} must not go public — that breaks the zero-census verdict");
            self::assertFalse($reflected->isStatic(), "{$fqn} is an instance helper, keep it non-static");
        }
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
                if (!str_starts_with($method->getDeclaringClass()->getName(), 'SugarCraft\\GitServer\\')) {
                    continue; // inherited surface is ruled by its own lib's census
                }
                if (str_starts_with($method->getName(), '__')) {
                    continue; // magic methods (__construct/__toString) carry no accessor name
                }
                $methods[] = $class . '::' . $method->getName();
            }
        }
        return $methods;
    }

    /** @return list<class-string> every type declared anywhere under src/ */
    private static function shippedClasses(): array
    {
        $root = new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS);
        $classes = [];
        foreach (new \RecursiveIteratorIterator($root) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                self::fail('src file without a namespace declaration: ' . $file->getPathname());
            }
            if (preg_match_all('/^(?:final |abstract )?(?:class|enum|interface|trait)\s+(\w+)/m', $source, $names) < 1) {
                self::fail('src file declares no type: ' . $file->getPathname());
            }
            foreach ($names[1] as $name) {
                $fqcn = trim($ns[1]) . '\\' . $name;
                if (
                    !class_exists($fqcn) && !enum_exists($fqcn)
                    && !interface_exists($fqcn) && !trait_exists($fqcn)
                ) {
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
}
