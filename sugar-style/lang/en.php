<?php

/**
 * English (default) translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'width must be >= 0',
    'tree.offset_nonneg'    => 'tree offset bounds must be >= 0',
    'style.width_nonneg'    => 'width must be >= 0',
    'style.height_nonneg'   => 'height must be >= 0',
    'style.maxwidth_nonneg' => 'maxWidth must be >= 0',
    'style.maxheight_nonneg' => 'maxHeight must be >= 0',
    'style.tabwidth_nonneg' => 'tabWidth must be >= 0',
    'style.spacing_nonneg' => 'spacing must be >= 0',
    'style.slot_unresolved' => 'the adaptive colour slots {slots} were never collapsed; call resolveAdaptive(bool $isDark) before render()',
    'style.hyperlink_id_delimiter' => 'hyperlink id must not contain ";" or ":" (OSC 8 parameter delimiters)',
    'style.markup_depth' => 'markup nesting exceeds {levels} levels',
    'style.write_invalid_stream' => 'cannot write: {destination} is not a valid stream resource',
    'style.write_short' => 'stream write failed: wrote {written} of {total} bytes',
    'style.label_nonneg'    => '{label} values must be >= 0',
    'style.border_toggles_count' => 'border() side toggles take 0, 2, or 4 bools; got {count}',
    'style.expand_sides_count'   => '{label}() takes 1, 2, or 4 ints; got {count}',
];
