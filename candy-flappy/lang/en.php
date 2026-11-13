<?php

/**
 * English (default) translations for candy-flappy.
 *
 * flappy-05 contract: every value here is BYTE-IDENTICAL to the literal it
 * replaced in src/Renderer.php — the three fixtures/ goldens are the proof
 * (they must stay green across the wiring commit). Leading spaces are part
 * of the assembled status row; padding that the renderer joins explicitly
 * (the '   ' record tails) stays call-site-side and is NOT baked in here.
 *
 * Value style mirrors candy-minesweeper: this file keeps the hint copy
 * lowercase where the game wrote it lowercase; capitalisation follows
 * target-language convention in each locale file.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'score: {score}',
    'ui.best'                => ' best: {best}',
    'ui.new_best'            => ' 🏆 NEW HIGH SCORE: {best}',
    'ui.plain_new_best'      => ' NEW HIGH SCORE: {best}',
    'ui.crash_full'          => '💥 splat — press r to try again, q to quit',
    'ui.crash_short'         => '💥 splat — r retry · q quit',
    'ui.ready_full'          => 'press space/⏎/↑/w to start · r restart · q quit',
    'ui.ready_short'         => 'space to start · q quit',
    'ui.live_full'           => 'space/⏎/↑/w flap · r restart · q quit',
    'ui.live_short'          => 'space flap · q quit',
    'ui.plain_crash_full'    => 'x splat - press r to try again, q to quit',
    'ui.plain_crash_short'   => 'x splat - r retry - q quit',
    'ui.plain_ready_full'    => 'press space/Enter/^/w to start . r retry . q quit',
    'ui.plain_ready_short'   => 'space to start . q quit',
    'ui.plain_live_full'     => 'space/Enter/^/w flap . r restart . q quit',
    'ui.plain_live_short'    => 'space flap . q quit',
    'notty.line1'            => 'candy-flappy needs an interactive terminal.',
    'notty.line2'            => 'No usable colour capability was detected (non-TTY stdout or TERM=dumb).',
    'size.notice'            => 'candy-flappy needs a {need} terminal; this one is {have}.',
    'size.resume'            => 'Resize the window and the game resumes right here.',
];
