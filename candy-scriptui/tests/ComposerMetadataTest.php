<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;

/**
 * scriptui-81 — the manifest must describe the tool that actually ships.
 *
 * (115) the description once counted 13 subcommands while the app was
 * invocably wider; this test re-derives the roster from the live
 * Application and pins the description's spelling to it.
 * (116) mb_* is called DIRECTLY (not just via sugar-core), so ext-mbstring
 * must be a declared requirement or a sibling swap turns these calls into
 * runtime fatals with zero install-time signal.
 */
final class ComposerMetadataTest extends TestCase
{
    /**
     * scriptui-53 KEEP (manifest-pin): shared reader for the two headed
     * manifest tests below — composer.json is the shipped contract itself.
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($json);

        return $json;
    }

    public function testTheDescriptionCountsTheLiveRosterHonestly(): void
    {
        // scriptui-53 KEEP (manifest-pin): composer.json IS the shipped
        // artifact — the description counting the live roster is an install-
        // time truth no run command can introspect. The roster is re-derived
        // from the real Application above the manifest read.
        $app = new Application();
        $names = array_values(array_filter(
            array_keys($app->all()),
            static fn (string $n): bool => !str_starts_with($n, '_') && !in_array($n, ['help', 'list'], true),
        ));
        sort($names);

        $interactive = array_values(array_diff($names, ['completion', 'version']));
        self::assertCount(13, $interactive, 'the interactive family is 13 commands — update the description AND this pin together');

        $description = (string) (self::manifest()['description'] ?? '');
        self::assertStringContainsString('13 interactive subcommands plus completion and version', $description);
        foreach ($interactive as $name) {
            self::assertStringContainsString($name, $description);
        }
    }

    public function testMbstringIsDeclaredBecauseSrcCallsItDirectly(): void
    {
        // scriptui-53 KEEP (manifest-pin): the src scan exists to keep the
        // composer.json declaration paired with direct mb_* use — a
        // requirement an install-time contract, unobservable at runtime on a
        // host that always has the extension loaded.
        $require = self::manifest()['require'] ?? [];
        self::assertArrayHasKey('ext-mbstring', $require, 'direct mb_* use must be declared, not transitive');

        $direct = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($rii as $file) {
            if ($file->isFile() && preg_match('/\bmb_[a-z_]+\(/', (string) file_get_contents($file->getPathname()))) {
                $direct[] = $file->getFilename();
            }
        }
        self::assertNotSame([], $direct, 'if mb_* calls ever leave src, drop ext-mbstring in the same commit');
    }
}
