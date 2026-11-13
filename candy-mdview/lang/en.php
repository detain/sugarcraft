<?php

/**
 * English (default) translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'unknown theme: {name} — JSON style files belong in --theme-config',
    'render.style_overrides_theme' => 'note: --style "{style}" overrides --theme "{theme}"',
    'render.theme_config_unreadable' => 'theme config not readable: {path}',
    'render.theme_config_too_large' => 'theme config exceeds {limit} bytes: {path}',
    'render.theme_config_unrecognized' => 'theme config matches neither the flat nor the glamour schema: {path}',
    'glamour.json_invalid' => 'glamour theme config is not valid JSON',
    'glamour.json_object' => 'glamour theme config must be a JSON object',
    'glamour.decoration_unsafe' => 'theme {slot} must contain only newlines, tabs and spaces',
    'glamour.value_too_large' => 'theme {key} exceeds the maximum of {limit}',
    'render.no_input_tty' => 'no input: pass a file argument, pipe Markdown to standard input, or run with --help',
    'render.no_input_empty' => 'no input: standard input is empty',
    'render.pager_needs_tty' => 'pager needs a terminal; render to stdout instead (drop -p)',
    'render.file_missing' => 'open {path}: no such file',
    'render.file_is_directory' => 'open {path}: is a directory',
    'render.file_unreadable' => 'open {path}: cannot read file',
    'render.binary_input' => 'refusing to render {path}: not a text file',
    'render.input_too_large' => 'input exceeds {limit} bytes: {path}',
    'render.fetch_failed' => 'cannot fetch {url}: {reason}',
    'render.directory_chosen' => 'rendering {chosen} from directory argument {path}',
    'render.directory_ambiguous' => 'no single document in {path}: found {found} — pass one of them explicitly',
    'render.directory_empty' => 'no Markdown document in {path}: looked for README.md, README.markdown, index.md or a single *.md',
    'render.width_invalid' => 'invalid --width value: {value} (expected an integer between 0 and 9999; 0 disables wrapping)',
];
