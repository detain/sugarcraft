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
    'cli.usage'               => <<<'USAGE'
candy-codeshot - render code or terminal output to an SVG screenshot

Usage:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Options:
  --theme <name>          Theme name: dark, light, dracula, tokyo-night, nord (default: dark)
  --padding <n>           Padding around content (default: 24)
  --no-window             Hide window chrome (traffic lights, title bar)
  --no-shadow             Disable drop shadow
  --no-border             Hide frame border
  --line-numbers          Show line numbers in gutter
  --border-radius <n>     Corner radius (default: 8)
  --window-style <style>  Window style: macos, windows-terminal, iterm, hyper, none (default: macos)
  --ligatures             Enable ligatures (font-variant-ligatures: normal)
  --font <path>           Path to a TTF/OTF/WOFF/WOFF2 font file to embed in the SVG
  --unsafe-paths          Allow input/--font/--output paths outside the CWD
  --highlight <start:end[:color]>
                          Highlight lines start:end with optional color (default color: #fffbe6)
  --format <svg|png>      Output format (default: svg)
  -o, --output <path>     Write output to file instead of stdout
  -h, --help              Show this help message

Examples:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
USAGE,
];
