# candy-top SKINNY logo variants — task brief (not started yet)

User request (2026-10-08, verbatim intent): "make skinny versions of each of those logos so like a max width of 40
same height or less ... should be like a slightly smaller / slightly cropped / slightly condensed version of each image
to get it down to size.. there will probably be a bunch of overlapping. height wise it can be anywhere from like 5-15
rows but ideally around same height. spawn 2 agents to create these encouraging them to write new tools or copied
variations of existing ones to assist in this and if they wind up making a new tool or adding functionality to an
existing one to include as much in the response."

## Context (another session's logo campaign — NOT ours, never git-commit any of it)
- Logos live in `/home/sites/sugarcraft/candy-top/.assets/logos/` (UNTRACKED; do not stage/commit). Briefs from that
  campaign: `prompt_kit/briefs/candy-top-logo-brief.md` (output rules: filename `logo-<slug>-<tc|256|16>-<W>x<H>.ansi`,
  every line same visible width ending `\e[0m`, one `logos.jsonl` line per file appended with a single `>>` write) and
  `prompt_kit/briefs/candy-top-logo-build.md` (build steps + the shared tools list).
- Tools in `logos/tools/`: `logo-kit.php` (colour math, grids, lkEncode tc/256/16, lkVerify, lkAnim, lkWriteLogo —
  idempotent jsonl), `generate-template.php`, per-slug generators `generate-<slug>.php` (data-driven: palette, masks,
  layout at top), previewers `ansi-ttf-preview.php` (real DejaVu TTF → PNG, preferred), `logo-preview.php`,
  `block-braille-ttf-preview.php`, `mixed-glyph-preview.php`, rasterisers `quadrant-raster.php`,
  `vector-quadrant-raster.php`, `sextant-*.php`, `braille-*.php`, `inflated-sdf-letters.php`, `glyph-sprite.php`,
  `logo-play.php` (anim player ≤5 s), `show-logo.sh`, `ttf-coverage.php`.
- 20 static logos (slug WxH, all 72-80 cols × 8-11 rows): art-deco-gilt 76x9, cellophane-twist-wrapper 79x10,
  chocolate-bar-emboss 78x9, cross-stitch-sampler 77x9, foil-balloon-party 76x10, gumdrop-circuit-board 72x9,
  gummy-worm-script 73x9, licorice-allsorts 78x9, neon-tube-sign 78x11, peppermint-barberpole 78x11,
  phosphor-scope-trace 78x10, risograph-misprint 76x10, rock-candy-prism 76x8, spun-sugar-dither 80x10,
  stained-glass-candy 76x8, thermal-heatmap 80x10, twilight-candyland-hills 78x10, ukiyoe-woodblock-wave 80x10,
  vu-meter-matrix 73x9, watercolor-brush-bloom 80x10. (Re-list with
  `ls logos/logo-*-tc-*.ansi | grep -v -- -anim-`; generators exist for most, some only in `archive/`.)
  Several also have `-anim-` variants (skinny anim versions optional).

## The job
For EACH of the 20 logos make a skinny variant: max width 40 cols, height ~same (5-15 rows OK, ideally near the
original), still reading "CANDY TOP"/"candy top" legibly — a slightly smaller / cropped / condensed rendition of the
same design (letters may overlap/kern tight, stack CANDY over TOP, use narrower glyph masks, finer sub-cell raster
such as quadrant/sextant/braille, etc.). Produce tc + 256 + 16 versions each, same verification rules as the
original brief. Suggested slug: `<slug>-skinny` → `logo-<slug>-skinny-<tc|256|16>-<W>x<H>.ansi`, plus logos.jsonl
lines (tag `skinny`). Animation optional (`<slug>-skinny-anim`).

## Plan
- Spawn 2 implementer agents (general-purpose, background), 10 logos each (split the list alphabetically: first 10 /
  last 10). Brief each with: this file + the two logo briefs; encourage writing NEW tools or COPIED variations of
  existing ones (e.g. a generic "condense/kern a glyph mask", "re-raster an existing generator's mask at a narrower
  target", a skinny font set, a downscaler from a tc .ansi to a narrower grid) — new tools get unique filenames in
  `logos/tools/` (prefix `skinny-`), never edit shared tools in place (copy them). They must VIEW every result as PNG
  (ansi-ttf-preview.php → Read the PNG; PNGs in their scratchpad/job tmp, not the logos dir) and iterate until it
  looks good and is legible.
- Final reply from each agent MUST include: every tool/script created or modified (full path) with a description of
  what it does and its CLI usage, any functionality added to an existing tool, the list of .ansi files produced
  (with dimensions), and notes. Relay all of that to the user in full.
- Agents: no git writes; don't touch anything outside `candy-top/.assets/logos/`. Append jsonl lines with single `>>`
  writes (both agents share the file).
- After both finish: optionally a quick visual check of a few PNGs; report to the user. No commit (untracked campaign).
