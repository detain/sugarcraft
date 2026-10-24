<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Database;
use SugarCraft\Kv\Store;

/**
 * Pins the accessor-deprecation era (2026-10-24, ruling 3b, W-G1).
 *
 * Database::getRaw() was renamed to its bare twin raw(), and the
 * file-writing verb Store::getFile() to saveTo() — era-correct because
 * the operation writes store data OUT to disk; the store-side mutator
 * setFile() keeps its name (file form of the canonical set() verb).
 * The legacy spellings stay byte-identical one-line BC forwarders
 * marked '@deprecated' + 'REMOVED-IN: 2.0.0' until v2.0.0.
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
            'Database::getRaw' => [Database::class, 'getRaw', 'raw'],
            'Store::getFile' => [Store::class, 'getFile', 'saveTo'],
        ];
    }

    /**
     * @return array{0: Database, 1: string}
     */
    private static function seededDatabase(): array
    {
        $base = \tempnam(\sys_get_temp_dir(), 'kv-dep-');
        \unlink($base);
        $db = new Database($base . '.db', 'test');
        $db->set('alpha', 'first');
        $db->set('payload', 'bytes', true);
        return [$db, $base];
    }

    private static function seededStore(): array
    {
        $dir = \sys_get_temp_dir() . '/kv-dep-store-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $store = new Store($dir);
        $store->set('avatar', 'raw-bytes', true);
        return [$store, $dir];
    }

    /**
     * @param list<string> $files
     */
    private static function removeFiles(array $files): void
    {
        foreach ($files as $file) {
            @\unlink($file);
        }
    }

    private static function closeDatabase(Database $db, string $base): void
    {
        $db->close();
        self::removeFiles([$base . '.db', $base . '.db-wal', $base . '.db-shm']);
    }

    /**
     * BC leg (entry carrier): the forwarder must answer exactly what the
     * bare twin answers — same present key, same missing key — both
     * bypassing the expiry filter the same way.
     */
    public function testDatabaseRawForwarderMatchesTheBareTwin(): void
    {
        [$db, $base] = self::seededDatabase();
        try {
            $this->assertEquals($db->raw('alpha'), $db->getRaw('alpha'));
            $this->assertEquals($db->raw('payload'), $db->getRaw('payload'));
            $this->assertNull($db->raw('missing'));
            $this->assertSame($db->raw('missing'), $db->getRaw('missing'));
        } finally {
            self::closeDatabase($db, $base);
        }
    }

    /**
     * BC leg (file verb): writing an existing key and a missing key must
     * return the identical bool through both spellings, and the bytes
     * landing on disk must be the same.
     */
    public function testStoreSaveToForwarderMatchesTheBareTwin(): void
    {
        [$store, $dir] = self::seededStore();
        $viaBare = \tempnam(\sys_get_temp_dir(), 'kv-dep-out-');
        $viaLegacy = \tempnam(\sys_get_temp_dir(), 'kv-dep-out-');
        $missing = [$viaBare . '.miss', $viaLegacy . '.miss'];
        try {
            $this->assertTrue($store->saveTo('avatar', $viaBare));
            $this->assertTrue($store->getFile('avatar', $viaLegacy));
            $this->assertSame(\file_get_contents($viaBare), \file_get_contents($viaLegacy));
            $this->assertFalse($store->saveTo('nope', $missing[0]));
            $this->assertFalse($store->getFile('nope', $missing[1]));
        } finally {
            self::removeFiles([$viaBare, $viaLegacy, ...$missing]);
            foreach ((\scandir($dir) ?: []) as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_file($dir . '/' . $entry)) {
                    @\unlink($dir . '/' . $entry);
                }
            }
            @\rmdir($dir);
        }
    }

    /**
     * Structural leg: every swept forwarder is a one-line return
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
     * forwarders under test — no marked get* accessor drifts in.
     */
    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $found = [];
        foreach (self::srcGetAccessors() as [$key, $marked]) {
            if ($marked) {
                $found[] = $key;
            }
        }
        $expected = [];
        foreach (self::deprecatedAccessors() as [$class, $legacy]) {
            $expected[] = \substr($class, (int) \strrpos($class, '\\') + 1) . '::' . $legacy;
        }
        \sort($found);
        \sort($expected);
        $this->assertSame($expected, $found, 'src/ census drifted from the tested pairs');
        $this->assertCount(2, $found);
    }

    /**
     * Inverse leg: every public get*() in src/ must be a marked
     * forwarder — a planted unmarked accessor, or the marker stripped
     * from a swept forwarder, goes red here.
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
     * the swept pairs (nothing added, nothing dropped).
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
        $this->assertCount(2, $rows, 'README Accessor-deprecation table must list exactly the swept pairs');
        $tabled = [];
        foreach ($rows as $row) {
            $tabled[$row['class'] . '::' . $row['legacy']] = $row['bare'];
        }
        foreach (self::deprecatedAccessors() as [$class, $legacy, $bare]) {
            $short = \substr($class, (int) \strrpos($class, '\\') + 1);
            $this->assertArrayHasKey($short . '::' . $legacy, $tabled);
            $this->assertSame($bare, $tabled[$short . '::' . $legacy]);
        }
    }
}
