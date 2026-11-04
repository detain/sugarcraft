<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The sugar-crumbs -> sugar-navstack rename ships class_alias shims under
 * aliases/ (per package policy: ONE resolution smoke-test, not a copied
 * suite). Each old FQN must resolve to the SAME canonical class.
 */
final class AliasesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function aliasRoster(): array
    {
        return [
            'NavStack' => ['\SugarCraft\Crumbs\NavStack', \SugarCraft\NavStack\NavStack::class],
            'Breadcrumb' => ['\SugarCraft\Crumbs\Breadcrumb', \SugarCraft\NavStack\Breadcrumb::class],
            'Shell' => ['\SugarCraft\Crumbs\Shell', \SugarCraft\NavStack\Shell::class],
            'Url' => ['\SugarCraft\Crumbs\Url', \SugarCraft\NavStack\Url::class],
            'Escape' => ['\SugarCraft\Crumbs\Escape', \SugarCraft\NavStack\Escape::class],
            'Closable' => ['\SugarCraft\Crumbs\Closable', \SugarCraft\NavStack\Closable::class],
            'NavigationItem' => ['\SugarCraft\Crumbs\NavigationItem', \SugarCraft\NavStack\NavigationItem::class],
            'Lang' => ['\SugarCraft\Crumbs\Lang', \SugarCraft\NavStack\Lang::class],
        ];
    }

    /**
     * @dataProvider aliasRoster
     */
    public function testLegacyFqnResolvesToTheCanonicalClass(string $legacy, string $canonical): void
    {
        $resolves = class_exists($legacy) || interface_exists($legacy);
        self::assertTrue($resolves, "alias {$legacy} does not resolve");
        // An alias loads the stub then reports the canonical name through
        // reflection -- equality of these names proves the shim points at the
        // live class, not at a forked duplicate (interfaces/ctor-less facades
        // included; sugar-treelist ScoringProfile precedent shape).
        self::assertSame(
            $canonical,
            (new \ReflectionClass($legacy))->getName(),
            "{$legacy} does not resolve to {$canonical}"
        );
    }

    public function testRenamedPackageRootStillCarriesItsRenameSentinel(): void
    {
        // tools/rename-lib.php reads '@rename-keep' from the class file; the
        // alias stubs must not become a second, sentinel-less entry point.
        $src = (string) file_get_contents(__DIR__ . '/../src/NavStack.php');
        self::assertStringContainsString('@rename-keep', $src);
    }
}
