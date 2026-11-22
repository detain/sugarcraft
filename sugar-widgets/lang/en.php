<?php

/**
 * English (default) translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'column width must be >= 0',
    'tabs.bad_index'              => 'tab index out of range',
    'tabs.neg_width'              => 'tab width must be >= 0',
    'tabs.neg_padding'            => 'tab label padding must be >= 0',
    'tabs.zone_key_empty'         => 'tab zone key must not be empty',
    'tabs.close_glyph_empty'      => 'tab close glyph must not be empty',
    'table.dim_nonneg'            => 'table width/height must be >= 0',
    'table.page_size_nonneg'      => 'page size must be >= 0',
    'table.set_columns_type'      => 'columns must be column instances',
    'table.sort_unknown_column'   => 'sort column not found: {column}',
    'progress.width_nonneg'       => 'progress width must be >= 0',
    'tree.dim_nonneg'             => 'tree width/height must be >= 0',
    'help.width_nonneg'           => 'help width must be >= 0',
    'stopwatch.interval_positive' => 'stopwatch interval must be > 0',
    'timer.duration_nonneg'       => 'timer duration must be >= 0',
    'timer.interval_positive'     => 'timer interval must be > 0',
    'paginator.arabic_format_invalid' => 'arabic format must be a valid format of two integers',
    'progress.bad_percent_format' => 'percent format must be a valid format of one integer',
    'progress.bad_value_format' => 'value format must be a valid format of two integers',
    'progress.rune_cell_width' => 'bar runes must be exactly one display cell',
    'progress.color_func_type' => 'color function must return a color',
    'tabs.next_tab' => 'next tab',
    'tabs.prev_tab' => 'prev tab',
    'tabs.jump_to' => 'tab {index}',
    'tabs.close_tab' => 'close tab',
    'timer.paused_tag' => 'paused',
    'timer.timeout_tag' => 'timeout',
    'stopwatch.not_started_tag' => 'not started',
    'stopwatch.paused_tag' => 'paused',
    'key.bad_binding' => 'invalid key binding "{key}": use a lowercase key name (ctrl+c, shift+tab, pageup, f5) or a single printable character',
    'key.bad_binding_type' => 'binding keys must be strings, got {key}',
    'tree.use_branch_for_children' => 'use the branch() factory when a node has children',
    'button.pressed' => '{label} activated',
    'rule.length_nonneg' => 'rule length must be >= 0',
    'rule.bad_rune' => 'rule rune must occupy exactly one cell',

    // Help/HelpOverlay.php, Key/KeyView.php
    'help_overlay.height_positive' => 'the help overlay height must be >= 1',
    'key_view.bad_columns' => 'key view columns must be a list of lists of action names',
    'key_view.column_size_nonneg' => 'key view column size must be >= 0',
];
