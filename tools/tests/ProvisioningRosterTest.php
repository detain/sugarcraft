<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Org-provisioning roster guard (RT-06, repo-tooling-006).
 *
 * WHY: three hand-maintained slug lists (bootstrap-org-repos.sh
 * DESCRIPTIONS, set-org-default-master.sh LIBS, the sync workflow's
 * discovered set) drifted apart — one still prescribed the dead
 * super-candy, another missed every lib added since, and a new lib's
 * first sync burned the push-retry loop on a 404 because nothing
 * created its split repo. The cure derives every roster from the CI
 * discovery rule and treats each lib's composer.json "description" as
 * the metadata SSOT (the map demoted to an optional override). This
 * guard is the offline tripwire for that law, in the same text-scan
 * house style as ReleasePublishPostureTest (bare phpunit phar, no
 * network, no YAML library).
 */
final class ProvisioningRosterTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function bootstrap(): string
    {
        return self::readText('scripts/bootstrap-org-repos.sh');
    }

    /**
     * The authoritative slug set, straight from the discovery rule both
     * scripts and the sync workflow now consume.
     *
     * @return list<string>
     */
    private static function discoveredLibs(): array
    {
        $out = shell_exec('php ' . escapeshellarg(self::ROOT . '/scripts/affected-libs.php') . ' --all 2>/dev/null');
        self::assertIsString($out, 'affected-libs.php --all must run for the guard');
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        $libs = $decoded['all_libs'] ?? null;
        self::assertIsArray($libs, 'all_libs list missing from discovery JSON');
        self::assertGreaterThan(30, count($libs), 'discovery produced a suspiciously small roster');

        return array_values(array_map('strval', $libs));
    }

    /**
     * @return array<string, string> slug => description override
     */
    private static function overrideMap(): array
    {
        preg_match_all('/^ {4}\[([\w.-]+)\]="(.*?)"/m', self::bootstrap(), $m, PREG_SET_ORDER);
        $map = [];
        foreach ($m as $row) {
            $map[$row[1]] = $row[2];
        }
        self::assertGreaterThan(10, count($map), 'the override map parsed empty — the shape regex and the script drifted');

        return $map;
    }

    private static function readText(string $rel): string
    {
        $text = (string) file_get_contents(self::ROOT . '/' . $rel);
        self::assertNotSame('', $text, $rel . ' must be readable');

        return $text;
    }

    public function testNoOverrideKeyNamesAMissingDirectory(): void
    {
        foreach (array_keys(self::overrideMap()) as $slug) {
            self::assertDirectoryExists(
                self::ROOT . '/' . $slug,
                "DESCRIPTIONS key '$slug' names no directory — prune it (roster-drift law, RT-06)"
            );
        }
        self::assertSame(0, substr_count(self::bootstrap(), 'super-candy'), 'the dead super-candy never returns to the map');
    }

    public function testEveryDiscoveredSlugResolvesADescription(): void
    {
        $map = self::overrideMap();
        $unresolvable = [];
        foreach (self::discoveredLibs() as $slug) {
            if (isset($map[$slug])) {
                continue; // optional override present — the script uses it verbatim
            }
            $composer = json_decode((string) file_get_contents(self::ROOT . '/' . $slug . '/composer.json'), true);
            if (!is_array($composer) || trim((string) ($composer['description'] ?? '')) === '') {
                $unresolvable[] = $slug;
            }
        }
        self::assertSame(
            [],
            $unresolvable,
            'libs with neither a DESCRIPTIONS override nor a composer.json "description" would abort bootstrap/sync auto-create'
        );
    }

    public function testTopicsArrayIsConsumedAndUnique(): void
    {
        $text = self::bootstrap();
        self::assertStringContainsString(
            'TOPICS=(php tui terminal sugarcraft composer)',
            $text,
            'the topic set is the pinned canonical list (the duplicated `sugarcraft` is gone)'
        );
        self::assertStringContainsString('for t in "${TOPICS[@]}"; do', $text, 'the create loop must CONSUME the array');
        self::assertStringContainsString('topic_args+=(-F "names[]=$t")', $text, 'consumption builds the gh -F args');
        foreach (['php', 'tui', 'terminal', 'sugarcraft', 'composer'] as $topic) {
            self::assertSame(0, substr_count($text, 'names[]=' . $topic . '"'), "no hardcoded '$topic' names[] line may survive next to the consumer");
        }
    }

    public function testSetOrgDefaultMasterDerivesItsRoster(): void
    {
        $text = self::readText('scripts/set-org-default-master.sh');
        self::assertStringContainsString('affected-libs.php', $text, 'the flip script derives from the CI discovery rule');
        self::assertStringContainsString('--all', $text, 'derivation uses the --all roster');
        self::assertSame(0, substr_count($text, 'LIBS=('), 'no hand-maintained literal LIBS list may return');
        self::assertSame(0, substr_count($text, 'super-candy'), 'the dead super-candy never returns to the flip list');
    }

    public function testSyncAutoCreatesMissingReposAndRefusesLoudlyOtherwise(): void
    {
        $text = self::readText('.github/workflows/sync-sugarcraft.yml');
        self::assertStringContainsString('ensure_repo "$lib"', $text, 'the split loop runs the first-sync preflight');
        self::assertStringContainsString(
            'GH_TOKEN: ${{ secrets.SUGARCRAFT_SYNC_TOKEN }}',
            $text,
            'gh in the push job authenticates with the sync secret it already trusts'
        );
        self::assertMatchesRegularExpression(
            '/cannot create sugarcraft\/\$lib.*exit 1/s',
            $text,
            'a failed auto-create must refuse LOUD, never fall through to the retry loop'
        );
        $ensureCall = strpos($text, 'ensure_repo "$lib"');
        $forcePush = strpos($text, 'git push --force "$push_url"');
        self::assertIsInt($ensureCall);
        self::assertIsInt($forcePush);
        self::assertLessThan($forcePush, $ensureCall, 'the preflight gates the force-push (create-before-push order)');
    }
}
