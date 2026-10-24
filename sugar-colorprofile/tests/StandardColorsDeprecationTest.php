<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\StandardColors;

/**
 * Pins the deprecation window opened 2026-10-24 (owner ruling 2b) for the
 * process-global mutable color statics on {@see StandardColors}.
 *
 * Two guarantees, both structural (they read the source, not the render):
 *
 *  1. BC — every @deprecated property is STILL a live, writable public static
 *     of type Color. Writing a sentinel and reading it back must observe the
 *     write, then restore the original byte-identically. This proves the
 *     mutators were not accidentally neutered (converted to readonly, dropped,
 *     or shadowed by a constant) — the whole point of a deprecation WINDOW is
 *     that the old surface keeps working until REMOVED-IN: 2.0.0.
 *
 *  2. Census — the number of @deprecated properties equals the number the lib
 *     README's deprecation section claims, and every one of them carries the
 *     "REMOVED-IN: 2.0.0" tag. Guards against silent drift: adding a new
 *     mutable static without documenting it, or quietly re-documenting fewer,
 *     both go red here.
 *
 * @covers \SugarCraft\ColorProfile\StandardColors
 */
final class StandardColorsDeprecationTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function deprecatedPropertyProvider(): array
    {
        $rows = [];
        foreach (self::mutableStatics() as $prop) {
            $rows[$prop->getName()] = [$prop->getName()];
        }
        return $rows;
    }

    /**
     * @dataProvider deprecatedPropertyProvider
     */
    public function testEveryDeprecatedMutatorStillFunctions(string $name): void
    {
        $original = StandardColors::$$name;
        $this->assertInstanceOf(Color::class, $original, "static \$$name must start as a live Color");

        $sentinel = new Color(0x12, 0x34, 0x56);
        StandardColors::$$name = $sentinel;
        try {
            // A write to the deprecated surface is observed on a direct read:
            // the mutator is still wired, not neutered to readonly.
            $this->assertSame($sentinel, StandardColors::$$name, "write to \$$name was not observed");
        } finally {
            // Restore byte-identically so no other test sees a mutated global.
            StandardColors::$$name = $original;
        }
        $this->assertSame($original, StandardColors::$$name, "restore of \$$name did not hold");
    }

    public function testDeprecatedSurfaceIsExactlyTheSixteenMutableStatics(): void
    {
        $this->assertCount(16, self::mutableStatics());
    }

    public function testEveryDeprecatedPropertyCarriesTheRemovalTag(): void
    {
        foreach (self::mutableStatics() as $prop) {
            $doc = (string) $prop->getDocComment();
            $this->assertStringContainsString('@deprecated', $doc, "\${$prop->getName()} missing @deprecated");
            $this->assertStringContainsString('REMOVED-IN: 2.0.0', $doc, "\${$prop->getName()} missing removal tag");
        }
    }

    /**
     * The README's deprecation section must claim the same count of deprecated
     * process-global statics that reflection finds in the code. A mismatch is
     * silent doc drift.
     */
    public function testDeprecatedCountMatchesReadmeDocumentation(): void
    {
        $readme = \dirname(__DIR__) . '/README.md';
        $this->assertFileExists($readme);

        $text = (string) \file_get_contents($readme);
        $section = self::section($text, '## Deprecation: StandardColors mutable statics');
        $this->assertNotNull($section, 'README is missing the StandardColors deprecation section');

        // The section states the count as "**N** ... deprecated" (may wrap).
        $flat = \trim((string) \preg_replace('/\s+/', ' ', $section));
        $matched = \preg_match('/\*\*(\d+)\*\*[^.]*\bdeprecated\b/i', $flat, $m);
        $this->assertSame(1, $matched, 'README deprecation section does not state a count');
        $documented = (int) $m[1];

        $this->assertSame(
            count(self::mutableStatics()),
            $documented,
            'README-documented deprecated static count drifts from the code'
        );
    }

    /**
     * Public, static, typed-Color properties on StandardColors — the mutable
     * global surface under deprecation. Derived by reflection so this stays
     * honest as the class changes shape.
     *
     * @return list<ReflectionProperty>
     */
    private static function mutableStatics(): array
    {
        $props = [];
        foreach ((new ReflectionClass(StandardColors::class))->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_STATIC) as $prop) {
            if ((string) $prop->getType() === Color::class) {
                $props[] = $prop;
            }
        }
        return $props;
    }

    private static function section(string $text, string $heading): ?string
    {
        $start = \strpos($text, $heading);
        if ($start === false) {
            return null;
        }
        $rest = \substr($text, $start + \strlen($heading));
        $next = \strpos($rest, "\n## ");
        return $next === false ? $rest : \substr($rest, 0, $next);
    }
}
