<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Release-posture guard (reviews-1103 G6-P, owner rulings OD-5/6/7/8 + the
 * OD-14 pinning half).
 *
 * WHY: every publisher in this repo used to fire from untested bytes - the
 * stable phar trio built HEAD of a tag with no CI verdict, sync split-pushed
 * every master push before CI had spoken, phar-dev published rolling dev
 * prereleases the same way, and the Packagist/GHCR route consumed a bundle
 * that CI never built. The ruled posture is: nothing publishes unless CI is
 * green at the exact commit, tags must be ancestors of master, releases are
 * built at the tagged SHA with an explicit target, and sugarcraft/* siblings
 * pin to those tested bytes instead of floating dev-master. This guard is
 * the fail-closed tripwire that the workflow files keep saying so - text
 * scans by design (the tools-guards job runs a bare phpunit phar with no
 * composer autoload and no YAML library).
 */
final class ReleasePublishPostureTest extends TestCase
{
    private const TRIO = [
        'release-phar.yml',
        'release-app-phar.yml',
        'release-candy-top-phar.yml',
    ];

    private function read(string $rel): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function workflow(string $name): string
    {
        return $this->read('.github/workflows/' . $name);
    }

    /**
     * Extract one job's block: from its two-space `  <job>:` key to the next
     * two-space key. Fail-closed: the job header must exist exactly once.
     */
    private function jobSection(string $text, string $job): string
    {
        $header = "\n  " . $job . ":\n";
        self::assertSame(1, substr_count($text, $header), "job header for '$job'");
        $start = strpos($text, $header) + strlen($header);
        $rest = substr($text, $start);
        if (!preg_match('/^  [A-Za-z0-9_.-]+:/m', $rest, $m, PREG_OFFSET_CAPTURE)) {
            // The final job in a workflow has no following top-level key.
            return $rest;
        }

        return substr($rest, 0, (int) $m[0][1]);
    }

    private function assertOnce(string $needle, string $haystack, string $where): void
    {
        self::assertSame(1, substr_count($haystack, $needle), "$where: '$needle'");
    }

    public function testTheReleaseTrioGatesOnTagAncestryAndCiGreen(): void
    {
        foreach (self::TRIO as $file) {
            $gate = $this->jobSection($this->workflow($file), 'gate');
            self::assertStringContainsString(
                'git merge-base --is-ancestor "$tag_sha" origin/master',
                $gate,
                "$file gate carries the OD-5 ancestry door"
            );
            self::assertSame(
                1,
                substr_count($gate, 'gh run list --workflow CI --branch master --commit'),
                "$file gate polls CI at the tagged SHA exactly once (OD-7)"
            );
        }
    }

    public function testTheReleaseJobsSerializePerAppAndNeedTheGate(): void
    {
        $appOutputs = [
            'release-phar.yml' => 'app: candy-code',
            'release-candy-top-phar.yml' => 'app: candy-top',
            'release-app-phar.yml' => 'app: ${{ steps.scope.outputs.app }}',
        ];
        foreach ($appOutputs as $file => $binding) {
            $text = $this->workflow($file);
            $slug = basename($file, '.yml');
            $gate = $this->jobSection($text, 'gate');
            self::assertStringContainsString($binding, $gate, "$file gate publishes an app identity");
            self::assertStringContainsString(
                'group: ' . $slug . '-${{ github.ref_name }}',
                $text,
                "$file workflow-level same-ref belt lock"
            );
            $release = $this->jobSection($text, 'release');
            self::assertStringContainsString('    needs: gate', $release, "$file release needs gate");
            self::assertStringContainsString(
                'group: ' . $slug . '-${{ needs.gate.outputs.app }}',
                $release,
                "$file per-app OD-5 lock on the release job"
            );
            self::assertStringNotContainsString(
                '--target master',
                $release,
                "$file release never targets a branch name"
            );
        }
    }

    public function testEveryStableReleaseBuildsAtTheTaggedCommit(): void
    {
        foreach (self::TRIO as $file) {
            $release = $this->jobSection($this->workflow($file), 'release');
            self::assertStringContainsString(
                'ref: ${{ needs.gate.outputs.tag_sha }}',
                $release,
                "$file OD-8 build-at-SHA checkout"
            );
            self::assertStringContainsString('--target "$TAG_SHA"', $release, "$file explicit OD-8 target");
        }
    }

    public function testTheReleasePipelinePinsSiblingsBeforeInstallAndChecksAfter(): void
    {
        foreach (self::TRIO as $file) {
            $text = $this->workflow($file);
            $inject = strpos($text, 'php scripts/pin-siblings.php inject');
            $install = strpos($text, 'composer install --no-dev');
            $verify = strpos($text, 'php scripts/pin-siblings.php verify');
            self::assertIsInt($inject, "$file carries the OD-14 inject step");
            self::assertIsInt($install, "$file carries the install step");
            self::assertIsInt($verify, "$file carries the OD-14 verify step");
            self::assertLessThan($install, $inject, "$file injects pins before installing");
            self::assertLessThan($verify, $install, "$file verifies after installing");
        }
    }

    public function testTheStableReleasesShipProvenanceSidecars(): void
    {
        $assets = [
            'release-phar.yml' => 'dist/candy-code.phar.commit dist/candy-code.phar.pins.json',
            'release-app-phar.yml' => '"dist/$app.phar.commit" "dist/$app.phar.pins.json"',
            'release-candy-top-phar.yml' => 'dist/candy-top.phar.commit dist/candy-top.phar.pins.json',
        ];
        foreach ($assets as $file => $row) {
            $text = $this->workflow($file);
            self::assertMatchesRegularExpression(
                '/printf .*"\$TAG_SHA" > (?:dist\/[\w-]+|"dist\/\$app)\.phar\.commit/',
                $text,
                "$file stamps the build SHA into a .phar.commit sidecar"
            );
            self::assertStringContainsString($row, $text, "$file uploads the sidecars as release assets");
        }
    }

    public function testTheDevChannelWaitsForCiAndPinsSiblings(): void
    {
        $text = $this->workflow('phar-dev.yml');
        $gate = $this->jobSection($text, 'ci-gate');
        self::assertStringContainsString(
            'gh run list --workflow CI --branch master --commit "$TARGET_SHA"',
            $gate,
            'phar-dev polls CI at the pushed SHA (OD-7)'
        );
        $build = $this->jobSection($text, 'build');
        self::assertStringContainsString('needs: [detect, ci-gate]', $build, 'phar-dev build needs the gate');
        // MAJOR-1 companion pin: the guard's blind spot is semantic
        // composition — `needs: [detect, ci-gate]` is textually present even
        // when the gate is decorative, because a custom job-level `if`
        // REPLACES GitHub's implicit all-needs-succeeded check. The condition
        // line itself must name the gate's result for the wait to be real.
        self::assertStringContainsString(
            "if: needs.detect.outputs.matrix_count != '0' && needs.ci-gate.result == 'success'",
            $build,
            'phar-dev build folds needs.ci-gate.result into its custom if (the gate is enforced, not ornamental)'
        );
        $inject = strpos($text, 'php scripts/pin-siblings.php inject "$APP" --root . --sha "$SHA"');
        $install = strpos($text, 'composer install --no-dev');
        $verify = strpos($text, 'php scripts/pin-siblings.php verify "$APP"');
        self::assertIsInt($inject, 'dev builds pin siblings too');
        self::assertLessThan($install, $inject, 'dev injects before install');
        self::assertLessThan($verify, $install, 'dev verifies after install');
        self::assertStringContainsString(
            'assets=("dist/$app.phar" "dist/$app.phar.sha256" "dist/$app.phar.commit" "dist/$app.phar.pins.json")',
            $text,
            'the rolling channel publishes provenance sidecars (OD-8)'
        );
    }

    public function testTheSyncPublishersWaitForCi(): void
    {
        $text = $this->workflow('sync-sugarcraft.yml');
        $gate = $this->jobSection($text, 'ci-gate');
        self::assertStringContainsString(
            'gh run list --workflow CI --branch master --commit "$TARGET_SHA"',
            $gate,
            'sync gates on CI at the pushed commit (OD-7)'
        );
        self::assertSame(2, substr_count($text, 'needs: ci-gate'), 'both sync publishers wait (OD-6/7)');
    }

    public function testCiCarriesTheWebBundleLegThePublishersVouchFor(): void
    {
        $text = $this->workflow('ci.yml');
        self::assertSame(0, strpos($text, "name: CI\n"), 'pollers query the workflow by this name');
        $job = $this->jobSection($text, 'web-bundle');
        self::assertStringContainsString('working-directory: candy-code-web', $job, 'bundle legs run in the web dir');
        self::assertStringContainsString('actions/setup-node@', $job, 'node setup pinned');
        self::assertStringContainsString('run: npm run build', $job, 'CI builds the bundle (OD-6)');
        self::assertStringContainsString('git diff --exit-code -- dist/', $job, 'CI proves dist parity');
    }

    /**
     * Boundedness alone never refuses anything — the terminal `exit 1`s are
     * the fail-closed half of the OD-5/OD-7 posture (MINOR-1): without a
     * pin on them, flipping a refuse to `exit 0` or smuggling `|| true` onto
     * the verdict path guts every gate while this guard stays all-green.
     * Pinned shapes per gate: the non-success-conclusion refuse (exit 1 glued
     * to its "refusing <verb>: ... concluded" echo — same line in phar-dev's
     * one-line case arm, next line in the multi-line arms) and the
     * budget-exhaustion refuse below the loop, plus the absence of any
     * `|| true` escape hatch anywhere inside the gate job.
     */
    public function testEveryGatePollsInsideABoundedTimeout(): void
    {
        // file => [gate job name, refuse-message verb]
        $pollers = [
            'release-phar.yml' => ['gate', 'release'],
            'release-app-phar.yml' => ['gate', 'release'],
            'release-candy-top-phar.yml' => ['gate', 'release'],
            'phar-dev.yml' => ['ci-gate', 'build'],
            'sync-sugarcraft.yml' => ['ci-gate', 'sync'],
        ];
        foreach ($pollers as $file => [$job, $verb]) {
            $text = $this->workflow($file);
            self::assertStringContainsString('timeout-minutes: 250', $text, "$file bounds the gate job");
            self::assertSame(
                1,
                substr_count($text, 'for attempt in $(seq 1 240)'),
                "$file has one bounded poll (fail-closed)"
            );

            $gate = $this->jobSection($text, $job);
            self::assertMatchesRegularExpression(
                '/refusing ' . $verb . ': CI at \$\w+ concluded \'\$run_line\'" >&2\W*exit 1/',
                $gate,
                "$file gate refuses on any non-green CI conclusion (the refuse's exit 1 is load-bearing)"
            );
            self::assertMatchesRegularExpression(
                '/no green CI at \$\w+ within the poll budget \(fail-closed\)" >&2\W*exit 1/',
                $gate,
                "$file gate refuses when the poll budget burns out (the refuse's exit 1 is load-bearing)"
            );
            self::assertStringNotContainsString(
                '|| true',
                $gate,
                "$file gate carries no || true escape hatch on the CI-verdict path"
            );
        }
    }

    /**
     * RT-05 (owner ruling OG-1 arm (a)): the five CI-green polls must read the
     * LATEST-COMPLETED run's conclusion, not the alphabetically-worst of every
     * historical run at the SHA. The old filter (`sort_by(.conclusion)`) made a
     * stale cancelled/failure outrank a fresh success, so a successful re-run
     * could never unlock the gate; it now guts nothing on a genuinely-red head
     * because the newest run still governs. Byte-identity across the five
     * copies is the guard - the snippet is copy-paste by design, so ANY drift
     * reddens this tripwire (the divergence the finding cites).
     */
    public function testCiVerdictReadsLatestCompletedRun(): void
    {
        $canonical = '[.[] | select(.status == "completed")] | if length == 0 then "PENDING" else (first(.[]) | .conclusion) end';
        $old = 'sort_by(.conclusion)';
        $files = [
            'phar-dev.yml',
            'release-phar.yml',
            'release-app-phar.yml',
            'release-candy-top-phar.yml',
            'sync-sugarcraft.yml',
        ];
        foreach ($files as $file) {
            $text = $this->workflow($file);
            self::assertSame(
                1,
                substr_count($text, $canonical),
                "$file carries the latest-completed jq filter byte-identically (OG-1 (a), five-copy roster)"
            );
            self::assertStringNotContainsString($old, $text, "$file never re-sorts by alphabetical worst-conclusion");
        }

        // Fabricated run-list fixture in the API's natural newest-first order:
        // a green head run over a stale cancellation must read "success" under
        // (a). The same list under the old alphabetical policy read
        // "cancelled" - that mismatch is the defect being cured.
        $runs = [
            ['status' => 'completed', 'conclusion' => 'success'],
            ['status' => 'completed', 'conclusion' => 'cancelled'],
        ];
        $completed = array_values(array_filter($runs, static fn (array $r): bool => 'completed' === $r['status']));
        self::assertSame('success', $completed[0]['conclusion'], 'latest-completed arm lets the re-run through');

        $alphabetical = $completed;
        usort($alphabetical, static fn (array $a, array $b): int => strcmp($a['conclusion'], $b['conclusion']));
        self::assertSame('cancelled', $alphabetical[0]['conclusion'], 'the old worst-conclusion policy is what we replaced');
    }
}
