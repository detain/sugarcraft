<?php

/**
 * English (default) UI strings for candy-tetris.
 *
 * Values are byte-identical to the literals they replaced — the renderers
 * and the launch screen pin their frames against these.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card'   => "score:  %d\nlines:  %d\nlevel:  %d",
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
    'over.quit_hint' => 'press q to quit',
    // MA-9: the VS match strip names the bindings the old frame never showed.
    'vs_keys'        => 'hard
hold
pause
restart
quit',
    'vs.record' => 'record: {wins} won, {losses} lost',
    'intro.title' => 'welcome to candy-tetris',
    'intro.goal' => 'clear lines - do not reach the top',
    'intro.press' => 'press {keys} to play',
    'intro.vs' => 'versus mode: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: unknown mode "{flag}"',
    'help_text'    => "candy-tetris - Tetris clone\n\n"
        . "Usage: candy-tetris [options]\n"
        . "  (no args)    Start single player game\n"
        . "  -v, --vs     Start VS Computer mode\n"
        . "  -h, --help   Show this help\n"
        . "  --version    Print the version\n",
];
