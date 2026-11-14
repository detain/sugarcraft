<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Support;

use SugarCraft\ScriptUi\Runtime\ColourGate;

/**
 * H1 (CI-watch 2026-11-13): the leg of the suite that drives commands
 * through `Application::run()` re-installs the colour gate from the REAL
 * environment on every call, so a test that asserts TrueColor-tier bytes
 * (`\x1b[38;2;…`) was depending on the developer's own COLORTERM=truecolor
 * export. CI runners export a bare (or absent) terminal family: the same
 * run resolves Ansi256/NoTty and the needle vanishes — a false red, but a
 * fair one: the test never said what terminal it was pretending to have.
 *
 * This seam scripts exactly that assumption: a TrueColor-eligible,
 * opt-out-free terminal, for the duration of the test only. Assertions
 * stay byte-exact; only the pretended environment moves into the test.
 */
trait TrueColorTerminalEnv
{
    /** Every variable {@see ColourGate::cap()} and core's ladder consult. */
    private const SCRIPTED_TERMS = [
        'TERM', 'COLORTERM', 'TERM_PROGRAM', 'WT_SESSION',
        'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR',
        'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE',
        ColourGate::NO_DECORATION_ENV,
    ];

    /** @var array<string,string|false> */
    private array $savedTerminalEnv = [];

    protected function scriptTrueColorTerminal(): void
    {
        foreach (self::SCRIPTED_TERMS as $name) {
            $this->savedTerminalEnv[$name] = getenv($name);
            putenv($name);
        }
        putenv('TERM=xterm-256color');
        putenv('COLORTERM=truecolor');
    }

    protected function restoreTerminalEnv(): void
    {
        foreach ($this->savedTerminalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedTerminalEnv = [];
    }
}
