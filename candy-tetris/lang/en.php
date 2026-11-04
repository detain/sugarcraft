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
    'help_card'    => "← →  move\n↑ x  rotate cw\nz    rotate ccw\n↓    soft drop\nspc  hard drop\nc    hold\np    pause\nr    restart\nq    quit",
    'next_label'   => 'next:',
    'hold_label'   => 'hold:',
    'paused'       => '[ paused — press p to resume ]',
    'over.title'       => 'GAME OVER',
    'over.final_score' => 'final score: {points}',
    'over.restart_hint' => 'press r to restart — press q to quit',

    // Versus renderer
    'label.player'   => 'PLAYER',
    'label.computer' => 'COMPUTER',
    'win.player'     => 'YOU WIN!',
    'win.computer'   => 'COMPUTER WINS!',
    'over.quit_hint' => 'press q to quit',

    // Launch screen
    'mode.unknown' => 'candy-tetris: unknown mode "{flag}"',
    'help_text'    => "candy-tetris - Tetris clone\n\n"
        . "Usage: candy-tetris [options]\n"
        . "  (no args)    Start single player game\n"
        . "  -v, --vs     Start VS Computer mode\n"
        . "  -h, --help   Show this help\n"
        . "  --version    Print the version\n",
];
