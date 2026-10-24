<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringCollection;

/**
 * Pins the accessor-deprecation era (2026-10-24, ruling 3b, W-G1).
 *
 * SpringCollection::getTarget() was renamed to its bare twin target()
 * — unclaimed on the class (withTarget() is the fluent builder,
 * setTarget() the deprecated in-place shim; a bare target($id) getter
 * sits beside them cleanly, mirroring the collection's existing bare
 * get()/all()/has() readers). The legacy spelling stays a
 * byte-identical one-line BC forwarder marked '@deprecated' +
 * 'REMOVED-IN: 2.0.0' until v2.0.0.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function deprecatedAccessors(): array
    {
        return [
            'SpringCollection::getTarget' => [SpringCollection::class, 'getTarget', 'target'],
        ];
    }

    /**
     * BC leg: both spellings answer the identical target on a live
     * collection — for the seeded spring and after a withTarget()
     * retarget (the existing SpringCollectionTest needles stay green
     * through the forwarder).
     */
    public function testTheForwarderMatchesTheBareTwinOnALiveCollection(): void
    {
        $collection = (new SpringCollection())->withSpring('a', new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 10.0);
        $this->assertSame(10.0, $collection->target('a'));
        $this->assertSame($collection->target('a'), $collection->getTarget('a'));
        $retargeted = $collection->withTarget('a', 50.0);
        $this->assertSame(50.0, $retargeted->getTarget('a'));
        $this->assertSame($retargeted->target('a'), $retargeted->getTarget('a'));
        $this->assertSame(10.0, $collection->getTarget('a'), 'withTarget() must not mutate the original');
    }
    /**
     * Structural leg: the swept forwarder is a one-line return
     * statement carrying the @deprecated + REMOVED-IN markers and
     * naming its bare twin in the docblock.
     *
     * @param class-string $class
     */
    #[DataProvider('deprecatedAccessors')]
    public function testEverySweptForwarderCarriesMarkersAndSingleReturn(string $class, string $legacy, string $bare): void
    {
        $method = new \ReflectionMethod($class, $legacy);
        $doc = (string) $method->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString(self::MARKER, $doc);
        $this->assertStringContainsString('{@see self::' . $bare . '()}', $doc);
        $this->assertTrue($method->isPublic());
        $lines = \array_slice(
            \file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $this->assertCount(1, $lines, $class . '::' . $legacy . ' must be a single-line forwarder');
        $this->assertMatchesRegularExpression(
            '/public function ' . \preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return \$this->' . \preg_quote($bare, '/') . '\((\$\w+(, \$\w+)*)?\); \}\s*$/',
            $lines[0]
        );
        $bareMethod = new \ReflectionMethod($class, $bare);
        $this->assertTrue($bareMethod->isPublic());
        $this->assertStringNotContainsString(self::MARKER, (string) $bareMethod->getDocComment());
        $this->assertSame($method->getNumberOfParameters(), $bareMethod->getNumberOfParameters());
        $this->assertSame((string) $method->getReturnType(), (string) $bareMethod->getReturnType());
    }

    /**
     * Census leg: discovery-first scan of src/ must find exactly the
     * forwarder under test — no marked get* accessor drifts in.
     */
    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $found = [];
        foreach (self::srcGetAccessors() as [$key, $marked]) {
            if ($marked) {
                $found[] = $key;
            }
        }
        $this->assertSame(['SpringCollection::getTarget'], $found, 'src/ census drifted from the tested pairs');
    }

    /**
     * Inverse leg: every public get*() in src/ must be a marked
     * forwarder — a planted unmarked accessor, or the marker stripped
     * from the swept forwarder, goes red here.
     */
    public function testNoPublicGetAccessorEscapesTheSweep(): void
    {
        $violations = [];
        foreach (self::srcGetAccessors() as [$key, $marked]) {
            if (!$marked) {
                $violations[] = $key;
            }
        }
        $this->assertSame([], $violations, 'public get*() accessors escaped the deprecation sweep');
    }

    /**
     * Token walk of src/: returns [fileBasename::name => carriesMarker]
     * for every public get*() function declaration.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function srcGetAccessors(): array
    {
        $rows = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(\file_get_contents($file->getPathname()));
            $count = \count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]->id !== T_FUNCTION) {
                    continue;
                }
                $name = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->id === T_WHITESPACE) {
                        continue;
                    }
                    $name = $tokens[$j]->text;
                    break;
                }
                if (\preg_match('/^get[A-Z]/', $name) !== 1) {
                    continue;
                }
                $private = false;
                $marked = false;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (
                        $tokens[$k]->id === T_WHITESPACE
                        || $tokens[$k]->id === T_PUBLIC
                        || $tokens[$k]->id === T_STATIC
                        || $tokens[$k]->id === T_FINAL
                        || $tokens[$k]->id === T_ABSTRACT
                        || (\defined('T_READONLY') && $tokens[$k]->id === T_READONLY)
                    ) {
                        continue;
                    }
                    if ($tokens[$k]->id === T_PRIVATE || $tokens[$k]->id === T_PROTECTED) {
                        $private = true;
                        break;
                    }
                    if ($tokens[$k]->id === T_DOC_COMMENT) {
                        $marked = \str_contains($tokens[$k]->text, self::MARKER);
                    }
                    break;
                }
                if ($private) {
                    continue;
                }
                $rows[] = [\basename($file->getFilename(), '.php') . '::' . $name, $marked];
            }
        }
        return $rows;
    }

    /**
     * Docs leg: the README Accessor-deprecation table must list exactly
     * the swept pair (nothing added, nothing dropped).
     */
    public function testTheReadmeTableAgreesWithTheSrcCensus(): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $sections = \preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY);
        $section = (string) $sections[
            \array_key_first(\array_filter(
                $sections,
                static fn (string $s): bool => \str_starts_with($s, 'Accessor deprecation')
            ))
        ];
        \preg_match_all(
            '/^\| `[^`]*` \| `(?P<class>[^`]+)` \| `(?P<legacy>get[A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER
        );
        $this->assertCount(1, $rows, 'README Accessor-deprecation table must list exactly the swept pair');
        $this->assertSame('SpringCollection', $rows[0]['class']);
        $this->assertSame('getTarget', $rows[0]['legacy']);
        $this->assertSame('target', $rows[0]['bare']);
    }
}
