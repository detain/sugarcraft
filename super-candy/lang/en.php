<?php

/**
 * English (default) translations for super-candy.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Confirmation prompts
    'confirm.delete'     => 'delete {names}? (y/n)',
    'confirm.delete_one' => "delete '{name}'? (y/n)",

    // Status messages
    'status.nothing_to_delete' => 'nothing to delete',
    'status.nothing_to_copy'   => 'nothing to copy',
    'status.nothing_to_move'   => 'nothing to move',
    'status.nothing_to_rename' => 'nothing to rename',
    'status.cancelled'        => 'cancelled',
    'status.deleted'           => 'deleted {count} entries',
    'status.deleted_with_errors' => 'deleted with {errors} errors',
    'status.copied'           => 'copied {count} entries',
    'status.copied_with_errors' => 'copied with {errors} errors',
    'status.moved'            => 'moved {count} entries',
    'status.moved_with_errors' => 'moved with {errors} errors',
    'status.renamed'          => 'renamed {old} to {new}',
    'status.rename_failed'    => 'rename failed: {name}',
    'status.nothing_to_undo'    => 'nothing to undo',
    'status.undone'            => 'undone: {description}',
    'status.undo_with_errors'  => 'undo {description} with {errors} error(s)',
    'status.cannot_close_last_tab' => 'Cannot close last tab',

    // Key help
    'keyhelp.default' => 'Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · c copy · m move · R rename · d delete · r refresh · q quit · / search · t new tab · ^w close tab · ^tab cycle',

    // Search
    'search.prompt'   => 'Search: {query}',
    'search.no_match' => '(no matches)',
    'search.counter'  => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[FILE]',

    // Pane header suffixes
    'pane.hidden_suffix' => '+hidden',

    // Entry display
    'entry.dir'   => 'DIR',
    'entry.link'  => 'LINK',

    // Sort orders (display labels used in pane header)
    'sort.name_asc'   => 'name-asc',
    'sort.name_desc' => 'name-desc',
    'sort.mtime_asc'  => 'mtime-asc',
    'sort.mtime_desc' => 'mtime-desc',
    'sort.size_asc'  => 'size-asc',
    'sort.size_desc' => 'size-desc',

    // Preview pane
    'preview.no_file'         => '(no file selected)',
    'preview.file_not_found' => '(file not found)',
    'preview.is_directory'   => '(is a directory)',
    'preview.image_error'     => '(image error: {error})',
    'preview.invalid_width'  => 'width must be positive',
    'preview.invalid_height' => 'height must be positive',
    'preview.metadata'       => 'Metadata',
    'preview.size'           => 'Size',
    'preview.mtime'          => 'Modified',
    'preview.mode'           => 'Mode',
    'preview.type'           => 'Type',
    'preview.link_target'    => 'Link',
    'preview.type_file'      => 'file',
    'preview.stat_failed'    => 'stat failed',
    'preview.unknown'       => 'unknown',

    // Bulk rename
    'bulk_rename.conflict' => 'duplicate names in output',
    'bulk_rename.invalid_pattern' => 'invalid regex pattern',
    'bulk_rename.empty_result' => 'resulting name is empty',
    'bulk_rename.success' => 'renamed {count} files',
    'bulk_rename.error'   => 'rename failed: {error}',

    // Error messages
    'error.remove_path' => 'failed to remove {path}',
    'error.restore_path' => 'failed to restore {path}',
    'error.mkdir' => 'failed to create directory {path}',
];
