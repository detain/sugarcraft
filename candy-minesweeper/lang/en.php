<?php

/**
 * English (default) translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small'           => 'board too small',
    'board.minecount_out_of_range' => 'mine count out of range',
    'board.rows_shape_mismatch' => 'grid data does not match the {width}×{height} board',
    'custom.min_rows'            => 'rows must be at least 2',
    'custom.min_cols'            => 'columns must be at least 2',
    'custom.max_rows'            => 'rows must be at most 50',
    'custom.max_cols'            => 'columns must be at most 50',
    'custom.min_mines'           => 'at least 1 mine required',
    'custom.max_mines'           => 'too many mines — leave a safe starting area',
    'ui.status' => 'mines: {mines}  ·  flags: {flags}  ·  remaining: {remaining}  ·  time: {time}',
    'ui.status_stats' => '{preset}  ·  flags: {flags}  ·  remaining: {remaining}  ·  time: {time}  ·  best: {best}',
    'ui.preset_easy' => 'easy',
    'ui.preset_standard' => 'standard',
    'ui.preset_medium' => 'medium',
    'ui.preset_expert' => 'expert',
    'ui.boom' => '💥 boom — press r to restart',
    'ui.cleared' => '★ cleared — press r to play again',
    'ui.help_rules' => 'a number counts nearby mines',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  move  ·  space / enter  reveal',
    'ui.help_board' => 'left-click  open · f / right-click  flag · c / middle-click  open around flags',
    'ui.help_session' => 'r  restart  ·  S  save  ·  L  load  ·  q / Esc / Ctrl+C  quit',
    'ui.saved' => 'game saved',
    'ui.restarted' => 'board restarted',
    'ui.loaded' => 'savegame loaded',
    'ui.no_save' => 'no savegame to load',
    'ui.load_refused' => 'savegame refused (unreadable or tampered)',
    'ui.save_failed' => 'save failed',
    'ui.chord_not_ready' => 'chord needs the flags to match the number',
    'ui.resume' => 'a savegame is waiting — press L to load it',
    'ui.new_best' => 'NEW BEST',
    'ui.beat_best' => '{prev} → NEW BEST',
    'ui.fit_notice' => 'candy-minesweeper needs a terminal {need} rows tall; this one has {have}. Enlarge the window — the game resumes the moment it fits. Press q to quit.',
];
