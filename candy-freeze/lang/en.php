<?php

/**
 * English (default) translations for candy-freeze.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candyfreeze: unrecognised flag '{flag}'",
    'cli.unknown_theme'       => "candyfreeze: unknown theme '{name}'. Known: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candyfreeze: unknown window style '{style}'. Known: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candyfreeze: unknown format '{format}'. Known: svg, png",
    'cli.gd_required'         => 'candyfreeze: ext-gd is required for PNG output',
    'cli.font_not_found'      => "candyfreeze: font file not found: '{path}'",
    'cli.bad_highlight'       => "candyfreeze: invalid highlight format '{format}'. Use start:end (e.g. 3:7) or start:end:#color (e.g. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candyfreeze: failed to read input',
    'cli.path_outside_cwd'    => 'candyfreeze: input path is outside the current working directory',
    'cli.write_failed'        => "candyfreeze: failed to write '{path}'",
    'cli.usage'               => <<<'USAGE'
candyfreeze - render code or terminal output to an SVG screenshot

Usage:
  echo "hello" | candyfreeze --theme dracula > out.svg
  candyfreeze input.txt --theme tokyo-night --no-window --line-numbers

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
  --font <path>           Path to a TTF font file to embed in the SVG
  --highlight <start:end[:color]>
                          Highlight lines start:end with optional color (default color: #fffbe6)
  --format <svg|png>      Output format (default: svg)
  -o, --output <path>     Write output to file instead of stdout
  -h, --help              Show this help message

Examples:
  cat script.php | candyfreeze --theme dracula
  candyfreeze script.php --theme tokyo-night --line-numbers -o out.svg
  candyfreeze script.php --window-style none --highlight 3:7
USAGE,
];
