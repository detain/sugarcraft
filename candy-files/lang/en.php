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
    'prompt.path' => 'Open: ',
    'prompt.pattern' => 'Select: ',
    'status.path_unreadable' => 'Cannot read: {path}',
    'status.path_opened' => 'Opened: {path}',
    'confirm.selected_entries' => '{count} selected entries',
    'confirm.affirmative' => 'y',

    // Status messages
    'status.nothing_to_delete' => 'nothing to delete',
    'status.nothing_to_copy'   => 'nothing to copy',
    'status.nothing_to_move'   => 'nothing to move',
    'status.nothing_to_rename' => 'nothing to rename',
    'status.cancelled'        => 'cancelled',
    'status.refreshed'        => 'refreshed',
    'status.unreadable' => 'cannot read {dir} — showing previous contents',
    'status.copy_pending'     => 'copying {count} entries…',
    'status.quit_armed'     => '{count} copy batches still in flight — press q again to quit anyway',
    'status.search_recursive_on' => 'search: recursive (^r again = here only)',
    'status.search_recursive_off' => 'search: this directory only',
    'status.search_partial' => 'search partial - results hit the walk budget',
    'status.selection_count' => '{count} selected',
    'status.deleted'           => 'deleted {count} entries',
    'status.deleted_with_errors' => 'deleted {count} entries with {errors} errors',
    'status.deleted_skipped'   => 'deleted {count} entries ({skipped} skipped)',
    'status.deleted_permanent' => 'deleted {count} entries ({permanent} permanent)',
    'status.copied'           => 'copied {count} entries',
    'status.copied_with_errors' => 'copied {count} entries with {errors} errors',
    'status.copied_skipped'   => 'copied {count} entries ({skipped} skipped)',
    'status.copied_overwrote' => 'copied {count} entries ({overwrote} overwritten)',
    'status.moved'            => 'moved {count} entries',
    'status.created'       => 'folders created: {count}',
    'status.mkdir_failed'    => 'cannot create {name}',
    'status.moved_with_errors' => 'moved {count} entries with {errors} errors',
    'status.moved_skipped'    => 'moved {count} entries ({skipped} skipped)',
    'status.moved_overwrote'    => 'moved {count} entries ({overwrote} overwritten)',
    'status.renamed'          => 'renamed {old} to {new}',
    'status.renamed_overwrote'  => 'renamed {old} to {new} ({overwrote} overwritten)',
    'status.rename_failed'    => 'rename failed: {name}',
    'status.rename_invalid'    => 'rename refused: invalid name {name}',
    'status.nothing_to_undo'    => 'nothing to undo',
    'status.undone'            => 'undone: {description}',
    'status.undo_with_errors'  => 'undo {description} with {errors} errors',
    'status.undo_target_occupied' => 'undo {description} blocked — occupied targets: {occupied} (originals kept in trash)',
    'status.cannot_close_last_tab' => 'cannot close last tab',
    'status.redone'            => 'redone: {description}',
    'status.redo_with_errors'  => 'redo {description} with {errors} errors',
    'status.nothing_to_redo'    => 'nothing to redo',
    'status.copy_undo_noop'    => 'copy left in place (nothing to undo)',
    'status.focus'             => 'focus: {side}',
    'side.left'                => 'left',
    'side.right'               => 'right',

    // Undo/redo action descriptions (rendered inside status.undone/&c.)
    'undo.delete' => 'items deleted: {count}',
    'undo.create' => 'items created: {count}',
    'undo.rename' => 'items renamed: {count}',
    'undo.move'   => 'items moved: {count}',
    'undo.copy'   => 'items copied: {count}',

    // Key help
    'keyhelp.default' => 'Tab swap · ↑↓ jk move · Home/End g/G jump · Enter open · l view · ← h up · space select · s sort · e ext · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · Esc clear · t duplicate tab · ^w close tab · ^tab cycle · ^PgUp/^PgDn tabs · u/^z undo · ^y redo · ? help · Ctrl+O open · 1-9 tabs · Ctrl+a all · v invert · * pattern · I info · mouse click/wheel · y/Y yank · p paste · a pin · B bookmarks',
    'keyhelp.terse' => 'Tab swap · ↑↓ jk move · Enter open · l view · ← h up · space select · s sort · e ext · c copy · m move · R rename · d delete · q quit · / search · u/^z undo · ^y redo',
    'keyhelp.minimal' => 'Enter open · l view · q quit · / search',
    'help.title' => '{count} key bindings',
    'help.more' => '+{count} more',
    'help.dismiss' => '? or Esc closes',

    // Search
    'search.no_match' => '(no matches)',
    'search.counter'  => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[FILE]',

    // Pane header suffixes
    'pane.hidden_suffix' => '+hidden',
    'pane.unreadable' => '(unreadable)',
    'row.link_broken' => 'broken',
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

    // CF-16 (closes 025, 056, 060): rendered chrome through Lang.
    'search.prompt' => 'Search: ',
    'size.dir' => 'DIR',
    'size.link' => 'LINK',
    'sort.name' => 'Name',
    'sort.mtime' => 'Date',
    'sort.size' => 'Size',
    'sort.ext' => 'Ext',
    'cli.usage' => 'candy-files — dual-pane file manager

Usage:
  candy-files [left] [right]   Open panes on the given directories
                               (default: cwd on the left, HOME on the right)
  candy-files --version        Print the version
  candy-files --help           Show this help
  candy-files --print-cd       Print the final working directory on exit',
    'cli.dir_missing' => '{side} directory {reason}: {dir}',
    'cli.reason_missing' => 'does not exist',
    'cli.reason_unreadable' => 'is not readable',
    'status.deleted_trash_hint' => 'moved to session trash — deleted forever when you quit (u restores until then)',
    'info.title' => 'File information',
    'info.path' => 'Path',
    'info.type' => 'Type',
    'info.type_file' => 'file',
    'info.type_dir' => 'directory',
    'info.type_link' => 'symbolic link',
    'info.size' => 'Size',
    'info.mode' => 'Permissions',
    'info.owner' => 'Owner',
    'info.modified' => 'Modified',
    'info.accessed' => 'Accessed',
    'info.target' => 'Link target',
    'info.free' => 'Free space',
    'info.dismiss' => 'I or Esc closes',
    'status.info_failed' => 'cannot read info for {name}',
    'status.yanked' => 'yanked {count} entries for copy — p pastes',
    'status.cut' => 'cut {count} entries for move — p pastes',
    'status.nothing_to_yank' => 'nothing to yank',
    'status.nothing_to_paste' => 'nothing to paste — y or Y stages entries first',
    'status.bookmark_added' => 'pinned {path}',
    'status.bookmark_exists' => 'already pinned: {path}',
    'status.bookmark_removed' => 'unpinned {path}',
    'status.bookmark_failed' => 'cannot save bookmarks',
    'bookmarks.title' => '{count} bookmarks',
    'bookmarks.empty' => 'no bookmarks — a pins the current directory',
    'bookmarks.dismiss' => 'Enter jumps · d removes · B or Esc closes',
];
