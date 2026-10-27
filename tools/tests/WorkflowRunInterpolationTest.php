<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards C-SES-9.d: the three phar-release workflows must move every
 * tag/input value across into the shell through step `env:` bindings, never
 * by pasting `${{ ... }}` into the text of a `run:` script.
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
     * The workflows this guard speaks for, relative to the monorepo root.
     *
     * @var list<string>
     */
    private const WORKFLOWS = [
        '.github/workflows/release-phar.yml',
        '.github/workflows/release-app-phar.yml',
        '.github/workflows/release-candy-top-phar.yml',
    ];

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
     * Anti-vacuity floor: the parser must actually find the steps in all
     * three files. Any real edit that makes the extraction return nothing
     * reddens here, not everywhere silently.
     */
    public function testTheRunBodiesAreActuallyExtracted(): void
    {
        foreach (self::WORKFLOWS as $rel) {
            $bodies = self::runBodies($rel);
            self::assertGreaterThanOrEqual(
                self::BODY_FLOORS[$rel],
                \count($bodies),
                $rel . ': the run: parser found too few bodies — a vacuous pass here would acquit the whole file'
            );
            foreach ($bodies as $index => $body) {
                self::assertNotSame('', \trim($body), $rel . ": run body #$index is empty — the parser mis-sliced the file");
            }
        }
    }

    /**
     * The headline arm: no script text may interpolate a dispatch input or a
     * step output. Those values arrive through `env:` (arm #3 pins that
     * bindings for the moved spellings actually exist).
     */
    public function testNoRunBodyInterpolatesDispatchInputsOrStepOutputs(): void
    {
        foreach (self::WORKFLOWS as $rel) {
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

        foreach (self::WORKFLOWS as $rel) {
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
        foreach (self::WORKFLOWS as $rel) {
            $joined = \implode("\n", self::runBodies($rel));
            self::assertStringContainsString($tagRef, $joined, $rel . ': no run body reads the TAG env binding where the checkout leg used to interpolate the step output');
        }
        $appJoined = \implode("\n", self::runBodies('.github/workflows/release-app-phar.yml'));
        self::assertStringContainsString('app="$APP"', $appJoined, 'release-app-phar.yml: no run body reads the APP env binding');
        self::assertStringContainsString('tag="$TAG"', $appJoined, 'release-app-phar.yml: no run body reads the TAG env binding in the fleet steps');
    }
}
