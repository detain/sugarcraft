# candy-top SKINNY logos: run from the ORIGINAL logo session (12f418fe)

The user wants every skinny logo made by the SAME subagent that made the full-size logo (it already knows its own
generator). Those subagents can only be resumed from this session (12f418fe). Do it in THREE PHASES:

1. **Pilots.** SendMessage ONLY logo-15 (art-deco-gilt) and logo-09 (phosphor-scope-trace) with the per-agent message
   below, PLUS this addition: "You are a pilot. Put real effort into TOOLS: write new reusable tools or copied
   variations of existing ones (skinny- prefix, --help/usage header, generic, not tied to your logo) that the other
   18 logo agents will use next. Finish/replace the stopped pilots' tools listed in the resume brief as you see fit.
   In your final reply list every tool in full, and add a 'how another agent should use these tools for a different
   logo' section." Wait for both to finish.
2. **Tools.** Review every skinny-* tool both pilots made or extended (plus the stopped pilots' leftovers). Either
   merge the overlapping ones into one super tool (e.g. `skinny-kit.php` lib + `skinny.php` CLI with subcommands)
   or keep them as separate tools where they do distinct jobs. Make sure each has a usage header and `php -l` is
   clean, and smoke-run them on one logo. Then rewrite the "Skinny tools" section below to describe the FINAL tool
   set and how to use it.
3. **The other 18.** SendMessage the remaining 18 original agents (table below) with the per-agent message, which
   now points at the final tool section. They run concurrently in the background.

Task spec: `prompt_kit/briefs/candy-top-skinny-logos.md` (max 40 cols, 5-15 rows ideally near the original height,
still legible "CANDY TOP", slightly smaller/cropped/condensed rendition of the SAME design; tc+256+16;
`logo-<slug>-skinny-<tc|256|16>-<W>x<H>.ansi`; logos.jsonl lines tagged `skinny`, appended with a single `>>` write).

## Slug → original agent (name in this session / agent id)
| slug | name | id |
|---|---|---|
| art-deco-gilt | logo-15 | a43b0414ddac4fe1b |
| cellophane-twist-wrapper | logo-14 | a00abcb1502217b15 |
| chocolate-bar-emboss | logo-10 | aaa429e3f38187263 |
| cross-stitch-sampler | logo-18 | a3f71f6a24e3341e6 |
| foil-balloon-party | logo-19 | aa53f34045c57e1c5 |
| gumdrop-circuit-board | logo-08 | a775a352ab64bd0fd |
| gummy-worm-script | logo-12 | aba1156ff7f94695c |
| licorice-allsorts | logo-01 | ad6fbc00b38365295 |
| neon-tube-sign | logo-06 | a1bd8e2fa4034b81c |
| peppermint-barberpole | logo-07 | a7270a709b92aa823 |
| phosphor-scope-trace | logo-09 | a29287309f6f9dcb0 |
| risograph-misprint | logo-16 | ac6d1c698c2d2a2ad |
| rock-candy-prism | logo-02 | abec31433ee91c0f3 |
| spun-sugar-dither | logo-11 | ad4effc5cc46964e8 |
| stained-glass-candy | logo-05 | a5c8c1abbd15297b3 |
| thermal-heatmap | logo-03 | ac1682a6c82b70ad2 |
| twilight-candyland-hills | logo-13 | a3194ec8e3ab01bd8 |
| ukiyoe-woodblock-wave | logo-20 | a42a2ec7ac1527176 |
| vu-meter-matrix | logo-04 | ad78875f0420de3af |
| watercolor-brush-bloom | logo-17 | a1f46d9cae22670eb |

(Mapped by which agent wrote `tools/generate-<slug>.php` / the most `logo-<slug>-tc` references; peppermint, rock-candy
and vu-meter mapped by output references; a1f46… also touched foil-balloon-party but aa53f… was its main author.)
If an agent can't be resumed, spawn a fresh general-purpose agent with the same message plus "read your predecessor's
generator `tools/generate-<slug>.php` first".

## Skinny tools (FINAL set — phase 2 done; reviewed, `php -l` clean, every tool smoke-run)
All in `candy-top/.assets/logos/tools/`. Shared tools are READ-ONLY for you: to change one, copy it under a new
`skinny-<slug>-…` name. Every CLI prints full usage with `--help`.

**One front-end:** `php tools/skinny.php <command> [args]` (`php tools/skinny.php` lists commands;
`php tools/skinny.php <command> --help` shows that command's options). Args pass straight through.
**One library loader:** `require_once __DIR__ . '/skinny-kit.php';` gives you every function below + logo-kit (lk*).

| command | tool | use it to |
|---|---|---|
| `fit` | skinny-type-fit.php (lib `stf*`) | plan TTF lettering: `--grid=40x11 --line='FONT\|TEXT\|x0,y0,x1,y1[\|condense[\|tracking[\|embolden]]]'`; reports ink box + thinnest stroke (aim ≥2 sub-px); FONT = path or fc-list short name (`--fonts` lists them, e.g. URWGothic-Demi, NimbusSansNarrow-Bold). `stfEmbolden()` fixes hairlines. |
| `stroke` | skinny-stroke-font.php (lib `ssf*`) | centre-line font → distance field → braille/sextant/quadrant/half ink; `--rows="CANDY/TOP" --w=38 --h=11 --glyph=6x5 [--gap=-0.2] [--res=sextant] --colour`. Field gives glow distance, per-letter id, draw order (reveal anims). |
| `btop` | skinny-btop-parts.php (lib `sb*`) | btop/candy-top pieces in a cell grid: `sbFrame` (tabs auto-drop to fit), `sbBox`, `sbArea`/`sbGraph` braille graphs (up/down, height-coloured, edge fade, skip-letter cells), `sbMeter` ■ meters, `sbSeries` fake data; records 16-colour pins. `--demo=40x9 --depth=16`. |
| `carve` | skinny-seamcarve.php | quick DRAFT: content-aware condense of your full logo to ≤40 cols (`<in.ansi> --w=40 [--h=N] --out=x.ansi`). Good to see what survives; a hand-tuned generator copy usually reads better. |
| `compose` | skinny-b-compose.php (lib `skb*`) | DRAFT: cut rectangles from existing logos and paste/rescale onto a new canvas (`--canvas=40x13 --in=… --piece=x,y,w,h@dx,dy[:WxH] --out=…`), e.g. stack CANDY over TOP. |
| `palette` | skinny-palette.php (lib `sp*`) | **default colour downgrade**: tc file → 256 + 16 files, hue-faithful (no olive golds, distinct rainbow neighbours, darks→black); `--bands`, `--pin=#RRGGBB:93`, `--probe=#hex,…`. In generators: `spQuantise($img,$depth)` BEFORE the cell fit (cleanest), or `spEncode`. |
| `depth` | skinny-depth.php (lib `sd*`) | ROLE-aware alternative: tag cells `[g, fg, bg, 'screen']` and `sdEncode($cells,$depth,$roles)` pins backgrounds/shadows per depth while artwork keeps hue. CLI: `<tc.ansi> --depth=16 > out.ansi`. |
| `check` | skinny-b-check.php | **must pass**: \e[0m endings, equal widths, ≤40 cols, 5-15 rows, colour purity per depth, filename WxH, `--jsonl` (one line per file, tagged skinny), `--png=DIR` previews. Exit 1 on failure. |
| `sheet` | skinny-sheet.php | contact-sheet PNG of any files: `--out=S.png [--cols=3] <files/globs>` |
| `compare` | skinny-compare.php | PNG of your ORIGINAL over your skinny tc/256/16: `--slug=<slug> --out=S.png` — LOOK at it. |

Libraries also available directly: `skinny-raster-kit.php` (`sk*`: `skParse` ANSI→cells, `skRender` exact PNG renderer,
`skRaster` sub-pixel→sextant/quadrant/half, **`skWriteSkinny()` = recommended writer**: verifies + writes
`logo-<slug>-<depth>-WxH.ansi` + appends ONE logos.jsonl line) and `skinny-b-kit.php` (`skb*`: `skbLoadCells`, `skbCrop`,
`skbBlit`, `skbRescaleCells`, `skbLayoutRows` stacked layouts, `skbArgs($argv)` for --write/--jsonl/--png/--out,
`skbWriteSet()` = alternative writer that also deletes your stale other-size files).

**Recommended workflow** (both pilots converged on it):
1. Plan: `skinny.php fit` (TTF logos) or `skinny.php stroke` (stroke/tube/glow logos) at `--grid=40x<H>`. Stacking CANDY
   over TOP is usually the win — 5 letters on one 40-col row tend to turn to mush.
2. Optional draft: `skinny.php carve` your full tc logo to see what survives.
3. Copy your generator → `tools/generate-<slug>-skinny.php`, `require_once 'skinny-kit.php'`, shrink to ≤40 cols, drop
   ornaments that become noise, keep the design's identity. Graph/btop art: `sb*`.
4. Colour: `spQuantise`/`spEncode` (or `sdEncode` with roles) for 256 and 16; tune with `skinny.php palette --probe=…`.
5. Write with `skWriteSkinny()` (or `skbWriteSet()`), then `php tools/skinny.php check --jsonl --png=<scratch>
   'logo-<slug>-skinny-*.ansi'` and `php tools/skinny.php compare --slug=<slug> --out=<scratch>/sheet.png`; look; iterate.

Reference skinny generators: `generate-art-deco-gilt-skinny.php` (40×11, TTF via ttf-coverage + stf*, sp* palette,
sb* btop frame/graphs/meters, stacked) and `generate-phosphor-scope-trace-skinny.php` (40×13, ssf* stroke font +
glow field, sd* roles, skb* writer, stacked).

## Per-agent message (fill in <slug>)
"New task: make a SKINNY variant of your logo <slug>. Read prompt_kit/briefs/candy-top-skinny-logos.md and the 'Skinny
tools (FINAL set)' section of prompt_kit/briefs/candy-top-skinny-logos-resume.md. Max 40 cols, 5-15 rows (ideally
near your original height), still legibly CANDY TOP: a slightly smaller / cropped / condensed rendition of your SAME
design (tight kerning/overlap, stack CANDY over TOP, narrower masks, finer sub-cell raster: whatever suits it). Start
from a COPY of your generator: `tools/generate-<slug>-skinny.php`. Use or extend the skinny-* tools; for new/changed
tools, COPY under a new `skinny-` name, never edit a shared tool in place (other agents run concurrently). Output tc + 256 +
16 as `logo-<slug>-skinny-<tc|256|16>-<W>x<H>.ansi` plus logos.jsonl lines tagged skinny (single `>>` append). Verify with
`php tools/skinny.php check --jsonl --png=<your scratch dir> 'logo-<slug>-skinny-*.ansi'` and `php tools/skinny.php compare --slug=<slug> --out=<scratch>/sheet.png`, and LOOK at the PNGs; iterate until
legible and good-looking. No git write commands; touch nothing outside candy-top/.assets/logos/ (PNGs in a scratch dir).
Final reply: every tool created/modified (full path, what it does, usage), .ansi files with dimensions, notes."

## When all 20 report back
Collect each report, relay to the user every tool created/extended (path, purpose, usage) + every .ansi file
(dimensions), optionally consolidate overlapping skinny-* tools into one super tool. Never commit the logos dir.
