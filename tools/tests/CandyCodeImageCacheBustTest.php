<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards C-SES-5.d: the GHCR serve image must ship the published stream it
 * resolved, not a cached snapshot of whenever the build command last moved.
 *
 * WHY THIS FILE EXISTS AT ALL. The create-project `RUN` instruction text was
 * constant, the workflow cached with `cache-from: type=gha`, and so the layer
 * was served CACHED on every run after the first — `:latest` and the sha tags
 * pointed at a frozen snapshot while claiming freshness. Three independent
 * seams made that unfixable by any single edit, and each gets a pin here:
 *
 *  - the Dockerfile layer must consume a per-run build ARG (else its cache
 *    key never changes) and record the resolved reference in the image, so a
 *    Packagist lag becomes a visible `BUILD-REF` row rather than a silent
 *    frozen layer;
 *  - the publishing trigger must ride AFTER `sync-sugarcraft.yml` completes
 *    (`workflow_run`) — a push-triggered build raced the very split-push that
 *    updates the stream it resolves;
 *  - the `release` arm must fire only for `candy-code/v*` tags — a bare
 *    `release: [published]` re-tagged this image for every other app's tag in
 *    the shared monorepo stream.
 *
 * THE SCANS ARE REGION-ANCHORED ON PURPOSE (fail-closed YAML reading, same
 * doctrine as ActionYmlInstallTest next door): every assertion checks content
 * INSIDE the extracted `on:` block, job-level `if:`, metadata/build-args
 * `with:` block, or the create-project `RUN`. A file whose trigger was moved
 * into a comment, or whose gate lives in the wrong step, cannot satisfy these
 * — and each extractor asserts it found its region, so an empty parse reddens
 * here rather than acquitting the whole file.
 *
 * Deliberately standalone (`--no-configuration tools/tests/`, per
 * CONTRIBUTING.md): it parses text, needs no lib autoloader, joins the
 * `tools-guards` job, and mints no candy-code `*Test.php` — no durations.tsv
 * row and no suite-figure change are owed by it.
 */
final class CandyCodeImageCacheBustTest extends TestCase
{
    /** The image definition, relative to the monorepo root. */
    private const DOCKERFILE = 'candy-code/Dockerfile';

    /** The publishing workflow, relative to the monorepo root. */
    private const WORKFLOW = '.github/workflows/candy-code-image.yml';

    /** The workflow whose completion the publishing arm must wait for. */
    private const SYNC_WORKFLOW_NAME = 'Sync to sugarcraft org';

    private static function read(string $rel): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $rel;
        $raw = @\file_get_contents($path);
        self::assertIsString(
            $raw,
            $rel . ' is unreadable, so this guard would speak for nothing'
        );

        return $raw;
    }

    /**
     * The create-project `RUN` block: the RUN keyword line plus every
     * backslash-continuation line, as one string. Fail-closed: exactly one
     * such RUN must exist in the Dockerfile, or the pin has no subject.
     */
    private static function createProjectRun(): string
    {
        $lines = \explode("\n", self::read(self::DOCKERFILE));
        $total = \count($lines);
        $blocks = [];

        for ($i = 0; $i < $total; $i++) {
            $line = \rtrim($lines[$i], "\r");
            if (\preg_match('/^RUN\b/', $line) !== 1) {
                continue;
            }
            $block = [$line];
            for ($j = $i + 1; $j < $total; $j++) {
                $cont = \rtrim($lines[$j], "\r");
                $block[] = $cont;
                if (\preg_match('/\\\\$/', \rtrim($cont)) !== 1) {
                    break;
                }
            }
            // The create-project call may ride a CONTINUATION line — the
            // cache-bust ARG is echoed on the RUN's first line precisely so
            // the whole layer's key turns with it — so filter on the
            // assembled block, not the keyword line.
            $assembled = \implode("\n", $block);
            if (\str_contains($assembled, 'composer create-project')) {
                $blocks[] = $assembled;
            }
        }

        self::assertCount(1, $blocks, 'expected exactly one composer create-project RUN in ' . self::DOCKERFILE);

        return $blocks[0];
    }

    /**
     * The top-level `on:` block of the workflow, up to the next column-0 key.
     * Extracted, not substring-searched: the header comment discusses these
     * trigger names, and a guard that accused comments would be ignored; a
     * guard that PASSES on them is forged.
     */
    private static function onSection(): string
    {
        $lines = \explode("\n", self::read(self::WORKFLOW));
        $block = [];
        $in = false;

        foreach ($lines as $line) {
            $line = \rtrim($line, "\r");
            if ($line === 'on:') {
                $in = true;
                continue;
            }
            if ($in && \preg_match('/^\S/', $line) === 1) {
                break;
            }
            if ($in) {
                $block[] = $line;
            }
        }

        $text = \implode("\n", $block);
        self::assertNotSame('', $text, 'no top-level on: block was found in ' . self::WORKFLOW);

        return $text;
    }

    /**
     * The `build` job's top-level `if:` value, read through the SAME YAML
     * parser the pinned-versions guard next door uses. Region anchoring is
     * preserved — the value is taken from the parse tree at jobs.build.if, so
     * a comment or a step-level gate cannot satisfy the assertions — and a
     * hand-rolled fold could not: the `>-` scalar's newlines join with
     * single spaces, gluing a trailing `(` of one line to the next, and the
     * explanatory comment lines inside the block would be captured as value.
     */
    private static function jobIf(): string
    {
        if (!\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            self::markTestSkipped('symfony/yaml is unavailable, so the workflow cannot be parsed');
        }

        $path = \dirname(__DIR__, 2) . '/' . self::WORKFLOW;
        try {
            /** @var mixed $workflow */
            $workflow = \Symfony\Component\Yaml\Yaml::parseFile($path);
        } catch (\Throwable $e) {
            self::fail(self::WORKFLOW . ' does not parse: ' . $e->getMessage());
        }

        self::assertIsArray($workflow, self::WORKFLOW . ' did not parse to a mapping');
        $if = $workflow['jobs']['build']['if'] ?? null;
        self::assertIsString($if, 'the build job in ' . self::WORKFLOW . ' carries no string top-level if: — every trigger admission gate would be missing');

        return \preg_replace('/\s+/', ' ', $if);
    }

    /**
     * A step's `with:` block by the action it `uses:`.
     */
    private static function stepWith(string $usesNeedle): string
    {
        $lines = \explode("\n", self::read(self::WORKFLOW));
        $total = \count($lines);

        for ($i = 0; $i < $total; $i++) {
            $line = \rtrim($lines[$i], "\r");
            if (\preg_match('/^(\s+)uses: \S*' . \preg_quote($usesNeedle, '/') . '/', $line, $m) !== 1) {
                continue;
            }
            // `uses:` and `with:` are SIBLING keys of one step: the walk
            // stays in the step while lines sit at the uses-indent (those are
            // keys — with:, id:, name: of the same step) or deeper (those are
            // block content), and leaves on any line shallower.
            $stepIndent = \strlen($m[1]);
            $body = [];
            $seenWith = false;
            for ($j = $i + 1; $j < $total; $j++) {
                $next = \rtrim($lines[$j], "\r");
                if (\trim($next) === '') {
                    if ($seenWith) {
                        $body[] = $next;
                    }
                    continue;
                }
                $indent = \strlen($next) - \strlen(\ltrim($next, ' '));
                if ($indent < $stepIndent) {
                    break;
                }
                if ($indent === $stepIndent) {
                    if (\preg_match('/^\s*with:\s*$/', $next) === 1) {
                        $seenWith = true;
                        continue;
                    }
                    if ($seenWith) {
                        break;
                    }
                    continue;
                }
                if ($seenWith) {
                    $body[] = $next;
                }
            }
            if ($seenWith) {
                return \implode("\n", $body);
            }
        }

        self::fail('no step using ' . $usesNeedle . ' with a with: block was found in ' . self::WORKFLOW);
    }

    /**
     * The run body of the step named "Summary" (the job-summary printer).
     */
    private static function summaryRunBody(): string
    {
        $lines = \explode("\n", self::read(self::WORKFLOW));
        $total = \count($lines);

        for ($i = 0; $i < $total; $i++) {
            $line = \rtrim($lines[$i], "\r");
            if (\preg_match('/^\s*-\s*name:\s*Summary\b/', $line) !== 1) {
                continue;
            }
            $body = [];
            $inRun = false;
            for ($j = $i + 1; $j < $total; $j++) {
                $next = \rtrim($lines[$j], "\r");
                if (\preg_match('/^\s*run:/', $next) === 1) {
                    $inRun = true;
                    continue;
                }
                if (!$inRun) {
                    continue;
                }
                if (\trim($next) === '') {
                    $body[] = '';
                    continue;
                }
                $indent = \strlen($next) - \strlen(\ltrim($next, ' '));
                if ($indent <= 6) {
                    break;
                }
                $body[] = $next;
            }
            self::assertNotSame([], \array_filter($body, static fn (string $l): bool => \trim($l) !== ''), 'the Summary step has an empty run: body');

            return \implode("\n", $body);
        }

        self::fail('no "Summary" step was found in ' . self::WORKFLOW);
    }

    public function testTheCreateProjectRunConsumesAPerRunBuildArg(): void
    {
        $run = self::createProjectRun();

        // The ARG must be DECLARED (Docker scoping: an ARG the RUN cannot see
        // is text, not a cache key) and CONSUMED inside the RUN itself — the
        // value has to reach the layer's instruction, which is exactly what
        // the original file lacked.
        $dockerfile = self::read(self::DOCKERFILE);
        $argAt = \preg_match('/^ARG CANDY_CODE_REF\S*$/m', $dockerfile);
        self::assertSame(1, $argAt, 'the Dockerfile declares no top-level ARG CANDY_CODE_REF');
        self::assertLessThan(
            (int) \strpos($dockerfile, 'RUN echo "candy-code build ref'),
            (int) \strpos($dockerfile, 'ARG CANDY_CODE_REF'),
            'the ARG must be declared before the RUN that consumes it'
        );
        self::assertStringContainsString('${CANDY_CODE_REF}', $run, 'the create-project RUN never reads CANDY_CODE_REF — its instruction text stays constant and the gha cache serves it frozen');
        self::assertStringContainsString('composer create-project', $run);
    }

    public function testTheWorkflowPassesTheRefBuildArgPerRun(): void
    {
        $with = self::stepWith('docker/build-push-action@');

        // Docker's build-args list spelling (`NAME=value` under a block
        // scalar) is what the build-push action documents; the value form
        // CANDY_CODE_REF=<sha>-<run id> is the plan's, verbatim.
        self::assertMatchesRegularExpression(
            '/build-args:\s*\|\s*\n\s*CANDY_CODE_REF=\$\{\{\s*github\.sha\s*\}\}-\$\{\{\s*github\.run_id\s*\}\}/',
            $with,
            'the build-push step must pass CANDY_CODE_REF=<sha>-<run id> — a value that repeats cannot bust the layer cache'
        );
    }

    public function testTheImageRecordsAndTheSummaryPrintsTheResolvedRef(): void
    {
        $run = self::createProjectRun();

        // The reference must be extracted FROM composer's own JSON (the plan
        // names the tool), must name the BUILD-REF path, and must FAIL THE
        // BUILD when it cannot parse — a silently missing BUILD-REF is the
        // same lie with better manners.
        self::assertStringContainsString('composer show', $run, 'the create-project RUN resolves but never interrogates the package stream');
        self::assertStringContainsString('--format=json', $run);
        self::assertStringContainsString('BUILD-REF', $run);
        self::assertStringContainsString('exit(1)', $run, 'the BUILD-REF extractor must be fail-closed, not best-effort');
        self::assertMatchesRegularExpression('/json_decode\(/', $run);

        $summary = self::summaryRunBody();
        self::assertStringContainsString('/opt/candy-code/BUILD-REF', $summary, 'the job summary must read BUILD-REF out of the built image');
        self::assertStringContainsString('BUILD-REF', $summary);
        self::assertStringContainsString('GITHUB_STEP_SUMMARY', $summary);
    }

    public function testPublishingRidesTheSyncWorkflowNotARawPush(): void
    {
        $on = self::onSection();

        self::assertStringContainsString('workflow_run:', $on, 'no workflow_run arm — publishing cannot be sequenced after the split sync');
        self::assertStringContainsString(self::SYNC_WORKFLOW_NAME, $on, 'the workflow_run arm must name the sync workflow by its title');
        self::assertStringContainsString('completed', $on);
        self::assertDoesNotMatchRegularExpression('/(^|\n) {2}push:/', $on, 'the raw master-push publishing arm must be gone — it raced the sync that feeds the stream the image resolves');

        $if = self::jobIf();
        self::assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $if, 'a failed sync run must not trigger a publishing build');
        self::assertStringContainsString("github.event.workflow_run.head_branch == 'master'", $if, 'only master syncs publish');
    }

    public function testTheReleaseArmIsFilteredToCandyCodeTags(): void
    {
        $if = self::jobIf();

        // Both halves are load-bearing: the event-name guard must not gate
        // OTHER events, and the tag prefix is the entire point.
        self::assertStringContainsString("github.event_name != 'release'", $if);
        self::assertStringContainsString("startsWith(github.event.release.tag_name, 'candy-code/v')", $if, 'an unrelated app release re-tagging this image was the defect');

        $on = self::onSection();
        self::assertMatchesRegularExpression('/(^|\n) {2}release:\n {4}types: \[published\]/', $on);
    }

    public function testThePullRequestProofArmSurvives(): void
    {
        $on = self::onSection();

        self::assertMatchesRegularExpression('/(^|\n) {2}pull_request:/', $on, 'the pre-merge proof arm must stay: this workflow lost nine consecutive red publishes before it existed');
        self::assertStringContainsString('candy-code/Dockerfile', $on);

        $with = self::stepWith('docker/build-push-action@');
        self::assertStringContainsString("push: \${{ github.event_name != 'pull_request' }}", $with, 'PR runs must build but never publish');
    }

    public function testTheVersionedTagAndRevisionLabelAreConfigured(): void
    {
        $with = self::stepWith('docker/metadata-action@');

        self::assertStringContainsString('type=raw,value=latest', $with);
        self::assertStringContainsString('type=sha,prefix=', $with);
        self::assertStringContainsString('type=match,pattern=candy-code/v(.*),group=1', $with, 'candy-code/v* releases must also publish a versioned tag');
        self::assertStringContainsString('org.opencontainers.image.revision=', $with, 'the image must name the commit it was built from');
    }
}
