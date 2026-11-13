<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\DetectionChain;
use SugarCraft\Core\Util\ColorProfile as CoreColorProfile;

/**
 * Cross-ladder drift pin for the two color-detection forks (finding -024).
 *
 * KNOWN divergence lives here — this file DOCUMENTS it, it does NOT bless it.
 * sugar-core's Util\ColorProfile and this library's DetectionChain answer the
 * same environment table differently by design-of-the-fork, and the
 * reconciliation is an owner SSOT ruling (README :399-414: "the forks answer
 * the same environment differently"; CALIBER [probe-ssot]: "Probe::colorProfile()
 * + ColorProfile enum is the SSOT ... Do not re-implement detection logic in
 * consumers" — while sugar-style and candy-top in fact resolve through the
 * Core fork, which is the sentence the README row above states). Both doc rows
 * are reproduced here byte-exact (NO_COLOR: core `Ascii` repaints the
 * black/white pair while this ladder's CP-05 `Ascii` drops color codes and
 * keeps decoration — names converged, semantics did not; FORCE_COLOR=1: core
 * TrueColor vs chain Ansi), plus the parity rows the forks
 * DO share, so the pin has shape, not just flips.
 *
 * What reddens this file: ANY silent move of either ladder's answer to any
 * row — a fork "fixed" under one consumer's feet, or a rename of the enum
 * cases this pin names as literals. Neither is allowed to happen quietly;
 * each must arrive as a deliberate, disclosed change to BOTH this table and
 * the README divergence prose. The no-composer/never-touch-other-seats lane
 * laws mean the acceptance mutation (flipping a Core answer in a scratch
 * copy) is the risk-the-shared-tree case the step itself authorizes skipping
 * — sugar-core carries live lanes; the literals above are the honest
 * substitute: computed from neither side, typed from the measured source.
 *
 * Determinism: every env key either ladder reads is passed explicitly (the
 * chain merges ambient only for missing names; the Core fork reads ONLY the
 * array when one is given), and the Core TTY leg is skipped by its documented
 * $stdout=null contract — nothing here depends on the runner's terminal.
 */
final class CoreForkDivergencePinTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string,string>, 1: string, 2: string}>
     */
    public static function divergenceProvider(): array
    {
        $keys = [
            'TERM', 'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'COLORTERM',
            'TERM_PROGRAM', 'TMUX', 'TMUX_PANE', 'STY', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL',
            'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'KONSOLE_VERSION',
        ];

        $row = static function (array $over) use ($keys): array {
            return array_merge(array_fill_keys($keys, ''), $over);
        };

        return [
            // label => [env, chain answer (this library), core answer (sugar-core)]
            // empty TERM: names CONVERGED on Ascii since CP-10 floored the
            // unknown/empty default — but the rungs still mean different
            // things (ours drops color SGR, core repaints the black/white
            // pair), so this row stays in the table as a same-name divergence.
            'empty' => [$row([]), 'Ascii', 'Ascii'],
            'TERM=dumb' => [$row(['TERM' => 'dumb']), 'Ascii', 'NoTty'],
            'NO_COLOR=1' => [$row(['NO_COLOR' => '1']), 'Ascii', 'Ascii'],
            'NO_COLOR=1+TERM=dumb' => [$row(['NO_COLOR' => '1', 'TERM' => 'dumb']), 'Ascii', 'NoTty'],
            'FORCE_COLOR=1' => [$row(['FORCE_COLOR' => '1']), 'Ansi', 'TrueColor'],
            'FORCE_COLOR=2' => [$row(['FORCE_COLOR' => '2']), 'Ansi256', 'TrueColor'],
            'FORCE_COLOR=yes' => [$row(['FORCE_COLOR' => 'yes']), 'TrueColor', 'TrueColor'],
            'CLICOLOR=0' => [$row(['CLICOLOR' => '0']), 'Ascii', 'Ascii'],
            'COLORTERM+dumb' => [$row(['TERM' => 'dumb', 'COLORTERM' => 'truecolor']), 'Ascii', 'NoTty'],
            'TERM=xterm-256color' => [$row(['TERM' => 'xterm-256color']), 'Ansi256', 'Ansi256'],
            'TERM_PROGRAM=iTerm' => [$row(['TERM_PROGRAM' => 'iTerm.app']), 'TrueColor', 'TrueColor'],
            'CLICOLOR_FORCE=yes+dumb' => [$row(['CLICOLOR_FORCE' => 'yes', 'TERM' => 'dumb']), 'TrueColor', 'TrueColor'],
            // CI=true: this library's ladder never reads CI (env only) — with
            // TERM unset CP-10 floors it Ascii, while the core fork honors its
            // documented CI leg and stays Ansi. Divergence surfaced by CP-10.
            'CI=true' => [$row(['CI' => 'true']), 'Ascii', 'Ansi'],
            'GOOGLE_CLOUD_SHELL' => [$row(['GOOGLE_CLOUD_SHELL' => 'true', 'TERM' => 'xterm']), 'TrueColor', 'Ansi'],
        ];
    }

    /**
     * @param array<string,string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('divergenceProvider')]
    public function testBothForksAnswerExactlyAsDocumented(array $env, string $chain, string $core): void
    {
        $this->assertSame(
            $chain,
            DetectionChain::detect($env)->toColorProfile()->name,
            'this library\'s ladder drifted from its documented answer',
        );
        $this->assertSame(
            $core,
            CoreColorProfile::detect($env, null)->name,
            'the sugar-core fork drifted from its documented answer',
        );
    }
}
