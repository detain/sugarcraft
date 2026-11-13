<?php

/**
 * English (default) translations for sugar-quickpick.
 *
 * Chrome is UTF-8: the ↕ (U+2195), ─ (U+2500), ▏ (U+258F) and ⋯ (U+22EF)
 * glyphs need a UTF-8 capable terminal, and the marker/caret reserve their
 * display cells through Width::of(), so a locale that swaps a glyph for a
 * wider one must re-check the frame arithmetic. The English text here is
 * also the code's DEFAULT — hosts without another catalogue render exactly
 * these bytes.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'showing_range' => '  ↕ showing {from}-{to} of {total}',
    'no_matches' => '  no matches',
    'no_items' => '  no items',
    'anchor_hidden' => '  {count} hidden by anchor bias',
    'filter_overflow' => '▏⋯',
    'filter_caret' => '▏',
];
