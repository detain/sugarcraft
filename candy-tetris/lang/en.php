<?php

/**
 * English (default) UI strings for candy-tetris.
 *
 * Values are byte-identical to the literals they replaced — the renderers
 * and the launch screen pin their frames against these.
 *
 * Placeholder law (candy-tetris-030): multi-argument frame text uses NAMED
 * {tokens} interpolated by Lang::t, never positional %d — a positional
 * triplet lets a locale reorder its labels while the call site still feeds
 * (points, lines, level), printing the score under "lines" with every
 * structural test green. Only single-value counters (combo_line) may keep
 * sprintf %d; LocaleParityTest fails the shape if that ceiling is crossed.
 *
 * Bootstrap exemption (candy-tetris-028): the extension preflight and the
 * missing-autoload refusal in bin/candy-tetris stay English literals on
 * purpose — they run before the catalogue (or the vendor tree) can load,
 * so no Lang::t seat exists for them.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card'   => "score:  {score}\nlines:  {lines}\nlevel:  {level}",
    'help_card'    => "move\nrotate cw\nrotate ccw\nsoft drop\nhard drop\nhold\npause\nrestart (over)\nquit",
    'next_label'   => 'next:',
    'hold_label'   => 'hold:',
    'paused'       => '[ paused — press p to resume ]',
    'over.title'       => 'GAME OVER',
    'over.final_score' => 'final score: {points}',
    'over.restart_hint' => 'press r to restart — press q to quit',
    // MA-10: the game-over banner names WHY the run ended — the fatal
    // piece sits in the hidden rows, so the banner is the only evidence.
    'over.reason'               => 'reason: {reason}',
    'over.reason.spawn_blocked' => 'no room for next piece',
    'over.reason.top_out'       => 'stack reached the ceiling',
    'over.reason.crushed'       => 'crushed by garbage',
    // m35/MA-12: on-frame text cue for an exhausted hold (the dim SGR alone
    // was a no-op where style does not land).
    'hold_used'                 => '(used)',
    // MA-13: earned-moment badges for the score card — the combo chain and
    // the back-to-back arm now surface on frame (they used to live only in
    // the score math). 'lastAward' tokens ('T-SPIN', 'TETRIS', …) are
    // gameplay vocabulary rendered verbatim, like the score numbers.
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B armed',
    'over.best'    => 'best: {points}',
    'over.new_best' => 'new best!',

    // Versus renderer
    'label.player'   => 'PLAYER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'YOU WIN!',
    'win.computer'   => 'COMPUTER WINS!',
    // MA-9: the VS match strip names the bindings the old frame never showed.
    'vs_keys'        => 'hard
hold
pause
restart
quit',
    'vs.record' => 'record: {wins} won, {losses} lost',
    'intro.title' => 'welcome to candy-tetris',
    'intro.goal' => 'clear lines — do not reach the top',
    'intro.press' => 'press {keys} to play',
    'intro.vs' => 'versus mode: candy-tetris -v',
    // The intro names its keys like the keycaps read them (candy-tetris-053:
    // "press spc ent to play" read as one impossible chord); {or} is the
    // locale's own disjunction so the sentence stays native everywhere.
    'key_or' => ' or ',

    // Launch screen and CLI diagnostics (candy-tetris-028: this whole
    // surface localizes now — the first text a user reads is not an
    // English-only zone). Key ORDER is parity-pinned: this tail is
    // mode.unknown, help_text, cli.no_tty, then the diagnostic block, in
    // that order in all sixteen catalogues.
    'mode.unknown' => 'candy-tetris: unknown mode "{flag}"',
    'help_text'    => "candy-tetris - Tetris clone\n\n"
        . "Usage: candy-tetris [options]\n"
        . "  (no args)    Start single player game\n"
        . "  -v, --vs     Start VS Computer mode\n"
        . "  -h, --help   Show this help\n"
        . "  --version    Print the version\n",
    'cli.no_tty' => 'candy-tetris needs an interactive terminal: run it from a TTY (not a pipe or redirect) with TERM set to something other than dumb.',
    // One key for the surplus-flags refusal (candy-tetris-033): {count}
    // sits after a plural-safe preposition in every locale, so the
    // English-only 'argument%s' 's' append is gone.
    'launch.extras' => 'candy-tetris: only one flag is accepted, but {count} extra: {list}',
    'score.save.failed' => 'candy-tetris: could not save scores ({problem})',
    'keys.ignored' => 'candy-tetris: ignoring unreadable keys file ({problem})',
    'scores.ignored' => 'candy-tetris: ignoring unreadable scores file ({problem})',
    'config.problem' => 'candy-tetris: {detail} in {path}',
    'cli.refusal' => "candy-tetris needs an interactive terminal (a TTY).\nIt plays without colour: set NO_COLOR.",
    'cli.too_small' => "candy-tetris needs a {size} terminal; this one is {actual}.\nResize the window and the game resumes right here.",
    'cli.too_small_vs' => "candy-tetris VS needs a {size} terminal; this one is {actual}.\nResize the window and the match resumes right here.",
];
