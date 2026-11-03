<?php

/**
 * English (default) translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Human-facing refusal prose (surfaced through RuntimeException messages and
    // the sanitized error frame). The former 'NoItems:' Go-sentinel prefix was
    // retired 2026-11-03 — users get a sentence, not an error-variable name.
    'list.no_items'    => 'The list has no items to display.',
    'list.zero_viewport' => 'The viewport has zero width or height, so the list cannot be displayed.',
];
