<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * House law: `::new()` is the default factory root; `::create()` / `::make()` /
 * `::default()` are banned names on candy-code/src public static factories.
 *
 * The census is roster-driven in both directions: an allowlisted seat that no
 * longer exists is a stale row, and a banned seat that is not allowlisted is a
 * new violation. STILL_BANNED lists the seats whose own steps (S-L08 Theme,
 * S-P05 providers, ...) have not landed; delete a row when its rename ships.
 */
final class FactoryNamingDriftTest extends TestCase
{
    /** Seats still carrying a banned name, pending their own rename steps. */
    private const STILL_BANNED = [
        'Theme.php::default',
        'MCP/McpAuthStore.php::create',
        'LSP/LspExchangeLock.php::create',
    ];

    /** @return list<string> */
    private static function census(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/public\s+static\s+function\s+(create|make|default)\s*\(/', $src, $m)) {
                $rel = substr($file->getPathname(), \strlen($root) + 1);
                foreach ($m[1] as $name) {
                    $found[] = $rel . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    public function testNoNewBannedFactoryNames(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::census(), self::STILL_BANNED)),
            'public static create()/make()/default() factory added; use ::new() (or a descriptive name like forMembers())',
        );
    }

    public function testAllowlistHasNoStaleRows(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::STILL_BANNED, self::census())),
            'STILL_BANNED row no longer exists in src/; delete it',
        );
    }

    public function testRenamedSeatsUseHouseNames(): void
    {
        self::assertTrue(method_exists(\SugarCraft\Code\Agents\Board\Board::class, 'forMembers'));
        self::assertTrue(method_exists(\SugarCraft\Code\Support\SiblingSpendLedger::class, 'new'));
        self::assertTrue(method_exists(\SugarCraft\Code\Background\ScheduleFactory::class, 'new'));
        self::assertTrue(method_exists(\SugarCraft\Code\Tools\Web\Search\SearchBackends::class, 'new'));
        self::assertTrue(method_exists(\SugarCraft\Code\Session\SessionSearchIndex::class, 'new'));
    }
}
