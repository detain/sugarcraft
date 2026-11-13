<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\ColorProfile\DetectionChain;

/**
 * The 14-step ladder is hand-copied in two places — the README's numbered
 * list and the DetectionChain class docblock — and until CP-31 nothing
 * guarded the genre (the README itself admitted it). This test re-derives
 * both lists from source and fails, naming the side that moved, whenever a
 * step's ENVocabulary (the env vars / TERM families it keys on) or its
 * ANSWER vocabulary (which color-depth rungs it can settle on) diverges.
 *
 * Prose may differ between the twins — the guard pins meaning, not commas:
 * per step it compares the set of env-var names mentioned and the set of
 * detection answer levels reachable. Adding a step to only one twin,
 * retargeting a rung (an ascii→ansi flip on one side), or renaming a leg's
 * variable in one copy all redden this test with a "step N (side)" diff.
 *
 * Mirrors the candy-code ReadmeRosterDriftTest SHAPE (re-derive the doc from
 * the source of truth, assert, name the drift) — not its wiring: this lib
 * has no generator, the two twins are each other's cross-check.
 */
final class LadderDriftTest extends TestCase
{
    /** Answer-level vocabulary shared by both twins (chain-level spellings). */
    private const ANSWER_LEVELS = ['truecolor', 'ansi256', 'ascii', 'notty', 'ansi'];

    /** Env/rung keys the ladder steps are allowed to name. */
    private const ENV_KEYS = [
        'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'COLORTERM',
        'TERM_PROGRAM', 'GOOGLE_CLOUD_SHELL', 'WT_SESSION', 'TMUX', 'STY', 'TERM',
    ];

    public function testReadmeAndDocblockCarryTheSameNumberOfSteps(): void
    {
        $readme = self::parseLadder(self::readmeLadderText(), 'README');
        $doc = self::parseLadder(self::docblockLadderText(), 'docblock');

        self::assertSame(14, count($readme), 'the README ladder lost or gained a step versus the pinned 14');
        self::assertSame(
            count($doc),
            count($readme),
            'step-count drift: docblock has ' . count($doc) . ' steps, README has ' . count($readme)
                . ' — a ladder change must edit both twins (CP-31)',
        );
    }

    public function testEachStepTwinsAgreeOnEnvAndAnswerVocabulary(): void
    {
        $readme = self::parseLadder(self::readmeLadderText(), 'README');
        $doc = self::parseLadder(self::docblockLadderText(), 'docblock');
        $n = min(count($readme), count($doc));

        for ($i = 0; $i < $n; $i++) {
            self::assertSame(
                $readme[$i]['env'],
                $doc[$i]['env'],
                sprintf(
                    'step %d env-vocabulary drift (CP-31): README names {%s}, the DetectionChain docblock names {%s}',
                    $i + 1,
                    implode(',', $readme[$i]['env']),
                    implode(',', $doc[$i]['env']),
                ),
            );
            self::assertSame(
                $readme[$i]['answers'],
                $doc[$i]['answers'],
                sprintf(
                    'step %d answer-vocabulary drift (CP-31): README answers {%s}, the docblock answers {%s}',
                    $i + 1,
                    implode(',', $readme[$i]['answers']),
                    implode(',', $doc[$i]['answers']),
                ),
            );
        }
    }

    /** @return list<array{env: list<string>, answers: list<string>}> */
    private static function parseLadder(string $text, string $side): array
    {
        $steps = [];
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $bare = strtolower(trim($line, " \t#*/-"));
            if ($bare === '') {
                // A truly blank line closes the list: the README's post-ladder
                // prose must not merge into step 14. Docblock padding (' *')
                // trims to '' too — but between step 1 and step 14 the chain
                // docblock carries no blank lines, and the README prose after
                // the list is exactly what this guard must stop at.
                $current = null;

                continue;
            }

            if (preg_match('/^(\d+)\.\s/', $bare, $m) === 1) {
                $current = (int) $m[1];
                self::assertGreaterThan(count($steps), $current, "{$side} ladder numbering must ascend without repeats");
                self::assertSame(count($steps) + 1, $current, "{$side} ladder numbering has a gap at step {$current}");
                $steps[] = substr($bare, strlen($m[0]));

                continue;
            }
            if ($current !== null) {
                $steps[count($steps) - 1] .= ' ' . $bare;
            }
        }

        return array_map(static function (string $step): array {
            // Parenthetical prose is commentary, not contract: one twin may
            // annotate a rung ("the floor answer is already ansi") while the
            // other phrases it elsewhere. The guard compares each step's
            // load-bearing vocabulary — env keys and answer rungs outside
            // parentheses — so a retarget or leg-rename on one side only
            // reddens; the CP-31 acceptance mutation proves the trip.
            $step = preg_replace('/\([^)]*\)/', ' ', $step) ?? $step;
            $up = strtoupper($step);
            $env = array_values(array_filter(
                self::ENV_KEYS,
                static fn (string $k): bool => preg_match('/\b' . $k . '\b/', $up) === 1,
            ));
            sort($env);
            $lower = strtolower($step);
            $answers = array_values(array_filter(
                self::ANSWER_LEVELS,
                // Boundaries per token: ansi must never shadow ansi256.
                static fn (string $a): bool => preg_match('/(?<!\w)' . $a . '(?!\w)/', $lower) === 1,
            ));
            sort($answers);

            return ['env' => $env, 'answers' => $answers];
        }, $steps);
    }

    private static function readmeLadderText(): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertMatchesRegularExpression('/^## The detection ladder$/m', $readme, 'README lost its ladder section');

        $start = (int) strpos($readme, '## The detection ladder');
        $body = substr($readme, $start);
        $end = (int) strlen($body);
        $heading = strpos($body, "\n### Step 0");
        if ($heading !== false) {
            $end = min($end, $heading);
        }
        $next2 = strpos($body, "\n## ");
        if ($next2 !== false) {
            $end = min($end, $next2);
        }

        return substr($body, 0, $end);
    }

    private static function docblockLadderText(): string
    {
        $doc = (new ReflectionClass(DetectionChain::class))->getDocComment();
        self::assertIsString($doc, 'DetectionChain lost its class docblock');

        return $doc;
    }
}
