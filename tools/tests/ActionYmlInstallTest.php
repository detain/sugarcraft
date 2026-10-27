<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped `candy-code/action.yml` on the two things that, once
 * broken, break it silently at other people's CI: the install leg and the
 * script-injection door.
 *
 * WHY THE INSTALL LEG IS PINNED WORD-FOR-WORD. The action used to install with
 * `composer global require`, and that could never resolve: the global
 * `composer.json` is a `stable` root, `sugarcraft/candy-code` requires its
 * siblings as `sugarcraft/*: "@dev"`, stability flags in a NON-root manifest
 * are ignored by composer, and Packagist carries no stable sibling releases —
 * so every install attempt died on resolution. The fix is root semantics:
 * `composer create-project` makes the package's own `minimum-stability: dev`
 * manifest the root. A guard is owed because the broken spelling is one-line
 * away, reads plausible, and sits in a file no PHP suite loads — exactly how
 * the original shipped.
 *
 * WHY THE INTERPOLATION ARM IS PINNED HERE AND NOT ONLY IN THE HEADER
 * COMMENT. "Never `${{ inputs.* }}` inside a `run:` body" is the whole
 * supply-chain doctrine of this action (the header comment of action.yml says
 * so itself). Comments do not fail builds. This does, and it is the only
 * executable check between a future editor's convenience and the classic
 * Actions script-injection bug on an action whose payload is attacker-grade
 * text.
 *
 * THE EXTRACTION IS THE TEST'S LOAD-BEARING PART: it scans RUN BODIES ONLY,
 * because `${{ inputs.* }}` is legal and used on `env:` lines by design. A
 * whole-file substring check would be a guard that accuses the correct file.
 *
 * Deliberately standalone (`--no-configuration` from the repo root, per
 * CONTRIBUTING.md): it reads YAML as text, needs no lib autoloader, and joins
 * the `tools-guards` job of ci.yml, not the candy-code suite.
 */
final class ActionYmlInstallTest extends TestCase
{
    /** The file this guard speaks for, relative to the monorepo root. */
    private const ACTION_FILE = 'candy-code/action.yml';

    /**
     * The exact install shape this action must never carry again, substring
     * as shell text (not a regex — the space in it is part of the point).
     */
    private const FORBIDDEN_INSTALL = 'composer global require';

    /** The root-semantics install it must carry instead. */
    private const REQUIRED_INSTALL = 'composer create-project';

    /**
     * Every `run:` body in the action, in file order, newlines intact.
     *
     * Minimal YAML by design: this file needs "for each run key, the script
     * text that belongs to it", nothing a YAML lib would do better, and a
     * bare `tools/` guard carries no composer dependency. Handles the two
     * spellings GitHub accepts — `run: |` / `run: >` block scalars whose
     * content is indented deeper than the `run:` line, and one-line
     * `run: cmd` — with or without the sequence dash (`- run:`). A comment
     * line mentioning `run:` cannot match: keys are anchored at line start.
     *
     * @return list<string>
     */
    private static function runBodies(): array
    {
        $path = \dirname(__DIR__, 2) . '/' . self::ACTION_FILE;
        $raw = \file_get_contents($path);
        self::assertIsString($raw, self::ACTION_FILE . ' is unreadable, so this guard speaks for nothing');

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

    /**
     * Anti-vacuity floor: the parser must actually find the steps. Three run
     * bodies is fewer than the file has today, and any real edit that makes
     * the extraction return nothing reddens here, not everywhere silently.
     */
    public function testTheRunBodiesAreActuallyExtracted(): void
    {
        $bodies = self::runBodies();

        self::assertGreaterThanOrEqual(3, \count($bodies), 'the run: parser found too few bodies — a vacuous pass here would acquit the whole file');
        foreach ($bodies as $index => $body) {
            self::assertNotSame('', \trim($body), "run body #$index is empty — the parser mis-sliced the file");
        }
    }

    public function testNoRunBodyInstallsWithComposerGlobalRequire(): void
    {
        // WHY THE ACTION'S OWN COMMENT IN THAT STEP AVOIDS THE EXACT SPELLING:
        // this arm scans run bodies as shell text, and a bash comment that
        // says the forbidden words verbatim is accused with everything else.
        // (It earned that sentence by being true at writing time.)
        foreach (self::runBodies() as $index => $body) {
            self::assertStringNotContainsString(
                self::FORBIDDEN_INSTALL,
                $body,
                "run body #$index installs with \"" . self::FORBIDDEN_INSTALL . '" — it cannot resolve (the global root is stable and the sugarcraft/* siblings are dev-only); use ' . self::REQUIRED_INSTALL
            );
        }
    }

    /**
     * Positive anchor beside the negative one: deleting the broken line
     * without replacing it with the working shape would keep arm #2 green and
     * leave the action with nothing to install. Pins the create-project call,
     * its RUNNER_TEMP target, the constraint arriving through the
     * CANDY_CODE_CONSTRAINT env (never interpolated), and the PATH handoff.
     */
    public function testTheInstallLegUsesCreateProjectRootSemantics(): void
    {
        $install = null;
        foreach (self::runBodies() as $body) {
            if (\str_contains($body, self::REQUIRED_INSTALL)) {
                $install = $body;
                break;
            }
        }

        self::assertNotNull($install, 'no run body installs with ' . self::REQUIRED_INSTALL . ' — the action would have no resolvable install leg');
        self::assertStringContainsString('sugarcraft/candy-code:', $install, 'the create-project call must constrain the package explicitly');
        self::assertStringContainsString('${CANDY_CODE_CONSTRAINT}', $install, 'the version constraint must arrive through the CANDY_CODE_CONSTRAINT env, the only door inputs cross into the shell');
        self::assertStringContainsString('$RUNNER_TEMP/candy-code', $install, 'the install target must be under $RUNNER_TEMP');
        self::assertStringContainsString('$GITHUB_PATH', $install, 'the installed bin dir must be appended to $GITHUB_PATH for later steps');
    }

    public function testNoRunBodyInterpolatesInputsIntoScriptText(): void
    {
        foreach (self::runBodies() as $index => $body) {
            self::assertDoesNotMatchRegularExpression(
                '/\$\{\{\s*inputs\./',
                $body,
                'run body #' . $index . ' interpolates ${{ inputs.* }} into the script — script-injection door open; inputs cross only through env:'
            );
        }
    }
}
