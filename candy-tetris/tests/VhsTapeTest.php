<?php

/**
 * candy-tetris/tests/VhsTapeTest.php
 *
 * The tapes are the README's flagship visuals (GIF law) and nothing else
 * reads them (candy-tetris-038: the MA-24 first-run card shipped and every
 * solo tape kept scripting against the old direct-into-play screen — the
 * re-recorded frames silently swallowed the opening keys). These guards pin
 * what a render can only show too late:
 *
 *   - every tape names the work directory its own relative paths need
 *     (candy-tetris-069: the old header line implied the repo root, where
 *     `Output .vhs/<name>.gif` would write beside the monorepo root);
 *   - every solo tape crosses the first-run card with an explicit press
 *     BEFORE any gameplay key — VS has no gate, so the vs-* tapes must
 *     NOT grow a stale one;
 *   - the binary the tapes type still answers that form (a dead flag must
 *     not ride into the next render).
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\LaunchMode;

final class VhsTapeTest extends TestCase
{
    /** @return array<string,string> tape basename => body */
    private static function tapes(): array
    {
        $bodies = [];
        foreach ((array) glob(__DIR__ . '/../.vhs/*.tape') as $file) {
            $body = file_get_contents((string) $file);
            self::assertIsString($body, (string) $file . ' must be readable');
            $bodies[basename((string) $file)] = $body;
        }

        self::assertNotEmpty($bodies, 'the shipped .vhs/*.tape files must exist');

        return $bodies;
    }

    public function testEveryTapePinsItsRenderDirectoryAndOutputSlot(): void
    {
        foreach (self::tapes() as $name => $body) {
            $stem = substr($name, 0, -strlen('.tape'));
            self::assertStringContainsString(
                "# Render with (from the candy-tetris directory): vhs .vhs/{$name}",
                $body,
                "{$name} must name the work directory its relative paths run from (candy-tetris-069)"
            );
            self::assertStringNotContainsString(
                'vhs candy-tetris/.vhs/',
                $body,
                "{$name} must not imply a repo-root render: the Output path below it is lib-root-relative"
            );
            self::assertStringContainsString(
                "Output .vhs/{$stem}.gif",
                $body,
                "{$name} must write its gif beside the tape, matching the pinned directory"
            );
        }
    }

    public function testSoloTapesCrossTheFirstRunCardBeforeGameplay(): void
    {
        $soloTapes = 0;
        foreach (self::tapes() as $name => $body) {
            if (str_starts_with($name, 'vs-')) {
                // VS mode has no on-ramp gate — a Space here would just be
                // a hard drop, so a taped "gate" in the VS demos is a bug.
                self::assertStringNotContainsString('first-run card', $body);

                continue;
            }
            $soloTapes++;
            $cardPos = strpos($body, 'candy-tetris-038');
            self::assertIsInt($cardPos, "{$name} must name the first-run cure it demonstrates");

            $gatePos = strpos($body, "\nSpace\n", (int) $cardPos);
            self::assertIsInt($gatePos, "{$name} must press through the card after naming it");

            foreach (['Left', 'Right', 'Up', 'Down', 'Type "c"', 'Type "q"'] as $input) {
                $movePos = strpos($body, $input);
                if ($movePos === false) {
                    continue;
                }

                self::assertGreaterThan(
                    $gatePos,
                    $movePos,
                    "{$name} scripts {$input} before the card press — the gate would swallow it"
                );
            }
        }

        self::assertSame(2, $soloTapes, 'solo demo count moved — revisit the card-crossing pins');
    }

    public function testEveryTapeBootsThroughAFlagTheLauncherStillAnswers(): void
    {
        $launchers = 0;
        foreach (self::tapes() as $name => $body) {
            self::assertSame(
                1,
                preg_match_all('~Type "\./bin/candy-tetris( -[a-z]+| --[a-z-]+)?"~', $body, $forms),
                "{$name} must boot the shipped binary exactly once"
            );
            foreach ($forms[1] as $flag) {
                $launchers++;
                $argv = $flag === '' ? ['candy-tetris'] : ['candy-tetris', trim($flag)];
                self::assertSame(
                    trim($flag) === '' ? LaunchMode::SINGLE : LaunchMode::VS,
                    LaunchMode::resolve($argv),
                    "{$name} types '{$flag}', which the argv contract no longer answers"
                );
            }
        }

        self::assertSame(4, $launchers, 'tape boot count moved — revisit the flag pins'
            . ' (the -h form belongs in the docs, not on a tape)');
    }
}
