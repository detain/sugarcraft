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
 * setTarget() a deprecated shim; a bare target($id) getter
 * sits beside them cleanly, mirroring the collection's existing bare
 * position()/all()/has() readers (get() itself swept by SPR-18). The legacy
 * spellings stay thin BC
 * forwarder marked '@deprecated' + 'REMOVED-IN: 2.0.0' until v2.0.0,
 * loud with E_USER_DEPRECATED since SPR-17 (finding 051).
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
            // SPR-18 (finding 020): the last get-prefix accessor followed
            // the exact same sweep — table above in README mirrors this map.
            'SpringCollection::get' => [SpringCollection::class, 'get', 'position'],
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

        // SPR-17 (finding 051): the forwarder is loud now — exercise the BC
        // legs under a swallowing handler so failOnWarning stays clean.
        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            $this->assertSame($collection->target('a'), $collection->getTarget('a'));
            $retargeted = $collection->withTarget('a', 50.0);
            $this->assertSame(50.0, $retargeted->getTarget('a'));
            $this->assertSame($retargeted->target('a'), $retargeted->getTarget('a'));
            $this->assertSame(10.0, $collection->getTarget('a'), 'withTarget() must not mutate the original');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * SPR-18: the get() forwarder copies the getTarget() precedent EXACTLY —
     * identical answers under both spellings, one loud notice carrying the
     * dated expiry stamp.
     */
    public function testThePositionForwarderAnswersIdenticallyAndWarns(): void
    {
        $collection = (new SpringCollection())->withSpring('a', new Spring(1.0 / 60.0, 6.0, 1.0), 7.0, 0.0, 10.0);
        $this->assertSame(7.0, $collection->position('a'));

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = [$errno, $errstr];
            return true;
        }, E_USER_DEPRECATED);
        try {
            $this->assertSame($collection->position('a'), $collection->get('a'));
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'get() must be loud exactly once');
        $this->assertStringContainsString('position()', $captured[0][1]);
        $this->assertStringContainsString('REMOVED-IN: 2.0.0', $captured[0][1]);
    }
    /**
     * Structural leg: the swept forwarder carries the @deprecated +
     * REMOVED-IN markers, names its bare twin in the docblock, and stays a
     * THIN delegation — at most one notice emission plus a single return of
     * the bare twin, no logic. (SPR-17 widened "one-line" to "thin": the
     * forwarder is loud now, finding 051, and the loudness must not become
     * an excuse for a body.)
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
        $body = \implode('', $lines);
        $this->assertMatchesRegularExpression(
            '/return \$this->' . \preg_quote($bare, '/') . '\((\$\w+(, \$\w+)*)?\);/',
            $body,
            $class . '::' . $legacy . ' must delegate to the bare twin in one return',
        );
        $this->assertSame(
            1,
            \preg_match_all('/\breturn\b/', $body),
            $class . '::' . $legacy . ' must stay a thin forwarder (single return)',
        );
        $this->assertSame(
            1,
            \preg_match_all('/trigger_error\(|emitShimDeprecation\(/', $body),
            $class . '::' . $legacy . ' must be loud exactly once (finding 051)',
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
        $expected = array_keys(self::deprecatedAccessors());
        sort($found);
        sort($expected);
        $this->assertSame(
            $expected,
            $found,
            'src/ census drifted from the tested pairs — every marked public get* forwarder must be in the roster',
        );
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
                if (\preg_match('/^get([A-Z]|$)/', $name) !== 1) {
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
