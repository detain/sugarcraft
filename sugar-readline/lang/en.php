<?php

declare(strict_types=1);

/**
 * sugar-readline English catalog — SOURCE OF TRUTH.
 *
 * Chrome strings only (see README "Internationalization"): labels, choices,
 * and validator messages are host data and never ride this catalog.
 *
 * Values are the exact bytes the chrome rendered before i18n existed; the
 * era-S-10 pass replaced English morphology splicing (' (%d match%s)' + an
 * ''/'es' ternary), fragment concatenation (selected + min + max clauses),
 * and the raw-concat search echo with whole-sentence keys and named
 * {placeholder} tokens, per the LOCALES.md plural policy. Lookup follows the
 * AGENTS.md law (exact locale → base language → en → raw key), so an unknown
 * key surfaces visibly.
 */

return [
    // ConfirmationPrompt chrome. '[y/n]' is key notation, not prose: it
    // rides verbatim across all locales (the bytes name actual keystrokes).
    'confirm.hint' => '[y/n]',
    'confirm.no' => 'No',
    'confirm.yes' => 'Yes',

    // FileHistory diagnostics (finding 041): the user-reachable throw sites
    // translate at throw time; the 'sugar-readline: ' program tag stays
    // English at the call site (it is the grep handle). The three error_log
    // channels (destruct flush failure, tryOpen decline, read-lock
    // degradation) are developer-facing diagnostics and ride the recorded
    // English exemption in CALIBER_LEARNINGS.md.
    'history.append_lock_failed' => 'cannot acquire append lock on {path}',
    'history.changed_under_us' => 'history path changed under us (possible symlink swap): {path}',
    'history.chmod_failed' => 'cannot set 0600 on history file {path}',
    'history.clear_temp_failed' => 'cannot create temp file for history clear in {dir}',
    'history.create_failed' => 'cannot create history file {path}',
    'history.lock_acquire_failed' => 'cannot acquire history write lock {path}',
    'history.lock_changed' => 'history lock changed under us (possible symlink swap): {path}',
    'history.lock_open_failed' => 'cannot open history write lock {path}',
    'history.not_regular' => 'history path is not a regular file (opened through a swap?): {path}',
    'history.open_failed' => 'cannot open history file {path}',
    'history.replace_failed' => 'cannot replace history file {path}',
    'history.symlink_refused' => 'refusing symlinked {what} {path}',
    'history.temp_create_failed' => 'cannot create temp file for history rewrite in {dir}',
    'history.temp_lock_failed' => 'cannot lock temp file for history rewrite: {path}',
    'history.temp_open_failed' => 'cannot open temp file for history rewrite: {path}',

    // MultiSelectPrompt hint row — one whole sentence per min/max
    // combination so word order stays the locale's own.
    'multiselect.hint' => '[multi-select] selected: {count}',
    'multiselect.hint_max' => '[multi-select] selected: {count} (max {max})',
    'multiselect.hint_min' => '[multi-select] selected: {count} (min {min})',
    'multiselect.hint_min_max' => '[multi-select] selected: {count} (min {min}) (max {max})',
    'multiselect.notice_unmarked' => 'un-marked {item} to respect the limit of {max}',
    'multiselect.nothing_to_submit' => 'nothing matches the filter',

    // SelectionEngine filter bar / page footer. matches_one/_other are
    // whole sentences chosen at the call site (LOCALES.md plural policy);
    // zero rides _other exactly like English prose does (' (0 matches)').
    'selection.filter_prefix' => '[filter] ',
    'selection.matches_one' => ' ({count} match)',
    'selection.matches_other' => ' ({count} matches)',
    'selection.no_matches' => '(no matches)',
    'selection.page' => 'Page {page}/{of}',

    // TextareaPrompt transient notices (S-31).
    'textarea.notice_max_lines' => 'Cannot add a line: maximum {max} reached',

    // TextPrompt chrome.
    'text.invalid_input' => 'Invalid input',
    'text.notice_no_history' => 'History is empty',

    // Incremental-search echo line — the whole line is the unit so the
    // failure wording and direction verb stay wherever the language needs
    // them. {label} arrives pre-sanitized (or styled) from the call site;
    // {query} is the search needle, kept intact through sanitize so users
    // see exactly what they typed.
    'text.search_line_forward' => '{label}(i-search)`{query}\': ',
    'text.search_line_forward_failed' => '{label}(failed i-search)`{query}\': ',
    'text.search_line_reverse' => '{label}(reverse-i-search)`{query}\': ',
    'text.search_line_reverse_failed' => '{label}(failed reverse-i-search)`{query}\': ',
];
