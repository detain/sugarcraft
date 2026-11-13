<?php

/**
 * English (default) translations for candy-minesweeper.
 *
 * Value style (finding -078): this file deliberately keeps toast and banner
 * copy lowercase — capitalisation follows target-language convention, so
 * locales that sentence-case their UI copy do so in their own files; the
 * rule is per-locale consistency, not cross-locale case uniformity.
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
    'custom.min_area'            => 'a custom board needs at least 10 cells (rows × cols)',
    'custom.label' => '{width}×{height} · {mines} mines',
    'ui.status' => 'mines: {mines}  ·  flags: {flags}  ·  remaining: {remaining}  ·  time: {time}',
    'ui.status_stats' => '{preset}  ·  flags: {flags}  ·  remaining: {remaining}  ·  time: {time}  ·  best: {best}',
    'ui.preset_easy' => 'easy',
    'ui.preset_standard' => 'standard',
    'ui.preset_medium' => 'medium',
    'ui.preset_expert' => 'expert',
    'ui.boom' => '💥 boom — press r to restart · n new game',
    'ui.cleared' => '★ cleared — press r to play again · n new game',
    'ui.help_rules' => 'a number counts nearby mines · flag suspected mines · reveal safe cells to win',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  move  ·  space / enter  reveal',
    'ui.help_board' => 'left-click  reveal · f / right-click  flag · c / middle-click  reveal around',
    'ui.help_loss' => '{mark} wrong flag · * missed mine',
    'ui.help_session' => 'r  restart  ·  S  save  ·  L  load  ·  q / Esc / Ctrl+C  quit',
    'ui.saved' => 'game saved',
    'ui.restarted' => 'board restarted',
    'ui.loaded' => 'save loaded',
    'ui.no_save' => 'no save to load',
    'ui.load_refused' => 'save refused (unreadable or tampered)',
    'ui.save_failed' => 'save failed',
    'ui.chord_not_ready' => 'chord needs the flags to match the number',
    'ui.confirm_quit' => 'press q again to quit — this game is unsaved',
    'ui.resume' => 'a save is waiting — press L to load it',
    'ui.new_best' => 'NEW BEST',
    'ui.beat_best' => '{prev} → NEW BEST',
    'ui.picker_title' => 'choose a board',
    'ui.picker_custom' => 'custom',
    'ui.picker_hint' => 'press 1-4 to start · c for a custom shape · q / Esc back',
    'ui.form_width' => 'width',
    'ui.form_height' => 'height',
    'ui.form_mines' => 'mines',
    'ui.form_hint' => 'type digits · enter for next · enter at mines starts · Esc back',
    'ui.fit_notice' => 'candy-minesweeper needs a terminal {need} rows tall; this one has {have}. Enlarge the window — the game resumes the moment it fits. Press q to quit.',
];
