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
    'ui.new_best'            => ' 🏆 NEW BEST: {best}',
    'ui.plain_new_best'      => ' NEW BEST: {best}',
    'ui.crash_full'          => '💥 {why} — press r to restart · q to quit',
    'ui.crash_short'         => '💥 {why} — r restart · q quit',
    'ui.crash_floor'         => 'r restart · q quit',
    'ui.ready_full'          => 'press space/⏎/↑/w to start · q quit',
    'ui.ready_short'         => 'space to start · q quit',
    'ui.live_full'           => 'space/⏎/↑/w flap · r restart · q quit',
    'ui.live_short'          => 'space flap · q quit',
    'ui.plain_crash_full'    => 'x {why} - press r to restart - q to quit',
    'ui.plain_crash_short'   => 'x {why} - r restart - q quit',
    'ui.plain_crash_floor'   => 'r restart - q quit',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'hit the ceiling',
    'ui.why_ground'           => 'hit the ground',
    'ui.why_pipe'             => 'hit a pipe',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'hit the ceiling',
    'ui.plain_why_ground'     => 'hit the ground',
    'ui.plain_why_pipe'       => 'hit a pipe',
    'ui.plain_ready_full'    => 'press space/Enter/up/w to start . q quit',
    'ui.plain_ready_short'   => 'space to start . q quit',
    'ui.plain_live_full'     => 'space/Enter/up/w flap . r restart . q quit',
    'ui.plain_live_short'    => 'space flap . q quit',
    'ui.quit_hint' => 'press q to quit.',
    'ui.quarantine'           => '⚠ previous leaderboard unreadable — archived as {file}',
    'ui.plain_quarantine'     => 'x previous leaderboard unreadable - archived as {file}',
    'notty.line1'            => 'candy-flappy needs a terminal that can render its output.',
    'notty.line2'            => 'stdout is not an interactive terminal, or TERM=dumb was detected.',
    'size.notice'            => 'candy-flappy needs a {need} terminal; this one is {have}.',
    'size.resume'            => 'Resize the window and the game resumes right here.',
];
