<?php

/**
 * English (default) translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'unknown theme: {name}',
    'render.theme_config_unreadable' => 'theme config not readable: {path}',
    'render.theme_config_too_large' => 'theme config exceeds {limit} bytes: {path}',
    'glamour.json_invalid' => 'glamour theme config is not valid JSON',
    'glamour.json_object' => 'glamour theme config must be a JSON object',
    'glamour.decoration_unsafe' => 'theme {slot} must contain only newlines, tabs and spaces',
    'glamour.value_too_large' => 'theme {key} exceeds the maximum of {limit}',
    'render.no_input_tty' => 'no input: pass a file argument or pipe Markdown to standard input',
    'render.no_input_empty' => 'no input: standard input is empty',
    'render.pager_needs_tty' => 'pager needs a terminal; render to stdout instead (drop -p)',
    'render.file_missing' => 'open {path}: no such file',
    'render.file_is_directory' => 'open {path}: is a directory',
    'render.file_unreadable' => 'open {path}: cannot read file',
    'render.binary_input' => 'refusing to render {path}: not a text file',
    'render.input_too_large' => 'input exceeds {limit} bytes: {path}',
    'render.width_invalid' => 'invalid --width value: {value} (expected an integer between 0 and 9999; 0 disables wrapping)',
];
