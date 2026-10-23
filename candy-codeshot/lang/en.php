<?php

/**
 * English (default) translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: unrecognised flag '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: unknown theme '{name}'. Known: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: unknown window style '{style}'. Known: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: unknown format '{format}'. Known: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd is required for PNG output',
    'cli.font_not_found'      => "candy-codeshot: font file not found: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' is not a valid font file (expected TTF, OTF, WOFF or WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: invalid highlight format '{format}'. Use start:end (e.g. 3:7) or start:end:#color (e.g. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: failed to read input',
    'cli.path_outside_cwd'    => "candy-codeshot: path '{path}' is outside the current working directory (use --unsafe-paths to allow)",
    'cli.write_failed'        => "candy-codeshot: failed to write '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} requires a value",
    'cli.bad_number'          => "candy-codeshot: {option} expects a non-negative integer, got '{value}'",
    'cli.png_ignores'         => "candy-codeshot: PNG output ignores SVG-only options: {options} (use --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: rejected highlight '{spec}': {reason}",
    'cli.format_mismatch'     => "candy-codeshot: output format '{format}' does not match the extension of '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - render code or terminal output to an SVG or PNG screenshot

Usage:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Flag values are separate arguments: "--theme dracula", not "--theme=dracula"
(the "= " form is rejected as an unrecognised flag).

Options:
  --theme <name>          Theme name: dark, light, dracula, tokyo-night, nord (default: dark)
  --padding <n>           Padding around content (default: 24)
  --no-window             Hide window chrome (traffic lights, title bar)
  --no-shadow             Disable drop shadow
  --no-border             Hide frame border
  --line-numbers          Show line numbers in gutter
  --border-radius <n>     Corner radius (default: 8)
  --window-style <style>  Window style: macos, windows-terminal, iterm, hyper, none (default: macos)
  --ligatures             Enable ligatures (font-variant-ligatures: normal; SVG only)
  --font <path>           Path to a TTF/OTF/WOFF/WOFF2 font file to embed in the SVG (SVG only)
  --unsafe-paths          Allow input/--font/--output paths outside the CWD
  --highlight <start:end[:color]>
                          Highlight lines start:end with optional color (default color: #fffbe6; SVG only)
  --format <svg|png>      Output format (default: svg; png requires ext-gd)
  -t, --type <lang>       Force language for syntax highlighting (default: filename, shebang, content)
  -o, --output <path>     Write output to file instead of stdout
  -h, --help              Show this help message

Notes:
  Input and output paths must stay inside the current working directory
  unless --unsafe-paths is given. PNG output ignores the SVG-only options
  above and announces each one it drops on stderr.

Exit codes:
  0  success
  1  missing composer autoload (installation problem)
  2  usage or input error (bad flag, unreadable path, refused highlight, ...)

Examples:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
