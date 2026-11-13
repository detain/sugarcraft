<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards C-SES-9.d: NO workflow in `.github/workflows/` may move a
 * tag/input/step-output value across into the shell by pasting `${{ ... }}`
 * into the text of a `run:` script — every such value must cross through a
 * step `env:` binding. The roster is the whole directory since RT-17
 * (repo-tooling-019); the five release/publisher workflows that started this
 * guard (C-SES-9.d trio + phar-latest.yml and sync-sugarcraft.yml from
 * reviews-1103 G6-P) additionally carry per-file binding pins and body floors.
 *
 * WHY THIS FILE EXISTS AT ALL. GitHub evaluates `${{ }}` textually BEFORE
 * bash ever sees the script: `${{ github.event.inputs.tag }}` and
 * `${{ steps.*.outputs.* }}` inside a `run:` body substitute the raw value
 * into the command line, so a ref or dispatch input containing a single
 * quote breaks out of the quoting around it and the remainder executes as
 * shell. `workflow_dispatch` inputs are attacker-influenceable wherever a
 * collaborator (or a compromised action upstream of one) can reach the
 * dispatch form, and the same interpolation made an honest tag with awkward
 * characters a script-rewrite hazard. The fix shape is the one the platform
 * itself prescribes: bind the value in the step's `env:` (`EVENT`, `TAG`,
 * `APP`) and expand it as a quoted shell variable inside the script — an
 * env value crosses into bash as data, never as source text.
 *
 * THE SCANS ARE REGION-ANCHORED ON PURPOSE (fail-closed YAML READING AS
 * TEXT, same doctrine as ActionYmlInstallTest and
 * CandyCodeImageCacheBustTest next door): the `tools-guards` job of ci.yml
 * runs the phpunit PHAR with no composer autoload, so a symfony/yaml
 * dependency would not be a stronger reader in CI — it would be a skip-path
 * that quietly unruns the headline pins in the one job that owns them. The
 * parser handles both spellings GitHub accepts — `run: |` / `run: >` block
 * scalars whose content is indented deeper than the `run:` line, and
 * one-line `run: cmd` — with or without the sequence dash, and each
 * extractor asserts it found its bodies, so an empty parse reddens here
 * rather than acquitting the whole file. Only `run:` bodies are accused;
 * `env:` bindings and `if:` conditions are the SAFE forms — the evaluator
 * resolves them outside the script text — so they stay legal (arm #3 pins
 * that they actually carry the moved values, which is what stops arm #2
 * from being satisfied by deleting the interpolation without relocating
 * it: the workflow would be broken, and a broken release pipeline must
 * not be able to pass the guard that speaks for it).
 *
 * The context-path spellings the arms accuse are assembled from escaped
 * regex fragments rather than pasted as GitHub-expression literals
 * (plan §1.9's test-literal hazard): a verbatim dollar-brace-brace string
 * in this file is exactly the quote-shape the campaign's literal scans
 * trip over, and a regex does not need the literal to match it.
 *
 * Deliberately standalone (`--no-configuration tools/tests/`, per
 * CONTRIBUTING.md): it parses text, needs no lib autoloader, joins the
 * `tools-guards` job, and mints no candy-code `*Test.php` — no
 * durations.tsv row and no suite-figure change are owed by it.
 */
final class WorkflowRunInterpolationTest extends TestCase
{
    /**
     * The three stable release pipelines whose binding arms are spelled per
     * file (EVENT/TAG/APP); the publisher pair joined the scope in G6-P and
     * carries its own binding set below.
     *
     * @var list<string>
     */
    private const TRIO_WORKFLOWS = [
        '.github/workflows/release-phar.yml',
        '.github/workflows/release-app-phar.yml',
        '.github/workflows/release-candy-top-phar.yml',
    ];

    /**
     * The five release/publisher files that additionally carry per-file env
     * binding pins (arm #3) and body floors. They stay EXPLICIT: silently
     * shrinking their floors is the rot this file's own doctrine forbids.
     *
     * @var list<string>
     */
    private const HEADLINED_WITH_BINDINGS = [
        '.github/workflows/release-phar.yml',
        '.github/workflows/release-app-phar.yml',
        '.github/workflows/release-candy-top-phar.yml',
        '.github/workflows/phar-latest.yml',
        '.github/workflows/sync-sugarcraft.yml',
    ];

    /**
     * Workflows the headline arms deliberately do not speak for, each with a
     * one-line reason (check-path-repos.php's IDLE_DEFERRAL census is the
     * house model: every entry named, reasoned, and enforced alive — an entry
     * pointing at a deleted file reddens testEveryWorkflowIsAccountedFor, so
     * exemptions rot exactly like rosters do). RT-17 verified the whole
     * directory CLEAN at time of writing, so the honest roster is the empty
     * one: an unaccounted-for future workflow must join the roster by default
     * and pass, not sneak past a hand-maintained 5-file list like
     * vhs-smoke-test.yml and vhs-runner-image.yml did.
     *
     * @var array<string,string> rel-path => reason
     */
    private const EXCLUDED_WITH_REASON = [];

    /**
     * The roster the headline arms accuse: EVERY workflow on disk minus the
     * reasoned exclusions (RT-17 — the old guard's hand-written 5-file
     * constant left live violators outside its view; a doctrine that is
     * doctrine must not depend on someone remembering to edit a list).
     *
     * @return list<string> monorepo-relative, sorted
     */
    private static function workflowRoster(): array
    {
        $files = glob(\dirname(__DIR__, 2) . '/.github/workflows/*.yml') ?: [];
        $rel = [];
        foreach ($files as $file) {
            $rel[] = '.github/workflows/' . \basename($file);
        }
        \sort($rel);

        return \array_values(\array_diff($rel, \array_keys(self::EXCLUDED_WITH_REASON)));
    }

    /**
     * Fewest `run:` bodies each file must yield for the parse to be trusted
     * (measured below each file's real count, with room for a step to be
     * merged but not for the extraction to silently find nothing).
     *
     * @var array<string,int>
     */
    private const BODY_FLOORS = [
        '.github/workflows/release-phar.yml' => 10,
        '.github/workflows/release-app-phar.yml' => 8,
        '.github/workflows/release-candy-top-phar.yml' => 8,
        '.github/workflows/phar-latest.yml' => 10,
        '.github/workflows/sync-sugarcraft.yml' => 5,
    ];

    /**
     * Dispatch-input arm. The `i` modifier is load-bearing for the same
     * reason ActionYmlInstallTest's inputs arm carries it: GitHub's
     * expression evaluator resolves context names case-insensitively, so a
     * re-spelled `${{ GITHUB.EVENT.INPUTS.TAG }}` binds the same input and
     * opens the same door — a case-sensitive scan would let the forgery
     * through.
     */
    private const INTERPOLATES_EVENT_INPUTS = '/\$\{\{\s*github\.event\.inputs\./i';

    /** Same reasoning for the step-output arm: outputs resolve case-insensitively too. */
    private const INTERPOLATES_STEP_OUTPUTS = '/\$\{\{\s*steps\./i';

    /**
     * The matrix arm (RT-17): matrix values are workflow-matrix JSON, and in
     * ci.yml that JSON is generated from PR-influenceable discovery output —
     * the exact value class the RT-01 spine routed through `env: LIB:`. The
     * repo measured ZERO matrix pastes inside run bodies at widening time, so
     * accusing it costs nothing today and closes the third leg of the same
     * door. `with:`/`if:` uses of `${{ matrix.* }}` stay legal: the parser
     * only scans run bodies (the finding's explicit narrowing).
     */
    private const INTERPOLATES_MATRIX = '/\$\{\{\s*matrix\./i';

    /**
     * Every `run:` body of one workflow, in file order, newlines intact.
     *
     * Minimal YAML by design (see the class doc-block); this is the same
     * parser shape {@see ActionYmlInstallTest} documents, kept as a local
     * copy because that guard's file is outside this step's ownership and
     * moving the parser into `_bootstrap.php` would rewrite two shipped
     * guards to add a third consumer.
     *
     * @return list<string>
     */
    private static function runBodies(string $rel): array
    {
        $raw = self::read($rel);
        $lines = \explode("\n", $raw);
        $total = \count($lines);
        $bodies = [];

        for ($i = 0; $i < $total; $i++) {
            $line = \rtrim($lines[$i], "\r");
            if (\preg_match('/^(\s*)(?:-\s+)?run:(\s+)(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $rest = \trim($m[3]);
            if ($rest === '') {
                continue;
            }
            if ($rest[0] !== '|' && $rest[0] !== '>') {
                $bodies[] = $rest;
                continue;
            }

            $keyIndent = \strlen($m[1]);
            $body = [];
            for ($j = $i + 1; $j < $total; $j++) {
                $next = \rtrim($lines[$j], "\r");
                if (\trim($next) === '') {
                    $body[] = '';
                    continue;
                }
                $indent = \strlen($next) - \strlen(\ltrim($next, ' '));
                if ($indent <= $keyIndent) {
                    break;
                }
                $body[] = $next;
            }
            while ($body !== [] && \end($body) === '') {
                \array_pop($body);
            }
            $bodies[] = \implode("\n", $body);
            $i = $j - 1;
        }

        return $bodies;
    }

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
     * Anti-vacuity floor: the parser must actually find the steps in every
     * roster file. Any real edit that makes the extraction return nothing
     * reddens here, not everywhere silently. The five binding-pinned files
     * keep their per-file floors; the rest are caught by a roster-wide
     * floor (RT-17 measured 181 bodies across 15 workflows; 170 leaves room
     * for a few merged steps, none for a blinded parser).
     */
    public function testTheRunBodiesAreActuallyExtracted(): void
    {
        $roster = self::workflowRoster();
        self::assertGreaterThan(0, \count($roster), 'the workflow roster is empty — the glob found nothing to accuse');
        foreach (self::HEADLINED_WITH_BINDINGS as $rel) {
            self::assertContains($rel, $roster, $rel . ': dropped out of the roster — renamed away from *.yml or silently excluded');
        }

        $total = 0;
        foreach ($roster as $rel) {
            $bodies = self::runBodies($rel);
            $total += \count($bodies);
            if (isset(self::BODY_FLOORS[$rel])) {
                self::assertGreaterThanOrEqual(
                    self::BODY_FLOORS[$rel],
                    \count($bodies),
                    $rel . ': the run: parser found too few bodies — a vacuous pass here would acquit the whole file'
                );
            }
            foreach ($bodies as $index => $body) {
                self::assertNotSame('', \trim($body), $rel . ": run body #$index is empty — the parser mis-sliced the file");
            }
        }
        self::assertGreaterThanOrEqual(170, $total, 'the whole-roster parse found far fewer run bodies than the measured corpus — the extraction is going blind');
    }

    /**
     * RT-17 staleness arm: every file in the workflows directory must be
     * accounted for — either caught by the *.yml glob (roster) or sitting in
     * EXCLUDED_WITH_REASON naming a file that still exists with a non-empty
     * reason. Two rot classes die here: an exclusion kept after its workflow
     * is deleted (lucky exoneration for whatever file claims that name next
     * is one glob away), and a workflow authored as `.yaml` — GitHub runs it,
     * the glob does not see it.
     */
    public function testEveryWorkflowIsAccountedFor(): void
    {
        $dir = \dirname(__DIR__, 2) . '/.github/workflows';

        $yamlLookalikes = \array_values(\array_filter(
            \scandir($dir) ?: [],
            static fn (string $f): bool => \str_ends_with(\strtolower($f), '.yaml')
        ));
        self::assertSame(
            [],
            $yamlLookalikes,
            'workflow files with a .yaml extension are invisible to the *.yml roster glob — rename them or widen the glob here'
        );

        $onDisk = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if (\str_ends_with($entry, '.yml')) {
                $onDisk['.github/workflows/' . $entry] = true;
            }
        }

        foreach (self::EXCLUDED_WITH_REASON as $rel => $reason) {
            self::assertArrayHasKey($rel, $onDisk, $rel . ': excluded but the file is gone — drop the stale exemption (every entry must name a live workflow)');
            self::assertNotSame('', \trim($reason), $rel . ': excluded without a reason');
        }

        // The roster is defined as glob minus exclusions, so the only way a
        // live .yml escapes it is a bug in that definition — pin it directly.
        foreach (\array_keys($onDisk) as $rel) {
            $isRostered = \in_array($rel, self::workflowRoster(), true);
            $isExcluded = isset(self::EXCLUDED_WITH_REASON[$rel]);
            self::assertTrue(
                $isRostered !== $isExcluded,
                $rel . ': neither in the accused roster nor in the reasoned exclusions — account for it one way or the other'
            );
        }
    }

    /**
     * The headline arm: no run-body script text in ANY roster workflow may
     * interpolate a dispatch input, a step output, or a matrix value (the
     * three value classes a PR or dispatch payload can shape). Those values
     * arrive through `env:` (arm #3 pins that bindings for the moved spellings
     * actually exist).
     */
    public function testNoRunBodyInterpolatesDispatchInputsOrStepOutputs(): void
    {
        foreach (self::workflowRoster() as $rel) {
            foreach (self::runBodies($rel) as $index => $body) {
                self::assertDoesNotMatchRegularExpression(
                    self::INTERPOLATES_EVENT_INPUTS,
                    $body,
                    $rel . ": run body #$index interpolates a github.event.inputs value into the script — "
                        . 'script-injection door open; the value must cross through the step env: instead'
                );
                self::assertDoesNotMatchRegularExpression(
                    self::INTERPOLATES_STEP_OUTPUTS,
                    $body,
                    $rel . ": run body #$index interpolates a steps.* output into the script — "
                        . 'script-injection door open; the value must cross through the step env: instead'
                );
                self::assertDoesNotMatchRegularExpression(
                    self::INTERPOLATES_MATRIX,
                    $body,
                    $rel . ": run body #$index interpolates a matrix.* value into the script — "
                        . 'the RT-01 spine env-binds matrix values for exactly this reason (ci.yml is measured clean); '
                        . 'the value must cross through the step env: instead'
                );
            }
        }
    }

    /**
     * Positive anchors beside the negative arm: every value the headline
     * arm refuses inside a script must be RE-ESTABLISHED as an `env:`
     * binding, and the scripts must read it back as a quoted shell variable.
     * Deleting the interpolation without moving the value would leave the
     * release pipeline broken — and a broken pipeline must not pass the
     * guard that speaks for it.
     */
    public function testTheMovedValuesArriveThroughStepEnv(): void
    {
        // github.event_name rides env too (the dispatch branch decision is
        // part of the same paste-into-script class), in every workflow.
        $eventName = '/EVENT:\s*\$\{\{\s*github\.event_name\s*\}\}/';
        // The dispatch input, bound at the resolve step of every workflow.
        $inputTag = '/TAG:\s*\$\{\{\s*github\.event\.inputs\.tag\s*\}\}/';
        // The step outputs, bound wherever a later step needs them.
        $tagStepOutput = [
            '.github/workflows/release-phar.yml' => '/TAG:\s*\$\{\{\s*steps\.tag\.outputs\.tag\s*\}\}/',
            '.github/workflows/release-candy-top-phar.yml' => '/TAG:\s*\$\{\{\s*steps\.tag\.outputs\.tag\s*\}\}/',
            // The fleet workflow resolves into the `scope` step and rebinds
            // both app and tag from it.
            '.github/workflows/release-app-phar.yml' => '/TAG:\s*\$\{\{\s*steps\.scope\.outputs\.tag\s*\}\}/',
        ];
        $appStepOutput = '/APP:\s*\$\{\{\s*steps\.scope\.outputs\.app\s*\}\}/';

        foreach (self::TRIO_WORKFLOWS as $rel) {
            $raw = self::read($rel);
            self::assertMatchesRegularExpression($eventName, $raw, $rel . ': no EVENT env binding — the dispatch branch would read nothing');
            self::assertMatchesRegularExpression($inputTag, $raw, $rel . ': no TAG env binding for the dispatch input — the headline arm must be satisfied by relocation, not deletion');
            self::assertMatchesRegularExpression($tagStepOutput[$rel], $raw, $rel . ': no TAG env binding for the resolved step output');
        }

        // The fleet workflow additionally moves `app` the same way, and its
        // build/sign/checksum/smoke/publish legs each bind it.
        self::assertMatchesRegularExpression($appStepOutput, self::read('.github/workflows/release-app-phar.yml'), 'release-app-phar.yml: no APP env binding for the resolved step output');

        // And the scripts must actually reference the variables (quoted) —
        // the bindings alone would leave the steps reading nothing.
        $tagRef = 'git checkout -B phar-build "$TAG"';
        foreach (self::TRIO_WORKFLOWS as $rel) {
            $joined = \implode("\n", self::runBodies($rel));
            self::assertStringContainsString($tagRef, $joined, $rel . ': no run body reads the TAG env binding where the checkout leg used to interpolate the step output');
        }
        $appJoined = \implode("\n", self::runBodies('.github/workflows/release-app-phar.yml'));
        self::assertStringContainsString('app="$APP"', $appJoined, 'release-app-phar.yml: no run body reads the APP env binding');
        self::assertStringContainsString('tag="$TAG"', $appJoined, 'release-app-phar.yml: no run body reads the TAG env binding in the fleet steps');

        // G6-P: the publisher pair moved under this guard when phar-latest and
        // sync gained their gate jobs. Same law, their own variable set: the
        // dispatch input there is the boolean `all` (phar-latest only), the CI
        // poller's commit arrives as TARGET_SHA, and the affected-range legs
        // read github.event.before/sha through env - never pasted inline.
        $publisherBindings = [
            '.github/workflows/phar-latest.yml' => [
                '/EVENT_NAME:\s*\$\{\{\s*github\.event_name\s*\}\}/',
                '/DISPATCH_ALL:\s*\$\{\{\s*github\.event\.inputs\.all\s*\}\}/',
                // WF-CURE redesign: the tested commit arrives from the
                // workflow_run payload (or the dispatched ref) through env.
                '/WR_SHA:\s*\$\{\{\s*github\.event\.workflow_run\.head_sha\s*\}\}/',
                '/DISPATCH_SHA:\s*\$\{\{\s*github\.sha\s*\}\}/',
            ],
            '.github/workflows/sync-sugarcraft.yml' => [
                '/TARGET_SHA:\s*\$\{\{\s*github\.sha\s*\}\}/',
                '/BEFORE_SHA:\s*\$\{\{\s*github\.event\.before\s*\}\}/',
                '/HEAD_SHA:\s*\$\{\{\s*github\.sha\s*\}\}/',
            ],
        ];
        foreach ($publisherBindings as $rel => $patterns) {
            $raw = self::read($rel);
            foreach ($patterns as $pattern) {
                self::assertMatchesRegularExpression($pattern, $raw, $rel . ': missing env binding ' . $pattern);
            }
            $joined = \implode("\n", self::runBodies($rel));
            $shaVar = \str_contains($rel, 'phar-latest') ? '$WR_SHA' : '$TARGET_SHA';
            self::assertStringContainsString($shaVar, $joined, $rel . ': no run body reads the ' . $shaVar . ' env binding');
        }
        $devJoined = \implode("\n", self::runBodies('.github/workflows/phar-latest.yml'));
        self::assertStringContainsString('${DISPATCH_ALL', $devJoined, 'phar-latest.yml: no run body reads the DISPATCH_ALL env binding');
        self::assertStringContainsString('"$EVENT_NAME"', $devJoined, 'phar-latest.yml: no run body reads the EVENT_NAME env binding');
        $syncJoined = \implode("\n", self::runBodies('.github/workflows/sync-sugarcraft.yml'));
        self::assertStringContainsString('${BEFORE_SHA', $syncJoined, 'sync-sugarcraft.yml: no run body reads the BEFORE_SHA env binding');
    }
}
