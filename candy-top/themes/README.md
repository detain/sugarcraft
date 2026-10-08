# candy-top themes

Of these 42 `.theme` files, 41 are copied **verbatim** from
[aristocratos/btop](https://github.com/aristocratos/btop)'s `themes/` directory
(upstream commit `d3389d74e88644c50907e84314c3c7c973ba5be9`, 2026-09-30).
Headers, author credits and comments are left untouched; do not edit them in
place — re-copy from upstream instead.

The 42nd, `mellow.theme`, comes from the still-unmerged upstream PR
[#1683](https://github.com/aristocratos/btop/pull/1683) (commit `09f7dd477fbc`),
built on the [mellow.nvim](https://github.com/mellow-theme/mellow.nvim) palette
(MIT). It is the PR's file byte-for-byte apart from two added header lines: a
palette licence note and a PR provenance line.

btop is licensed under the Apache License, Version 2.0; a verbatim copy of its
license ships alongside these files as [`LICENSE`](./LICENSE) (Apache-2.0 §4a),
and the theme files are redistributed under it as part of that project. Several files additionally credit
their individual authors or the palette they adapt (nord, gruvbox, solarized,
…) in their header comments; those credits remain with the files.

Format: `theme[key]="value"` lines, `#` comments; values are `#RRGGBB`, `#GG`
(gray level) or `R G B` decimal, and an empty value means transparent
(`main_bg`) or "no stop" (`*_mid` / `*_end`). Loaded by
`SugarCraft\Top\Theme\ThemeConfig::fromFile()`; listed after the builtin
`Default` and `TTY` themes by `SugarCraft\Top\Theme\ThemeRegistry`. Drop your
own `.theme` files in `~/.config/candy-top/themes/` (or
`$XDG_CONFIG_HOME/candy-top/themes/`) — a file there with the same name as one
here takes precedence.
