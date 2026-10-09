# candy-top logo campaign — BUILD phase instructions

Go ahead and create your ANSI logo now, following your own design notes and the
brief in prompt_kit/briefs/candy-top-logo-brief.md. All output goes in
/home/sites/sugarcraft/candy-top/.assets/logos/ (tools in its tools/ subdir).

1. Once you have all the design details worked out, you're encouraged to write a tool
   (PHP preferred, the repo is PHP 8.3) to generate the .ansi files. Write it so it can
   be reused later, swapping in new palettes and designs plus a little extra code where needed:
   palette, glyph masks/shapes, seeds and layout as data at the top; generic
   rasterise/encode, tc→256→16 downgrade and width-verify steps as functions.
2. Make the true-colour file first: `logo-<slug>-tc-<W>x<H>.ansi` (W = visible
   columns, H = rows). Every line ends with `\e[0m`, and every line has the same visible width.
3. VIEW it: `cat` it in the terminal, and also strip the SGR codes and check each
   line's display width (mb_strwidth). It has to look right, read as "CANDY TOP" /
   "candy top", and line up cleanly. Iterate until it does.
4. Then make a 256-colour version (`38;5;n`/`48;5;n` only) and then a 16-colour version
   (30-37/90-97 fg, 40-47/100-107 bg only), using the same filename format
   (`logo-<slug>-256-<W>x<H>.ansi`, `logo-<slug>-16-<W>x<H>.ansi`). View and width-check each one.
5. Append ONE line per file to `logos.jsonl` (single `>>` write per line; other agents
   share this file):
   {"filename":"...","slug":"...","colors":"tc|256|16","width":W,"height":H,"description":"...","tags":["kebab-tag",...]}
6. Animation is optional. A raw .ansi file can't carry timing, so if you animate,
   ship the static three files as the canonical logo, and add the animation as a separate
   file with the slug `<slug>-anim` (e.g. `logo-<slug>-anim-tc-<W>x<H>.ansi`, also with a jsonl
   line). It must END on the static frame (`\e[?25h` restored), and the playback it is
   designed for must take ≤ 5 seconds. If it relies on delays, put a small player in
   tools/ that plays it within 5s.
7. Don't touch anything outside candy-top/.assets/logos/, and don't git commit.

When done, your final reply MUST start with your slug on the first line, followed by
the full path and filename of every tool/script you created and used, then
the list of .ansi files produced (with dimensions), then a 1-2 line note on anything notable.

## Shared tools in candy-top/.assets/logos/tools/ (OPTIONAL suggestions)

Using these tools is optional. Use them, copy pieces out of them, or ignore them
and write your own. You are also encouraged to create NEW tools (new rasterisers,
font builders, dithering, previewers, animation helpers, etc.). Put any new tool in
tools/ with a unique filename (prefix it with your slug or give it a descriptive
name), and list EVERY tool you create with its full path/filename in your final reply,
so good work can be reviewed and passed on to later designers.

Shared kit (merged best-of from the first two designers):
- `logo-kit.php`: require this from your generator. It provides colour math (lkHex, lkMix,
  lkScale, lkShade, lkGradient, lkNoise), grids (lkHalfBlock pixel→cells, lkBlankCells,
  lkPut, lkText, lkTextRow, lkLayout glyph-mask font→mask), depth (lkEncode cells→ANSI
  at tc/256/16 with optional 16-colour pins, lkTo256 redmean nearest, lkTo16
  hue-preserving or 'nearest', lkDowngradeAnsi for tc text you built yourself),
  verify (lkVerify widths + \e[0m, lkCheckDepth code purity, lkLastFrame), lkAnim
  (frames → cursor-up animation ending on the static frame) and lkWriteLogo (verify +
  write logo-<slug>-<depth>-WxH.ansi + idempotent logos.jsonl line, so reruns don't
  duplicate). A cell is [glyph, fg rgb|null, bg rgb|null]; a wide (2-col) glyph must be
  followed by a '' placeholder cell.
- `generate-template.php`: a minimal working generator. If you use it, COPY it to
  `tools/generate-<slug>.php` (unique filename), set SLUG, and customise it for your
  design. Don't edit the template or logo-kit.php in place. If you need more, add
  helpers locally in your generator (or a `<slug>-*.php` helper file).
- `logo-play.php <anim.ansi> [delayMs]`: plays an animation, hard-capped below 5 s.
- `logo-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH]`: renders any logo
  (last frame of an animation) to PNG so you can look at it with the Read tool. Handles
  blocks, half/quadrant blocks, shades and ◢◣◤◥; other glyphs are approximate.
  Put PNGs in your scratchpad, not in the logos dir.

Reference generators from the first two designers. Read them for techniques, and copy
any pieces into your own script if useful. Don't modify them:
- `rock-candy-prism.php` + `rock-candy-prism-lib.php`: facet/bevel shading from
  mask neighbours, per-letter hue interpolation, sparkle text row, glint sweep anim,
  reflection row; plus `rock-candy-prism-play.php`, `rock-candy-prism-preview.php`.
- `licorice-allsorts.php` + `licorice-allsorts-lib.php`: layered horizontal bands,
  ring-shaped O, speckle texture, side-face shading, drop shadow, PINNED 16-colour
  map (nearest-colour turned pastels white; pins fixed it); plus
  `licorice-allsorts-play.php`, `licorice-allsorts-png.php`.
Already-built logos for comparison: ../logo-rock-candy-prism-*.ansi, ../logo-licorice-allsorts-*.ansi.

## Tools added by later designers (also optional references)
- `generate-vu-meter-matrix.php`: LED-segment (▆) matrix font, per-row heat ramp, axis labels, braille sparkline row, staggered rise/overshoot animation (built on logo-kit).
- `generate-thermal-heatmap.php`: distance-field heat glow over a pixel font, ironbow palette stops, camera HUD overlay (brackets, crosshair, colour-scale bar), warm-up animation.
- `generate-neon-tube-sign.php`: one-stroke box-drawing tube glyph font, bg-colour glow halo via distance field, hand-tuned 256/16 maps (nearest-256 made glows blotchy), flicker-on timeline anim.
- `ansi-ttf-preview.php <in.ansi> <out.png> [bg] [cellW] [cellH] [font]`: PNG preview that renders with the real DejaVu Sans Mono TTF, so box-drawing, braille, diagonals and symbols look like a terminal. Prefer it over logo-preview.php for non-block glyphs.

NOTE: the session scratchpad is shared by all designers. Put your PNG previews in your own subfolder (e.g. <scratchpad>/<slug>/) so others don't overwrite them.
- `generate-stained-glass-candy.php`: sgVoronoi() seeded Voronoi pane split of a mask with lead lines, sgColourPanes() neighbour-distinct pane colouring, sgSpill() blurred coloured light under letters; lesson: one hue per letter keeps it legible.
- `generate-gumdrop-circuit-board.php`: heavy box-drawing trace font with 45° chamfers, textured board bg, silkscreen text, edge-connector fingers, left→right power-on front anim; per-role 256/16 pins (nearest-256 turned green board grey).
- `generate-peppermint-barberpole.php`: auto-tiler that derives a rounded box-drawing outline from a mask (separate outlines for touching letters), diagonal stripe fill with ◤ wedge anti-aliasing, scrolling-stripe anim. Caveat: ◢◣◤◥ are East-Asian ambiguous width.
- `generate-spun-sugar-dither.php`: density→░▒▓█ glyph table over a distance field, noisy halo kept out of counters (legibility), --seed=N texture variants, condense-from-noise anim; 16-colour pinned by density+word.
- `braille-canvas.php`: standalone braille dot-canvas rasteriser (thick lines, polylines, ellipse arcs, stroke-font layout, per-dot layer/order/stroke tags → cells with dot/stroke counts).
- `generate-phosphor-scope-trace.php`: braille vector letters, density-based brightness, bg bloom, bezel frame, beam-trace anim; 16-colour by hue band.
- `braille-preview.php`: PNG previewer drawing all 8 braille dots + box-drawing as lines (use for braille logos).
- `generate-sprinkle-donut-glaze.php`: letters drawn as truecolour BACKGROUND cells (not block glyphs), quadrant-rounded corners, drip columns, seeded sprinkle scatter, specular streak, sprinkle-rain anim.
- `generate-chocolate-bar-emboss.php`: generic emboss() relief shader (lit top-left / shadow bottom-right / cast shadow), grain noise, foil with ▓▒░ crinkle + torn wedge edge, sheen anim; role-pinned 256/16 (nearest turned browns grey); --out=<dir> scratch writes.

USER FEEDBACK SO FAR (applies to everyone): the user rejected a design as "ugly" for being a plain blocky pixel font with weak colour, and asked for "more colors, more art, less block font… actually do a good job with it… make it look much nicer". Aim for genuinely artistic, rich, polished results, not just a pixel font with a gradient.
- `generate-art-deco-gilt.php`: thick/thin Deco font, banded metallic gilt gradient, sunburst rays, ziggurat frame, emblem; hue-constrained 256 mapping + dark-bg→black 16 rule.
- `sextant-canvas.php`: standalone 2×3 sextant rasteriser (round brush strokes, arc-length/normal/order per sub-pixel, path smoothing, arcs, 6-bit→glyph map) — smooth curves/cursive.
- `sextant-preview.php`: PNG previewer drawing sextants exactly.
- `generate-gummy-worm-script.php`: cursive centre-line paths → sextant strokes, colour along arc length, gel gloss by stroke normal, crawl-in anim; role-based 16 mapping.
- `quadrant-raster.php`: standalone 2×2 quadrant-block rasteriser (▘▝▖▗▌▐▚▞▛▜▙▟), best-two-colours per cell, transparent-aware — 2× horizontal resolution vs half-blocks.
- `generate-cellophane-twist-wrapper.php`: pill-shaped candy body with cylinder shading, cut-out cream letters, twisted cellophane bows, gloss slide anim.
- More user feedback: "add some art/styling to it that isn't part of the wording" — logos should include artistic elements/illustration beyond the letters, not just lettering.
- `generate-licorice-allsorts.php` (v2): geometric shapes (rounded boxes/capsules/ellipses) rasterised via quadrant-raster, lit top faces + extruded layered sides, drop-in bounce anim.
- `generate-topo-contour-relief.php`: vector line/arc font with distance field, smooth noise, hypsometric tints, hillshade, contour lines, map border with ticks, ∼ water marks, sea-level anim.
- `generate-cross-stitch-sampler.php`: ╳ stitch grid on linen, per-letter floss shading, ornament masks (vine border, tulip, spinning top), needle-sweep anim; hue-preserving 256 on flat render.
- `generate-risograph-misprint.php`: textCoverage() fits ANY TTF font word into the grid (real typefaces instead of pixel fonts!), halftoneHit() rotated halftone screen, printPixels() multiply-ink overprint, knockout, registration marks overlay; uses quadrant-raster.
- `vector-quadrant-raster.php`: flat-colour vector shapes as data (rect, ellipse, ring sector, polygon, diff/union, translate), supersampled majority-colour → quadrant cells (crisp edges), multiply overprint.
- `generate-bauhaus-primaries.php`: geometric-construction letters, background shapes, overprint, role-pinned 256/16, drop-in anim.
- `block-braille-ttf-preview.php`: PNG previewer drawing ▁–█/▀ blocks exactly + braille as real dots + DejaVu TTF for the rest (best all-round previewer for mixed glyph logos).

## VERSION BACKUPS (mandatory before any revision)
Before you overwrite or delete ANY of your logo files or edit your generator, back up the current version:
  mkdir -p archive/<slug>/v<N>   (N = next unused integer, starting at 1)
  cp your logo-<slug>-*.ansi, logo-<slug>-anim-*.ansi, your generator(s)/tools, and your logos.jsonl lines (grep them into archive/<slug>/v<N>/logos.jsonl) into it.
(Path is relative to candy-top/.assets/logos/.) A full snapshot of everything as of the first review round is in archive/snapshot-*/.
- `generate-ukiyoe-woodblock-wave.php`: quadrant-res woodblock scene (curling wave + foam claws, Fuji, sea, washi paper, bokashi sky, hanko seal w/ CJK double-width), carved letters; per-element 256/16 tables.
- `braille-netgraph.php`: reusable btop-style mirrored braille area graph (download up / upload down, height-graded colour like candy-top net panel) + seeded fake-traffic generator.
- `sextant-image-raster.php`: turns ANY full-colour pixel image into sextant cells (best two colours per cell, palette-aware for 256/16, edge smoothing, preview PNG) — render a scene at high-res, then rasterise.
- `generate-twilight-candyland-hills.php`: real serif TTF letters (URW Bookman Demi) via sextants, gloss/outline/shadow, painted dusk candyland scene.

## USER RULE (applies to ALL logos): backgrounds
"background in general should end at black or near black with colorful stuff throughout the image — just the edges should be closer to black or have faded to black by then."
→ Use dark/black backgrounds (no cream/pale paper), colourful content throughout, and fade/vignette to black or near-black at the outer edges.
- `ttf-coverage.php`: render ANY TTF font + string into a coverage grid at half-block/quadrant/sextant sub-cell resolution with correct cell aspect, per-letter ownership/position (for gradients); tcDilate() grow/shrink masks. Best path to pro-looking letterforms.
- `inflated-sdf-letters.php`: (logo-17/19) puffy/inflated SDF letter shading.
- `glyph-sprite.php`: place individual TTF letters anywhere with per-letter size/tilt/squeeze (graffiti / staggered lettering).

## USER RULE (applies to ALL logos): "top" means the process monitor, NOT a spinning top
"its a top as in top processes / resource monitoring not a spinning top — it's a TUI app similar to btop, so something relating to graphing in the background or off to the side, not an actual top."
→ Do NOT draw spinning-top toys/emblems. Ornament ideas for "top": CPU/mem/net graphs (braille/block area charts, sparklines, bar meters, gauges), process-list rows, btop-style panel frames, heat ramps.
- `mixed-glyph-preview.php`: PNG previewer for mixed-glyph logos (exact sextants/blocks, ░▒▓ stipple, braille dots, DejaVu for the rest).
