<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README snippet-resolution guard (D1).
 *
 * Every fenced ```php block in README.md is censused: each `use` import must
 * resolve to a live symbol, and each `Class::member` / `$var->method()` cite
 * must resolve on a class the README itself imports. The TEA quick-start once
 * taught `use SugarCraft\Core\Msg\KeyType;` — a FQN that never existed (the
 * enum lives at `SugarCraft\Core\KeyType`, only KeyMsg is in Msg) — and no
 * guard reddened it. This file is that guard: the fix commit's claim that a
 * snippet-resolves pin shipped was checked against disk and found false, so
 * the pin is minted here for real.
 *
 * Deliberately resolution-based, not exec-based (the sibling sugar-flexbox
 * genre): the picker snippets chain `$picker` across blocks, so they lint but
 * cannot each run standalone; symbol resolution is what pins the doc's truth.
 * The extraction-floor test fails closed if a refactor ever shrinks the
 * census below its known-positive counts, so a broken extractor can never
 * quietly vacuate the arms above it.
 */
final class ReadmeSnippetResolvesTest extends TestCase
{
    /** Floors for the extraction arms (known-positive counts, lower bounds). */
    private const MIN_PHP_BLOCKS = 3;
    private const MIN_USE_IMPORTS = 4;
    private const MIN_INSTANCE_METHODS = 20;
    private const MIN_STATIC_MEMBERS = 4;

    /**
     * @return array<string,array{0:string}> import FQN => [fqn]
     */
    public static function documentedImports(): array
    {
        $cases = [];
        foreach (self::useImports() as $fqn) {
            $cases[$fqn] = [$fqn];
        }

        return $cases;
    }

    /**
     * @return array<string,array{0:string}> method name => [method]
     */
    public static function documentedInstanceMethods(): array
    {
        $cases = [];
        foreach (self::instanceMethods() as $method) {
            $cases[$method] = [$method];
        }

        return $cases;
    }

    /**
     * @return array<string,array{0:string,1:string}> [short cite, member] => [fqn, member]
     */
    public static function documentedStaticMembers(): array
    {
        $cases = [];
        $byShort = self::classMapByShortName();
        foreach (self::staticReferences() as [$short, $member]) {
            $fqn = $byShort[$short] ?? '?';
            $cases[$short . '::' . $member] = [$fqn, $member];
        }

        return $cases;
    }

    /**
     * @dataProvider documentedImports
     */
    public function testEveryDocumentedImportResolvesToALiveSymbol(string $fqn): void
    {
        $this->assertTrue(
            class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn),
            "README.md imports {$fqn}, but no such symbol exists — the snippet is a lie (D1)."
        );
    }

    /**
     * @dataProvider documentedInstanceMethods
     */
    public function testEveryDocumentedMethodCallResolvesOnAClassTheReadmeCites(string $method): void
    {
        $owners = [];
        foreach (self::useImports() as $fqn) {
            if (method_exists($fqn, $method)) {
                $owners[] = $fqn;
            }
        }

        $this->assertNotSame([], $owners, "README.md calls ->{$method}(), but none of the "
            . 'imported classes declares it — the snippet would fatal at the call (D1).');
    }

    /**
     * @dataProvider documentedStaticMembers
     */
    public function testEveryDocumentedStaticMemberResolves(string $fqn, string $member): void
    {
        $this->assertTrue(
            method_exists($fqn, $member) || \defined($fqn . '::' . $member),
            "README.md cites {$fqn}::{$member}, but neither a method nor a constant of that name exists (D1)."
        );
    }

    /**
     * The named D1 keystone: the TEA snippet must import KeyType from its real
     * home (Core), never from Core\Msg — the bogus namespace the README taught
     * before the fix wave. Reverting the import line reddens this arm directly
     * in addition to the generic census above.
     */
    public function testTheTeaSnippetImportsKeyTypeFromItsRealHomeNotFromMsg(): void
    {
        $readme = self::readme();

        $this->assertStringContainsString(
            "use SugarCraft\\Core\\KeyType;\n",
            $readme,
            'the TEA quick-start must import SugarCraft\Core\KeyType (the live FQN, D1).'
        );
        $this->assertStringNotContainsString(
            'use SugarCraft\\Core\\Msg\\KeyType;',
            $readme,
            'SugarCraft\Core\Msg\KeyType never existed — the README must not cite it (D1).'
        );
    }

    /**
     * Fail-closed extractor floors: if block/import/method/member extraction ever
     * collapses (regex drift, README surgery), the per-item arms above would run
     * vacuously. These counts are the guard's own known-positive witness.
     */
    public function testTheExtractionArmsCarryKnownPositiveCounts(): void
    {
        $blocks = self::phpBlocks();
        $this->assertGreaterThanOrEqual(
            self::MIN_PHP_BLOCKS,
            count($blocks),
            'expected at least ' . self::MIN_PHP_BLOCKS . ' fenced php blocks in README.md — the extractor broke.'
        );

        $imports = self::useImports();
        $this->assertGreaterThanOrEqual(
            self::MIN_USE_IMPORTS,
            count($imports),
            'expected at least ' . self::MIN_USE_IMPORTS . ' use-imports across the php blocks — the extractor broke.'
        );

        $methods = self::instanceMethods();
        $this->assertGreaterThanOrEqual(
            self::MIN_INSTANCE_METHODS,
            count($methods),
            'expected at least ' . self::MIN_INSTANCE_METHODS . ' distinct ->method() cites — the extractor broke.'
        );

        $statics = self::staticReferences();
        $this->assertGreaterThanOrEqual(
            self::MIN_STATIC_MEMBERS,
            count($statics),
            'expected at least ' . self::MIN_STATIC_MEMBERS . ' Class::member cites — the extractor broke.'
        );
    }

    /**
     * Every class the README's php blocks import, in document order, deduplicated.
     *
     * @return list<string> fully-qualified names
     */
    private static function useImports(): array
    {
        $imports = [];
        foreach (self::phpBlocks() as $block) {
            if (preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+);/m', $block, $hits) === false) {
                continue;
            }
            foreach ($hits[1] as $fqn) {
                if (!in_array($fqn, $imports, true)) {
                    $imports[] = $fqn;
                }
            }
        }

        return $imports;
    }

    /**
     * @return array<string,string> short cite => FQN
     */
    private static function classMapByShortName(): array
    {
        $map = [];
        foreach (self::useImports() as $fqn) {
            $short = substr($fqn, (int) strrpos($fqn, '\\') + 1);
            $map[$short] = $fqn;
        }

        return $map;
    }

    /**
     * @return list<array{0:string,1:string}> [short class cite, member] pairs, deduplicated
     */
    private static function staticReferences(): array
    {
        $seen = [];
        $refs = [];
        foreach (self::phpBlocks() as $block) {
            if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)/', $block, $hits, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($hits as $hit) {
                $key = $hit[1] . '::' . $hit[2];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $refs[] = [$hit[1], $hit[2]];
                }
            }
        }

        return $refs;
    }

    /**
     * @return list<string> distinct ->method() names across all php blocks
     */
    private static function instanceMethods(): array
    {
        $seen = [];
        foreach (self::phpBlocks() as $block) {
            if (preg_match_all('/->\s*([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $block, $hits) === false) {
                continue;
            }
            foreach ($hits[1] as $method) {
                $seen[$method] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return list<string> fenced ```php block bodies, in document order
     */
    private static function phpBlocks(): array
    {
        $blocks = [];
        if (preg_match_all('/^```php[ \t]*\n(.*?)\n```/ms', self::readme(), $hits) === false) {
            return $blocks;
        }

        return $hits[1];
    }

    private static function readme(): string
    {
        $path = \dirname(__DIR__) . '/README.md';
        if (!is_readable($path)) {
            self::fail('README.md is not readable at ' . $path);
        }

        return (string) file_get_contents($path);
    }
}
