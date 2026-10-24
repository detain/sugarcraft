<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;
use SugarCraft\Readline\History\HistoryInterface;
use SugarCraft\Readline\History\InMemoryHistory;

/**
 * Pins the accessor-deprecation era (2026-10-24, ruling 3b).
 *
 * The history navigation pair was renamed to its bare twins and the
 * whole family moved with the interface: HistoryInterface now declares
 * only previous()/next() (PHP forbids interface method bodies, so the
 * legacy spellings could not ride the contract as defaults), while
 * InMemoryHistory — and FileHistory by inheritance — keep getPrevious()
 * /getNext() as byte-identical one-line BC forwarders marked
 * '@deprecated' + 'REMOVED-IN: 2.0.0' until v2.0.0.
 *
 * Navigation mutates the position cursor, so the BC legs pin the whole
 * walk (returns AND advance state), not a single call.
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
            'InMemoryHistory::getPrevious' => [InMemoryHistory::class, 'getPrevious', 'previous'],
            'InMemoryHistory::getNext' => [InMemoryHistory::class, 'getNext', 'next'],
        ];
    }

    private static function seeded(): InMemoryHistory
    {
        $history = new InMemoryHistory();
        $history->push('alpha');
        $history->push('bravo');
        $history->push('charlie');
        return $history;
    }

    /**
     * BC leg (live object): through a reset() sandwich the forwarder and
     * the bare twin must return the identical value from the identical
     * navigation state.
     *
     * @param class-string $class
     */
    #[DataProvider('deprecatedAccessors')]
    public function testTheForwarderMatchesTheBareTwinOnALiveHistory(string $class, string $legacy, string $bare): void
    {
        $object = self::seeded();
        $viaLegacy = $object->{$legacy}();
        $object->reset();
        $viaBare = $object->{$bare}();
        $this->assertSame($viaBare, $viaLegacy, $class . '::' . $legacy . ' must return what ' . $bare . ' returns');
    }

    /**
     * BC leg (state advance): the full navigation walk — deeper, past
     * exhaustion, back toward the live buffer — must be identical through
     * the legacy spellings, proving the forwarders carry the position
     * side effect exactly like the bare twins.
     */
    public function testTheFullNavigationWalkIsIdenticalThroughBothSpellings(): void
    {
        $legacy = self::seeded();
        $bare = self::seeded();
        $legacyWalk = [
            $legacy->getPrevious(), $legacy->getPrevious(), $legacy->getPrevious(), $legacy->getPrevious(),
            $legacy->getNext(), $legacy->getNext(), $legacy->getNext(),
        ];
        $bareWalk = [
            $bare->previous(), $bare->previous(), $bare->previous(), $bare->previous(),
            $bare->next(), $bare->next(), $bare->next(),
        ];
        $this->assertSame(['charlie', 'bravo', 'alpha', null, 'bravo', 'charlie', null], $bareWalk);
        $this->assertSame($bareWalk, $legacyWalk);
    }

    /**
     * BC leg (inheritance): FileHistory is final but extends
     * InMemoryHistory, so it must carry the same forwarders.
     */
    public function testFileHistoryInheritsTheForwarders(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'rl-dep-') . '.hist';
        try {
            // Closure scope: FileHistory's destructor flushes on teardown, so it
            // must fire (rewriting the file) BEFORE the cleanup below unlinks.
            (function () use ($path): void {
                $history = new FileHistory($path);
                $history->push('alpha');
                $history->push('bravo');
                $viaLegacy = $history->getPrevious();
                $history->reset();
                $viaBare = $history->previous();
                $this->assertSame('bravo', $viaLegacy);
                $this->assertSame($viaBare, $viaLegacy);
                $history->reset();
                $viaLegacyNext = $history->getNext();
                $history->reset();
                $this->assertSame($history->next(), $viaLegacyNext);
            })();
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
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
        $lines = array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $this->assertCount(1, $lines, $class . '::' . $legacy . ' must be a single-line forwarder');
        $this->assertMatchesRegularExpression(
            '/public function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return \$this->' . preg_quote($bare, '/') . '\(\); \}\s*$/',
            $lines[0]
        );
        $bareMethod = new \ReflectionMethod($class, $bare);
        $this->assertTrue($bareMethod->isPublic());
        $this->assertStringNotContainsString(self::MARKER, (string) $bareMethod->getDocComment());
        $this->assertSame($method->getNumberOfParameters(), $bareMethod->getNumberOfParameters());
        $this->assertSame((string) $method->getReturnType(), (string) $bareMethod->getReturnType());
    }

    /**
     * Contract leg: the interface declares the bare pair as its abstract
     * navigation members and no longer carries the legacy spellings.
     */
    public function testTheInterfaceCarriesOnlyTheBareNavigationPair(): void
    {
        $previous = new \ReflectionMethod(HistoryInterface::class, 'previous');
        $next = new \ReflectionMethod(HistoryInterface::class, 'next');
        $this->assertTrue($previous->isAbstract());
        $this->assertTrue($next->isAbstract());
        $this->assertFalse(method_exists(HistoryInterface::class, 'getPrevious'));
        $this->assertFalse(method_exists(HistoryInterface::class, 'getNext'));
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
