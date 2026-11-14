<?php

/**
 * English (default) translations for sugar-readline.
 *
 * Source of truth: every key starts here (LOCALES.md). The values are the
 * exact bytes the chrome rendered before i18n existed (S-09 law: zero
 * behavioural diff on the default locale).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // ConfirmationPrompt answers and key hint. '[y/n]' names the literal
    // keys, so it is key notation, not prose — translated files keep it.
    'confirm.yes' => 'Yes',
    'confirm.no'  => 'No',
    'confirm.hint' => '[y/n]',

    // Selection filter bar (shared byte-for-byte by SelectionPrompt and
    // MultiSelectPrompt via SelectionEngine::renderFilterBar()). The
    // trailing space of filter_prefix and the leading space of match_count
    // are part of the painted bytes.
    'selection.filter_prefix' => '[filter] ',
    'selection.match_count'   => ' (%d match%s)',
    'selection.no_matches'    => '(no matches)',
    'selection.page'          => 'Page %d/%d',

    // MultiSelectPrompt hint row segments.
    'multiselect.selected' => '[multi-select] selected: %d',
    'multiselect.min'      => ' (min %d)',
    'multiselect.max'      => ' (max %d)',

    // TextPrompt chrome.
    'text.invalid_input'   => 'Invalid input',
    'text.search_reverse'  => 'reverse-i-search',
    'text.search_forward'  => 'i-search',
    'text.search_failed'   => 'failed ',
];
