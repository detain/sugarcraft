<?php

/**
 * English (default) translations for sugar-mouse.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mark.id_invalid'        => 'sugar-mouse: Mark id must match {pattern} (ASCII letters, digits, and ._:- only); got {id} — an id with sentinel/control/whitespace bytes would desync zone scanning.',
    'mark.id_too_long'       => 'sugar-mouse: Mark id exceeds {max} bytes (got {len}).',
    'mark.content_too_long'  => 'sugar-mouse: Mark content exceeds {max} bytes (got {len}) — cap oversized/reflected input to bound zone scanning.',
    'scan.duplicate_id'      => 'sugar-mouse: duplicate zone id {id} in scanned render — every zone id must be unique (a repeated id would merge two zones into one wrong bounding box).',
    'selection.off_terminal' => 'sugar-mouse: selection coordinates are 1-based terminal cells, got {cell} for parameter {param} — clamp or rebase the pointer before building a range.',
];
