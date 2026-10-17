<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Enforces the naming policy the 2026-10 rename established (plan_rename.md Phase 12 #2).
 *
 * The rule, as the plan words it: every first-level directory carrying a
 * `composer.json` starts with `candy-` (and is an app — its composer `type`
 * is `project`, or it declares a `bin`), or starts with `sugar-` (and is a
 * library — never `type: project`). `honey-` directories are the retired
 * math/physics family and must not exist at all; `candy-starter` satisfies
 * the app rule through its `bin/start`.
 *
 * WHY THIS IS A TEST AND NOT A PLAN LINE. The rename campaign moved every
 * prefix by hand across 61 packages; the policy only survives if a new
 * package that breaks it goes red in CI rather than getting reviewed into
 * existence. The prefix law is also stated over the rename map in
 * {@see RenamesJsonTest}; this guard covers the other half — directories on
 * disk, including ones no rename entry will ever mention.
 *
 * THE ONE EXCEPTION, named by the plan itself: `candy-code-web` is a lib by
 * kind but carries the app's name — "asset bundle shipped with candy-code".
 * It is exempted below by name and reason, and
 * {@see testEveryNamedExceptionIsStillEarned()} keeps the exemption honest:
 * a licensed directory that no longer violates the rule is a stale row, the
 * same bidirectional-roster discipline the `ACCEPTED_*` censuses apply.
 */
final class PrefixPolicyTest extends TestCase
{
    /** Prefix of the runnable-app family. */
    private const CANDY = 'candy-';

    /** Prefix of the library family. */
    private const SUGAR = 'sugar-';

    /** Prefix of the retired math/physics family — no directory may carry it. */
    private const RETIRED = 'honey-';

    /** Composer type of an application root; absent or other types read as a lib. */
    private const TYPE_PROJECT = 'project';

    /**
     * Directories exempted from the app rule, each with the reason the plan gives.
     *
     * @var array<string, string>
     */
    private const APP_RULE_EXEMPTIONS = [
        'candy-code-web' => 'asset bundle shipped with candy-code',
    ];

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Every first-level directory that carries a `composer.json`, keyed by name.
     *
     * The glob is the same walk {@see DocsQuickstartTest::psr4()} uses, and the
     * non-emptiness floor is the guard against a wrong glob: a prefix law that
     * iterates over nothing reports perfect compliance.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function packageManifests(): array
    {
        $manifests = [];
        $paths = \glob(self::root() . '/*/composer.json') ?: [];
        self::assertNotSame([], $paths, 'no */composer.json found — the glob is wrong, not the tree');

        foreach ($paths as $path) {
            $dir = \basename(\dirname($path));
            $raw = \file_get_contents($path);
            self::assertIsString($raw, "{$dir}/composer.json is unreadable, so this guard speaks for nothing");
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, "{$dir}/composer.json must decode to an object");
            $manifests[$dir] = $decoded;
        }

        self::assertGreaterThan(50, \count($manifests), 'the walk found too few package dirs — it is not looking at the monorepo root');

        return $manifests;
    }

    public function testTheRetiredFamilyHasNoLeftoverDirectories(): void
    {
        $leftovers = [];
        foreach (\glob(self::root() . '/*/') ?: [] as $dir) {
            $name = \basename($dir);
            if (\str_starts_with($name, self::RETIRED)) {
                $leftovers[] = $name;
            }
        }

        self::assertSame([], $leftovers, "honey-* directories outlived the 2026-10 rename: " . \implode(', ', $leftovers));
    }

    public function testEveryComposerDirectoryCarriesAFamilyPrefix(): void
    {
        $violations = [];
        foreach (self::packageManifests() as $dir => $json) {
            if (!\str_starts_with((string) $dir, self::CANDY) && !\str_starts_with((string) $dir, self::SUGAR)) {
                $violations[] = $dir;
            }
        }

        self::assertSame([], $violations, 'package directories must start with candy- or sugar-: ' . \implode(', ', $violations));
    }

    public function testBothFamiliesAreRepresentedSoNeitherRuleTestsNothing(): void
    {
        $candy = 0;
        $sugar = 0;
        foreach (array_keys(self::packageManifests()) as $dir) {
            if (\str_starts_with((string) $dir, self::CANDY)) {
                $candy++;
            } else {
                $sugar++;
            }
        }

        self::assertGreaterThan(0, $candy, 'no candy-* packages — the app rule below would pass vacuously');
        self::assertGreaterThan(0, $sugar, 'no sugar-* packages — the library rule below would pass vacuously');
    }

    public function testEveryCandyDirectoryDeclaresABinOrIsTypedProject(): void
    {
        $violations = [];
        foreach (self::packageManifests() as $dir => $json) {
            if (!\str_starts_with((string) $dir, self::CANDY) || isset(self::APP_RULE_EXEMPTIONS[$dir])) {
                continue;
            }
            $hasBin = !empty($json['bin']);
            $isProject = ($json['type'] ?? null) === self::TYPE_PROJECT;
            if (!$hasBin && !$isProject) {
                $violations[] = $dir;
            }
        }

        self::assertSame([], $violations, 'candy-* must declare "bin" or be type: project (apps ship something to run): ' . \implode(', ', $violations));
    }

    public function testNoSugarDirectoryIsTypedProject(): void
    {
        $violations = [];
        foreach (self::packageManifests() as $dir => $json) {
            if (!\str_starts_with((string) $dir, self::SUGAR)) {
                continue;
            }
            if (($json['type'] ?? null) === self::TYPE_PROJECT) {
                $violations[] = $dir;
            }
        }

        self::assertSame([], $violations, 'sugar-* are libraries and must not be type: project: ' . \implode(', ', $violations));
    }

    public function testEveryNamedExceptionIsStillEarned(): void
    {
        $manifests = self::packageManifests();
        foreach (self::APP_RULE_EXEMPTIONS as $dir => $reason) {
            self::assertArrayHasKey($dir, $manifests, "{$dir} is exempted as \"" . $reason . '" but no longer exists — drop the exemption row');

            $json = $manifests[$dir];
            $hasBin = !empty($json['bin']);
            $isProject = ($json['type'] ?? null) === self::TYPE_PROJECT;
            self::assertTrue(
                !$hasBin && !$isProject,
                "{$dir} no longer violates the app rule (bin: " . \var_export($json['bin'] ?? null, true)
                    . ', type: ' . \var_export($json['type'] ?? null, true) . ') — the exemption for "' . $reason . '" is stale'
            );
        }
    }
}
