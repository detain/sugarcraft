<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped documentation example `candy-code/docs/examples/pr-comment-bot.yml` —
 * the association-gated issue-comment bot ruled on in the comparison campaign.
 *
 * WHY A DOC EXAMPLE NEEDS A BUILD GUARD AT ALL. The file is nothing this
 * repository's CI executes, which is exactly the hazard: a workflow nobody
 * runs drifts silently, and this one's drift modes are not cosmetic — every
 * plausible "simplification" a future editor reaches for (check the head out
 * so the review sees the tree, move the comment step into the run job to save
 * a handoff, pass the review text straight into the script because it is only
 * Markdown) reintroduces the exfiltration or script-injection shape the
 * doctrine in docs/CI.md spends two jobs avoiding. Comments say "do not";
 * only a red build does.
 *
 * THE PINS, each one a named load-bearing choice of the example:
 *   1. No `${{ }}` inside any `run:` body (the script-injection arm; values
 *      cross through `env:` — the relocation is pinned positively too, so
 *      deleting an interpolation without moving it reddens here, the same
 *      anti-deletion discipline as WorkflowRunInterpolationTest next door).
 *   2. The `author_association` gate is present, with all three permitted
 *      associations and the `@candy-code` mention test, inside `if:` bodies —
 *      `issue_comment` runs in the base context WITH secrets, so without the
 *      gate any stranger's comment directs a token-bearing paid run.
 *   3. The file never mentions the target event or a checkout action. The
 *      safe shape needs neither, so an accusation on bare presence is sound
 *      here and fails CLOSED: a reworded evasion still has to explain itself.
 *   4. The write scope is solitary AND positioned: exactly one
 *      `pull-requests: write` line in the file, below the second job's key,
 *      with the read scopes above it in the run job. Count alone would pass
 *      a file that put the write scope in the job that touches model text.
 *   5. The run leg is `plan` mode with a `max-cost` ceiling — the example is
 *      the recommended shape for other people's repos, and an unbounded
 *      write-capable review bot is not a thing to be teaching.
 *
 * Like its neighbours this guard parses YAML as TEXT (the `tools-guards` job
 * runs a phpunit PHAR with no composer autoload — symfony/yaml would be a
 * skip-path, not a stronger reader) and keeps its own copy of the region
 * parser, per the licensing note in WorkflowRunInterpolationTest: sharing it
 * through `_bootstrap.php` would rewrite two shipped guards to add a third
 * consumer. Deliberately standalone (`--no-configuration tools/tests/`); it
 * mints no candy-code `*Test.php`, so no durations.tsv row and no suite-figure
 * change are owed by it.
 */
final class PrCommentBotExampleTest extends TestCase
{
    /** The example this guard speaks for, relative to the monorepo root. */
    private const EXAMPLE_FILE = 'candy-code/docs/examples/pr-comment-bot.yml';

    /**
     * The script-injection arm: ANY interpolation inside a `run:` body, not
     * just the context paths the neighbours single out. The example's contract
     * is stricter than the release workflows' — every dynamic value it has
     * crosses through `env:`, so no `${{` is legitimate inside a script here,
     * whatever context it names.
     */
    private const INTERPOLATES_ANYTHING = '/\$\{\{/';

    /** Fewest `run:` bodies the parse must yield before its silence means anything. */
    private const MIN_RUN_BODIES = 2;

    /** Fewest `if:` bodies likewise (the gate arm and the job-2 condition). */
    private const MIN_IF_BODIES = 2;

    /**
     * Every `run:` body of the example, in file order, newlines intact.
     *
     * The two spellings GitHub accepts — `run: |` / `run: >` block scalars
     * whose content is indented deeper than the `run:` line, and one-line
     * `run: cmd` — with or without the sequence dash; a comment line naming
     * `run:` cannot match because keys anchor at line start.
     *
     * @return list<string>
     */
    private static function runBodies(string $raw): array
    {
        return self::bodiesForKey($raw, 'run');
    }

    /**
     * Every `if:` body of the example — one-line conditions (job 2's output
     * check) and the block-folded association gate (job 1) both included.
     *
     * @return list<string>
     */
    private static function conditionBodies(string $raw): array
    {
        return self::bodiesForKey($raw, 'if');
    }

    /**
     * Shared block walker for the two key shapes above.
     *
     * @return list<string>
     */
    private static function bodiesForKey(string $raw, string $key): array
    {
        $lines = \explode("\n", $raw);
        $total = \count($lines);
        $bodies = [];

        for ($i = 0; $i < $total; $i++) {
            $line = \rtrim($lines[$i], "\r");
            if (\preg_match('/^(\s*)(?:-\s+)?' . $key . ':(\s+)(.*)$/', $line, $m) !== 1) {
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

    private static function read(): string
    {
        $path = \dirname(__DIR__, 2) . '/' . self::EXAMPLE_FILE;
        $raw = @\file_get_contents($path);
        self::assertIsString(
            $raw,
            self::EXAMPLE_FILE . ' is unreadable, so this guard would speak for nothing'
        );

        return $raw;
    }

    /**
     * Anti-vacuity floor: the parsers must actually find the steps and the
     * gates. Any edit that makes either extraction return nothing reddens
     * here, not everywhere silently.
     */
    public function testTheBodiesAreActuallyExtracted(): void
    {
        $raw = self::read();

        $runs = self::runBodies($raw);
        self::assertGreaterThanOrEqual(
            self::MIN_RUN_BODIES,
            \count($runs),
            'the run: parser found too few bodies — a vacuous pass here would acquit the whole file'
        );
        foreach ($runs as $index => $body) {
            self::assertNotSame('', \trim($body), "run body #$index is empty — the parser mis-sliced the file");
        }

        $ifs = self::conditionBodies($raw);
        self::assertGreaterThanOrEqual(
            self::MIN_IF_BODIES,
            \count($ifs),
            'the if: parser found too few bodies — the gate arm below would scan nothing'
        );
        foreach ($ifs as $index => $body) {
            self::assertNotSame('', \trim($body), "if body #$index is empty — the parser mis-sliced the file");
        }
    }

    /**
     * Pin 1, negative half: no script text interpolates anything.
     */
    public function testNoRunBodyInterpolatesGitHubExpressions(): void
    {
        foreach (self::runBodies(self::read()) as $index => $body) {
            self::assertDoesNotMatchRegularExpression(
                self::INTERPOLATES_ANYTHING,
                $body,
                "run body #$index interpolates a value into the script — the script-injection door; "
                    . 'the value must cross through the step env: instead'
            );
        }
    }

    /**
     * Pin 1, discriminator: the scan is armed, not decorative. The forgery is
     * assembled from fragments rather than pasted as a GitHub-expression
     * literal (plan §1.9's test-literal hazard), and the harmless shell
     * expansions the example DOES use must not match — proving the arm
     * accuses the marker itself, not dollars or braces generally.
     */
    public function testTheInterpolationArmDiscriminates(): void
    {
        $forgery = '$' . '{' . '{ steps.bot.outputs.result }' . '}';
        self::assertMatchesRegularExpression(
            self::INTERPOLATES_ANYTHING,
            $forgery,
            'the interpolation pattern does not even match a pasted expression — the headline arm is vacuous'
        );
        foreach (['"$REVIEW"', '"$PR_URL"', '"$RUNNER_TEMP/comment.md"'] as $shellVariable) {
            self::assertDoesNotMatchRegularExpression(
                self::INTERPOLATES_ANYTHING,
                $shellVariable,
                'the interpolation pattern accuses plain shell expansion — it would convict the correct file'
            );
        }
    }

    /**
     * Pin 2: the association gate — mention test, the pull-request check, and
     * all three permitted associations — lives in the `if:` bodies. A gate
     * moved into a comment, a step, or one association only is this arm going
     * red.
     */
    public function testTheAssociationGateIsPresentAndComplete(): void
    {
        $gate = \implode("\n", self::conditionBodies(self::read()));
        foreach ([
            'github.event.issue.pull_request',
            "'@candy-code'",
            'author_association',
            "'OWNER'",
            "'MEMBER'",
            "'COLLABORATOR'",
        ] as $needle) {
            self::assertStringContainsString(
                $needle,
                $gate,
                'the if: bodies no longer carry ' . $needle . ' — the issue_comment event runs with secrets; '
                    . 'without the full gate any passerby directs a token-bearing paid run'
            );
        }
    }

    /**
     * Pin 3: the two shapes that would put attacker-controlled CODE next to
     * the base-context token. The safe design uses neither at all, so bare
     * presence is the accusation (fail-closed by omission, the doctrine
     * spelled in docs/CI.md).
     */
    public function testTheExampleNeverChecksOutHeadCodeOrTargetsThePullRequestEvent(): void
    {
        $raw = self::read();
        self::assertStringNotContainsString(
            'pull_request_target',
            $raw,
            'the example grew the target event — with secrets in scope, that is the classic exfiltration vector'
        );
        self::assertStringNotContainsString(
            'actions/checkout',
            $raw,
            'the example grew a checkout — the model must see the diff TEXT only; head code never lands where secrets are'
        );
    }

    /**
     * Pin 4: exactly one `pull-requests: write` configuration line in the
     * file (comment prose cannot match — the pattern anchors the line), it
     * sits UNDER the second job's key, and the read scopes sit above it. The
     * job that touches model text is the only one that can write, and nothing
     * else ever holds the write scope.
     */
    public function testTheWriteScopeIsSolitaryAndBelongsToThePostingJob(): void
    {
        $raw = self::read();
        $writeLine = '/^ +pull-requests: write$/m';
        self::assertSame(
            1,
            \preg_match_all($writeLine, $raw),
            'the example no longer holds pull-requests write in exactly one job — the two-job split is the point'
        );
        self::assertSame(1, \preg_match($writeLine, $raw, $write, PREG_OFFSET_CAPTURE));

        self::assertSame(1, \preg_match_all('/^ +pull-requests: read$/m', $raw), 'the run job must keep exactly one read scope');
        self::assertSame(1, \preg_match('/^ +pull-requests: read$/m', $raw, $read, PREG_OFFSET_CAPTURE));
        self::assertMatchesRegularExpression('/^ +contents: read$/m', $raw, 'the run job lost its contents read scope');
        self::assertMatchesRegularExpression('/^permissions: \{\}$/m', $raw, 'the workflow-level default scope is no longer empty');

        $postKey = \strpos($raw, "\n  post:\n");
        self::assertIsInt($postKey, 'the post job key is gone — the example is no longer the two-job shape');
        self::assertGreaterThan(
            $postKey,
            $write[0][1],
            'the sole write scope sits above the post job — the job that touches model text must not hold it'
        );
        self::assertLessThan(
            $postKey,
            $read[0][1],
            'the read scope no longer sits in the job above post — the run job lost its side of the split'
        );
    }

    /**
     * Pin 5: the documented leg runs the action in plan mode under a cost
     * ceiling, and the review value crosses through `env:` — the relocations
     * the negative arms presuppose.
     */
    public function testTheRunLegIsPlanModeWithACeilingAndTheValuesArriveThroughEnv(): void
    {
        $raw = self::read();
        self::assertStringContainsString('permission-mode: plan', $raw, 'the example bot no longer runs in plan mode');
        self::assertMatchesRegularExpression('/max-cost:\s*["\'][0-9.]+["\']/', $raw, 'the example bot lost its cost ceiling');
        self::assertMatchesRegularExpression(
            '/REVIEW:\s*\$\{\{\s*needs\.review\.outputs\.result\s*\}\}/',
            $raw,
            'the review no longer crosses into the post step through env: — the headline arm must be satisfied by relocation, not deletion'
        );

        $joined = \implode("\n", self::runBodies($raw));
        self::assertStringContainsString('"$PR_URL"', $joined, 'no run body reads the PR_URL env binding');
        self::assertStringContainsString('"$REVIEW"', $joined, 'no run body reads the REVIEW env binding');
        self::assertStringContainsString('"$RUNNER_TEMP', $joined, 'no run body reads the RUNNER_TEMP path binding');
    }

    /**
     * Pin 5b: every `uses:` ref is pinned — a full commit SHA — or the docs
     * placeholder the example carries until a caller swaps in their pin. A tag ref
     * here would let upstream retarget the paid run, which is the doctrine
     * action.yml's own header states for every third-party action.
     */
    public function testEveryUsesRefIsPinnedToAFullShaOrThePlaceholder(): void
    {
        $matched = \preg_match_all('/^(\s*)(?:-\s+)?uses:\s*(\S+)\s*$/m', self::read(), $refs);
        self::assertGreaterThanOrEqual(1, $matched, 'the example no longer invokes the action at all — this guard speaks for a dead file');
        foreach ($refs[2] as $ref) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z0-9._\-\/]+@(?:[0-9a-f]{40}|<[a-z\-]+>)$/',
                $ref,
                'unpinned action ref ' . $ref . ' — pin it to a full commit SHA (a tag is a moving ref a compromised upstream can retarget)'
            );
        }
    }
}
