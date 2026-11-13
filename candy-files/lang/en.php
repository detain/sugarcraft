<?php

/**
 * English (default) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Confirmation prompts
    'confirm.delete'     => 'delete {names}? (y/n)',
    'confirm.delete_permanent' => 'delete {names}? PERMANENT (y/n)',
    'confirm.copy'       => 'copy {names} to {dest}? (y/n)',
    'confirm.move'      => 'move {names} to {dest}? (y/n)',
    'confirm.rename'     => "rename '{name}' to: ",
    'prompt.mkdir'   => 'create directory: ',
    'confirm.selected_entries' => '{count} selected entries',
    'confirm.affirmative' => 'y',

    // Status messages
    'status.nothing_to_delete' => 'nothing to delete',
    'status.nothing_to_copy'   => 'nothing to copy',
    'status.nothing_to_move'   => 'nothing to move',
    'status.nothing_to_rename' => 'nothing to rename',
    'status.cancelled'        => 'cancelled',
    'status.refreshed'        => 'refreshed',
    'status.copy_pending'     => 'copying {count} entries…',
    'status.deleted'           => 'deleted {count} entries',
    'status.deleted_with_errors' => 'deleted {count} entries with {errors} errors',
    'status.deleted_skipped'   => 'deleted {count} entries ({skipped} skipped)',
    'status.deleted_permanent' => 'deleted {count} entries ({permanent} permanent)',
    'status.copied'           => 'copied {count} entries',
    'status.copied_with_errors' => 'copied {count} entries with {errors} errors',
    'status.copied_skipped'   => 'copied {count} entries ({skipped} skipped)',
    'status.copied_overwrote' => 'copied {count} entries ({overwrote} overwrote)',
    'status.moved'            => 'moved {count} entries',
    'status.created'       => 'created {count} folder(s)',
    'status.mkdir_failed'    => 'cannot create {name}',
    'status.moved_with_errors' => 'moved {count} entries with {errors} errors',
    'status.moved_skipped'    => 'moved {count} entries ({skipped} skipped)',
    'status.moved_overwrote'    => 'moved {count} entries ({overwrote} overwrote)',
    'status.renamed'          => 'renamed {old} to {new}',
    'status.renamed_overwrote'  => 'renamed {old} to {new} ({overwrote} overwrote)',
    'status.rename_failed'    => 'rename failed: {name}',
    'status.nothing_to_undo'    => 'nothing to undo',
    'status.undone'            => 'undone: {description}',
    'status.undo_with_errors'  => 'undo {description} with {errors} error(s)',
    'status.undo_target_occupied' => 'undo {description} blocked: {occupied} target(s) occupied — originals kept in trash',
    'status.cannot_close_last_tab' => 'Cannot close last tab',
    'status.redone'            => 'redone: {description}',
    'status.redo_with_errors'  => 'redo {description} with {errors} error(s)',
    'status.nothing_to_redo'    => 'nothing to redo',
    'status.copy_undo_noop'    => 'copy left in place (nothing to undo)',
    'status.focus'             => 'focus: {side}',
    'side.left'                => 'left',
    'side.right'               => 'right',

    // Undo/redo action descriptions (rendered inside status.undone/&c.)
    'undo.delete' => 'delete {count} item(s)',
    'undo.create' => 'create {count} item(s)',
    'undo.rename' => 'rename {count} item(s)',
    'undo.move'   => 'move {count} item(s)',
    'undo.copy'   => 'copy {count} item(s)',

    // Key help
    'keyhelp.default' => 'Tab swap · ↑↓ jk move · Home/End g/G jump · Enter open · ← h up · space select · s sort · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · Esc clear · t duplicate tab · ^w close tab · ^tab cycle · ^PgUp/^PgDn tabs · u/^z undo · ^y redo',
    'keyhelp.terse' => 'Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · c copy · m move · R rename · d delete · q quit · / search · u/^z undo · ^y redo',
    'keyhelp.minimal' => 'Enter open · q quit · / search',

    // Search
    'search.no_match' => '(no matches)',
    'search.counter'  => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[FILE]',

    // Pane header suffixes
    'pane.hidden_suffix' => '+hidden',
    'pane.more_above' => '{count} more above',
    'pane.more_below' => '{count} more below',
    'status.opening_editor' => 'editing {name}…',
    'status.opening_pager' => 'viewing {name}…',
    'status.running' => 'running {name}…',
    'status.opened' => 'back from {name}',
    'status.editor_failed' => '{name} exited with code {code}',
    'status.no_editor' => 'no editor found for {name}',
    'status.no_viewer' => 'no viewer for {name}',
    'status.not_executable' => '{name} is not executable',
];
