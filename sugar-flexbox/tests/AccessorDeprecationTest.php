<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Viewport;

/**
 * Pins the accessor-deprecation era (2026-10-24, ruling 3b).
 *
 * Viewport's dimension accessors were renamed to their bare twins —
 * width()/height() were unclaimed in-lib (the mutators are the
 * setWidth()/setHeight() spellings, so there is no fluent collision).
 * The legacy get*() spellings stay byte-identical one-line BC
 * forwarders marked '@deprecated' + 'REMOVED-IN: 2.0.0' until v2.0.0.
 * The delegates still answer through the canonical widgets viewport's
 * own names; that hop belongs to the wrapped class, not this lib's era.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<mixed>}>
     */
    public static function deprecatedAccessors(): array
    {
        return [
            'Viewport::getWidth' => [Viewport::class, 'getWidth', 'width', []],
            'Viewport::getHeight' => [Viewport::class, 'getHeight', 'height', []],
        ];
    }

    private static function fixture(string $class): object
    {
        /** @var class-string $class */
        return match ($class) {
            Viewport::class => Viewport::new(40, 12),
            default => self::fail("no fixture for {$class}"),
        };
    }

    /**
     * BC leg: the deprecated name must return exactly what the bare
     * twin returns, on the same live object, with the same arguments.
     *
     * @param class-string $class
     * @param list<mixed> $args
     */
    #[DataProvider('deprecatedAccessors')]
    public function testTheDeprecatedForwarderMatchesTheBareTwin(string $class, string $legacy, string $bare, array $args): void
    {
        $object = self::fixture($class);
        $this->assertTrue(method_exists($object, $bare), $class . "::" . $bare . "() must exist");
        $this->assertSame($object->{$bare}(...$args), $object->{$legacy}(...$args));
    }

    /**
     * Structural leg: every swept forwarder is a one-line return
     * statement carrying the @deprecated + REMOVED-IN markers and
     * naming its bare twin in the docblock.
     *
     * @param class-string $class
     */
    #[DataProvider('deprecatedAccessors')]
    public function testEverySweptForwarderCarriesMarkersAndSingleReturn(string $class, string $legacy, string $bare, array $args): void
    {
        $method = new \ReflectionMethod($class, $legacy);
        $doc = (string) $method->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString(self::MARKER, $doc);
        $this->assertStringContainsString('{@see self::' . $bare . '()}', $doc);
        $this->assertTrue($method->isPublic());
        $lines = array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $this->assertCount(1, $lines, $class . '::' . $legacy . ' must be a single-line forwarder');
        $this->assertMatchesRegularExpression(
            '/public function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return \$this->' . preg_quote($bare, '/') . '\((\$\w+(, \$\w+)*)?\); \}\s*$/',
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
     * forwarders under test — no marked get* accessor drifts in, and the
     * swept names must not reappear unmarked elsewhere.
     */
    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if ($token->id !== T_FUNCTION) {
                    continue;
                }
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->id === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
                $name = $tokens[$j]->text;
                if (preg_match('/^get[A-Z]/', $name) !== 1) {
                    continue;
                }
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (
                        $tokens[$k]->id === T_WHITESPACE
                        || $tokens[$k]->id === T_PUBLIC
                        || $tokens[$k]->id === T_STATIC
                        || $tokens[$k]->id === T_FINAL
                        || $tokens[$k]->id === T_ABSTRACT
                    ) {
                        continue;
                    }
                    break;
                }
                if ($tokens[$k]->id !== T_DOC_COMMENT || !str_contains($tokens[$k]->text, self::MARKER)) {
                    continue;
                }
                $found[] = basename($file->getFilename(), '.php') . '::' . $name;
            }
        }
        $expected = [];
        foreach (self::deprecatedAccessors() as [$class, $legacy]) {
            $expected[] = substr($class, (int) strrpos($class, '\\') + 1) . '::' . $legacy;
        }
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found, 'src/ census drifted from the tested pairs');
        $this->assertCount(2, $found);
    }

    /**
     * Docs leg: the README Accessor-deprecation table must list exactly
     * the swept pairs (nothing added, nothing dropped).
     */
    public function testTheReadmeTableAgreesWithTheSrcCensus(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        $sections = preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY);
        $section = (string) $sections[
            array_key_first(array_filter(
                $sections,
                static fn (string $s): bool => str_starts_with($s, 'Accessor deprecation')
            ))
        ];
        preg_match_all(
            '/^\| `[^`]*` \| `(?P<class>[^`]+)` \| `(?P<legacy>get[A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER
        );
        $this->assertCount(2, $rows, 'README Accessor-deprecation table must list exactly the swept pairs');
        $tabled = [];
        foreach ($rows as $row) {
            $tabled[$row['class'] . '::' . $row['legacy']] = $row['bare'];
        }
        foreach (self::deprecatedAccessors() as [$class, $legacy, $bare]) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $this->assertArrayHasKey($short . '::' . $legacy, $tabled);
            $this->assertSame($bare, $tabled[$short . '::' . $legacy]);
        }
    }
}
