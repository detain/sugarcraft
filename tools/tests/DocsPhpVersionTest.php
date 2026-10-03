<?php

declare(strict_types=1);

/**
 * Pins every lib page's "PHP x.y+" footer to the PHP constraint that lib's
 * composer.json actually declares.
 *
 * WHY. `docs/_data/<slug>.json`'s `phpVersion` is hand-authored metadata that
 * `tools/gen-docs.php` copies verbatim into the footer of the public page, and
 * nothing tied it to the manifest. When the monorepo moved every lib to
 * `"php": "^8.3"`, 35 of the 60 pages kept saying "PHP 8.1+": a published
 * claim that the lib installs on a PHP version Composer refuses it on.
 *
 * The value is DERIVED from the constraint (lowest lower bound across the
 * `||` alternatives), never compared against a hardcoded "8.3+", so a lib that
 * moves to `^8.4` turns this red until its page follows.
 */

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocsPhpVersionTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The "x.y+" label for a composer PHP constraint, or null when the
     * constraint has a shape this derivation does not understand — which the
     * caller treats as a failure, not a skip.
     */
    private static function derive(string $constraint): ?string
    {
        $best = null;
        foreach (\preg_split('/\s*\|\|?\s*/', \trim($constraint)) ?: [] as $alternative) {
            if (!\preg_match('/^(?:\^|~|>=\s*|=)?v?(\d+)\.(\d+)(?:\.\d+|\.\*)?$/', \trim($alternative), $m)) {
                return null;
            }
            $version = [(int) $m[1], (int) $m[2]];
            if ($best === null || $version < $best) {
                $best = $version;
            }
        }

        return $best === null ? null : $best[0] . '.' . $best[1] . '+';
    }

    /** @return iterable<string, array{string, string}> */
    public static function derivations(): iterable
    {
        yield 'caret' => ['^8.3', '8.3+'];
        yield 'floor' => ['>=8.3', '8.3+'];
        yield 'floor with space' => ['>= 8.4', '8.4+'];
        yield 'tilde with patch' => ['~8.2.1', '8.2+'];
        yield 'alternatives take the lowest' => ['^8.4 || ^8.3', '8.3+'];
        yield 'single pipe alternatives' => ['^8.3|^9.0', '8.3+'];
    }

    #[DataProvider('derivations')]
    public function testTheDerivationReadsTheConstraintsTheTreeUses(string $constraint, string $expected): void
    {
        self::assertSame($expected, self::derive($constraint));
    }

    public function testAConstraintShapeItCannotReadIsRefusedNotGuessed(): void
    {
        self::assertNull(self::derive('8.3 - 8.4'));
        self::assertNull(self::derive('*'));
    }

    public function testEveryPagePhpVersionMatchesItsComposerConstraint(): void
    {
        $root = self::root();
        $pages = \glob($root . '/docs/_data/*.json') ?: [];
        self::assertNotSame([], $pages, 'no docs/_data/*.json found — the glob is wrong, not the docs');

        $mismatches = [];
        foreach ($pages as $page) {
            $slug = \basename($page, '.json');
            $meta = \json_decode((string) \file_get_contents($page), true, 512, \JSON_THROW_ON_ERROR);
            $manifest = $root . '/' . $slug . '/composer.json';
            if (!\is_file($manifest)) {
                $mismatches[] = "{$slug}: docs/_data/{$slug}.json has no {$slug}/composer.json to derive phpVersion from";
                continue;
            }
            $composer = \json_decode((string) \file_get_contents($manifest), true, 512, \JSON_THROW_ON_ERROR);
            $constraint = $composer['require']['php'] ?? null;
            if (!\is_string($constraint)) {
                $mismatches[] = "{$slug}: composer.json declares no require.php";
                continue;
            }
            $expected = self::derive($constraint);
            if ($expected === null) {
                $mismatches[] = "{$slug}: cannot derive a version label from \"{$constraint}\"";
                continue;
            }
            $actual = $meta['phpVersion'] ?? null;
            if ($actual !== $expected) {
                $mismatches[] = "{$slug}: phpVersion is " . \var_export($actual, true)
                    . " but composer.json requires \"{$constraint}\" (expected \"{$expected}\")";
            }
        }

        self::assertSame([], $mismatches, "docs/_data phpVersion drifted from composer.json — edit the JSON, then run php tools/gen-docs.php:\n" . \implode("\n", $mismatches));
    }
}
