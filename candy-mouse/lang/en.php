<?php

/**
 * English (default) translations for candy-mouse.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mark.id_invalid'        => 'Mark id must match {pattern} (ASCII letters, digits, and ._:- only); got {id} — an id with sentinel/control/whitespace bytes would desync zone scanning.',
    'mark.id_too_long'       => 'Mark id exceeds {max} bytes (got {len}).',
    'mark.content_too_long'  => 'Mark content exceeds {max} bytes (got {len}) — cap oversized/reflected input to bound zone scanning.',
    'scan.duplicate_id'      => 'candy-mouse: duplicate zone id {id} in scanned render — every zone id must be unique (a repeated id would merge two zones into one wrong bounding box).',
    'selection.off_terminal' => 'candy-mouse: selection coordinates are 1-based terminal cells, got {cell} — clamp or rebase the pointer before building a range.',
];
