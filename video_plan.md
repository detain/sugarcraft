# sugar-reel — terminal video player (mp4 → ascii/ansi/sixel) build plan

> **Deliverable note:** On execution, the **first action** is to copy this plan verbatim to
> `/home/sites/sugarcraft/video_plan.md` (repo root) so it lives with the code and every
> spawned agent can read it. Plan-mode rules block writing there now; do it as step 0a.

## Context

The user wants a terminal **video player** — play `mp4`s (and other formats) and convert them
*on the fly* to ASCII / ANSI / truecolor half-block / sixel / kitty output, like `mpv -vo tct`,
`tplay`, `video-to-ascii`, and `glyph`.

Exploration confirmed the monorepo already has **most of the rendering stack**, but **nothing
decodes video or syncs audio**:

| Need | Already exists — reuse | Key API (file:line) |
|---|---|---|
| Image → terminal (sixel/kitty/iterm2/half-block/quarter-block) | **candy-mosaic** | `Renderer` iface `candy-mosaic/src/Renderer/Renderer.php:16`; `PixelGrid::fromGd()` `:39`; `Mosaic::halfBlock()/::sixel()` `:172/:191` |
| Downscale frames (nearest / area-average) | **candy-flip** | `Downsampler::downsample(\GdImage,$w,$h,$mode)` `candy-flip/src/Downsampler.php:22` |
| Density/ASCII ramp render + adaptive sizing | **candy-flip** | `Renderer::render(Frame,$preset)` `candy-flip/src/Renderer.php:92`, presets `PRESET_SOLID`/`PRESET_DENSITY` |
| Floyd-Steinberg dither | **candy-flip** | `Dither\FloydSteinberg::dither(\GdImage,$palette)` `:27` |
| Playback Model (play/pause/index/tick) skeleton | **candy-flip** | `Player` Model `candy-flip/src/Player.php:26` |
| RGB → 256/16-color + truecolor SGR | **candy-palette** | `Color::toAnsi256Index()` `:97`, `toAnsiForeground()/Background()` `:132/:140` |
| Terminal capability probe (truecolor/256/sixel/kitty) | **candy-palette** + **candy-mosaic** | `Probe::colorProfile()` `candy-palette/src/Probe.php:31`; `Mosaic::probe()` `:56`, `::diagnose()` `:124` |
| TEA runtime, frame pacing, subprocess | **candy-core** | `Model` `src/Model.php:26`; `Cmd::tick()` `:120`, `Cmd::every()` `:87`, `Cmd::exec()` `:193`, `Cmd::quit()` `:21`; `Program::run()` `src/Program.php:163` |
| Key controls | **candy-core** | `KeyMsg{type:KeyType,$rune,$ctrl,$alt,$shift}` `candy-core/src/Msg/KeyMsg.php:21` |
| Cell grid + minimal diff repaint | **candy-buffer** | `Buffer`/`Cell`, `Diff/DiffEncoder` |
| Golden-file / scripted-input tests | **candy-testing** | `Assertions::assertGoldenAnsi()`, `ProgramSimulator`, `ScriptedInput` |

**Decisions locked with the user:**
- **Name:** `sugar-reel` → pkg `sugarcraft/sugar-reel` → namespace `SugarCraft\Reel\`. Sits beside
  `candy-flip` (GIF) and `candy-mosaic` (images) as the video member of the family.
- **Decode:** shell to the **ffmpeg binary** via `proc_open` (one pipe, raw `rgb24` frames
  pre-scaled + fps-decimated by ffmpeg) — **with a pure-PHP GIF fallback** through `candy-flip`'s
  `Decoder` when ffmpeg is absent. ffmpeg/ffprobe presence is probed and degraded gracefully.
- **Audio:** **in v1** — spawn `ffplay`/`mpv` (no-video) as a separate subprocess at t0; pace video
  off a wall clock with frame-skip resync.
- **Export to file:** **deferred** to a later optional step.

### Outcome
A new `sugar-reel` lib: `php examples/play.php video.mp4` plays the video in the terminal with
auto-detected best rendering mode, audio in sync, and live controls (space/seek/speed/mode/quit) —
reusing candy-mosaic/candy-flip/candy-palette/candy-core throughout rather than reinventing them.

---

## Architecture (target `sugar-reel/src/`)

```
SugarCraft\Reel\
  Reel.php              Facade/entry: Reel::open($path)->play() ; mode/size/fps builders (immutable, ::new())
  Source/
    VideoSource.php     Value object: path + probed metadata (w,h,duration,fps,hasAudio)
    Probe.php           Locate ffmpeg/ffprobe/ffplay (which via escaped `command -v`); ffprobe -> metadata JSON
  Decode/
    Decoder.php         interface: open(grid w,h,fps): iterable<RgbFrame> ; close()
    FfmpegDecoder.php   proc_open ffmpeg pipe, read W*H*3 byte chunks -> RgbFrame
    GifDecoder.php       fallback: wraps candy-flip Decoder/Downsampler -> RgbFrame
    RgbFrame.php        readonly: bytes (rgb24), w, h  (+ ->toGd() bridge for mosaic renderers)
  Render/
    Mode.php            enum: Ascii, Ansi256, TrueColor, HalfBlock, Braille, Sixel, Kitty, Iterm2
    FrameRenderer.php   interface: render(RgbFrame): string
    LumaRamp.php        256-entry brightness->char LUT (precomputed); named ramps (CHARS1.. ) from research
    AsciiRenderer.php   grayscale/256/truecolor char ramp (reuses candy-palette Color)
    HalfBlockRenderer.php  delegates to candy-mosaic HalfBlockRenderer via RgbFrame->ImageSource
    GraphicsRenderer.php   delegates to candy-mosaic sixel()/kitty()/iterm2() renderers
    RendererFactory.php from(Mode,opts) ; auto(Probe) picks best supported mode
  Player.php            TEA Model: state(decoder iterator, frame, mode, paused, speed, clock, audioPid)
  AudioPlayer.php       spawn ffplay/mpv -nodisp/--no-video via Cmd::exec ; stop()
  Sync.php              wall-clock pacing: target_frame = floor(elapsed*fps*speed); skip/repeat to track
  Msg/                  FrameMsg, TickMsg (player-specific messages)
  Lang.php              i18n wrapper over SugarCraft\Core\I18n\T (mirror candy-pty/src/Lang.php)
```

**Decode→render→pace pipeline** (the shared shape from all 3 reference repos):
`ffmpeg(decode+scale+fps) → RgbFrame → FrameRenderer(map to cells) → emit → Sync(pace to fps, skip if behind) → [audio = master wall clock]`.

### Algorithm notes (harvested from tplay / glyph / video-to-ascii)
- **ffmpeg pipe** (one frame = exactly `W*H*3` bytes; ffmpeg does decode+downscale+decimation so PHP never decodes):
  `ffmpeg -hide_banner -loglevel error -i IN -f rawvideo -pix_fmt rgb24 -vf "fps=FPS,scale=W:H:flags=bilinear" -`
  Build args as an **array** for `proc_open`/`Cmd::exec` (no shell); if a string is ever needed, every field via `escapeshellarg()`.
- **Luminance:** BT.709 `0.2126R+0.7152G+0.0722B` (float) or integer `(77R+150G+29B)>>8`. Precompute a
  **256-entry char LUT** (`LumaRamp`) so the hot loop is an index, not arithmetic (tplay's trick).
  `Color::luminance()` in candy-palette is `private` — compute luma locally.
- **Half-block (default mode):** read **2 source rows per cell**, emit
  `\x1b[38;2;{tr};{tg};{tb};48;2;{br};{bg};{bb}m▀` — top px = FG, bottom px = BG ⇒ 2× vertical res.
  Reuse candy-mosaic's `HalfBlockRenderer` rather than hand-rolling.
- **Aspect:** terminal cells are ~2:1 tall. Plain ASCII → output H = `rows`, W = `cols`; half-block →
  pixel H = `2*rows`. Let ffmpeg `scale` keep aspect (`scale=W:-1`), then pad/crop to grid.
- **256-color fallback:** `Color::toAnsi256Index()` (6×6×6 cube + grey ramp) for non-truecolor TTYs.
- **Delta/dedup emit** (biggest perf/flicker win): cache previous frame's per-cell `(char,fg,bg)`;
  emit cursor-move + SGR only for changed cells; suppress redundant SGR when adjacent cells match.
  candy-buffer's `Diff/DiffEncoder` already does cell-diff → reuse it for the buffer-backed modes.
- **Pacing/sync:** audio subprocess is the master clock; each tick compute
  `target = floor(wall_elapsed * fps * speed)`; if behind by >skip_limit, `fread`-and-discard frames;
  if ahead, hold. Never accumulate lag.
- **Optional polish (only if cheap):** glyph's Sobel-orientation edge glyphs (`| - / \`), Bayer/Floyd
  dithering for the 256-color path (candy-flip's `FloydSteinberg` is reusable), Braille 2×4 via
  sugar-charts `BrailleGrid`.

---

## Per-step agent pipeline (run for EVERY numbered step below)

Each step is one PR-sized chunk. **Spawn a fresh agent for each sub-step, one at a time** (never
concurrent — CLAUDE.md: concurrent writes to shared files collide). The supervisor stays lean and
only orchestrates + relays results.

1. **Implementer agent** — implements exactly that step's scope against the APIs in this plan.
   Reuse existing libs (cite file:line); follow conventions (`declare(strict_types=1)`, PSR-12/PSR-4,
   `final`, immutable `with*()` via `mutate()`, bare accessors, `::new()`, `Mirrors charmbracelet/...`
   only where an upstream exists — here cite tplay/glyph/video-to-ascii instead). Run
   `composer update` in the lib first (stale vendor → false failures).
2. **Reviewer agent** — reviews ONLY this step's diff for correctness, convention adherence,
   security (every external-CLI arg escaped/arg-array; no shell injection from `$path`), reuse
   (did it reinvent something candy-mosaic/flip/palette already does?). Returns a findings list with
   severities, or "CLEAN".
3. **Fixer agent** — applies the reviewer's findings. **Loop reviewer ↔ fixer until the reviewer
   returns CLEAN** (cap 4 rounds; if still not clean, surface to the user).
4. **Tester agent** — writes/extends PHPUnit 10 tests for this step and runs
   `vendor/bin/phpunit` from the lib root until green. Snapshot (raw SGR bytes), behaviour
   (`update()` with scripted `KeyMsg`/`TickMsg`), coercion (clamp/no-op), immutability checks.
   Use `candy-testing` `Assertions::assertGoldenAnsi()` + `ProgramSimulator`/`ScriptedInput`.
   For ffmpeg/audio code, gate live tests on binary presence (`Probe`) and skip when absent — keep
   the decoder pure/unit-testable by feeding canned `rgb24` byte buffers (no ffmpeg in unit tests).
   **PHPUnit hang guard** (PTY/subprocess can hang): spawn a backgrounded `pkill` watchdog, don't
   rely on `timeout`.
5. **Documenter agent** — updates `README.md`, `CALIBER_LEARNINGS.md`, doc-comments, and (where the
   step touches them) `docs/lib/sugar-reel.html`, `MATCHUPS.md`, `PROJECT_NAMES.md`.
6. **Ship agent** — the ship-as-you-go cadence (the `ship-pr` skill encodes this):
   ```sh
   git checkout -b ai/sugar-reel-<short>
   # stage only this step's files
   git commit            # author Joe Huss <detain@interserver.net>
   git push -u origin ai/sugar-reel-<short>
   unset GITHUB_TOKEN && gh pr create --title "sugar-reel: <step summary>" --body "...## Test plan: N tests"
   unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only      # leave local on master, ready for next step
   ```
   **Always `unset GITHUB_TOKEN` immediately before every `gh` call.** Leave the working tree on a
   clean `master` at the end of every step.

**Caliber:** this machine skips Caliber — do NOT run `caliber refresh`; if a hook auto-stages
Caliber-managed files, unstage them before committing.

**Verification gate before each Ship:** `vendor/bin/phpunit` green for `sugar-reel` AND for any
foundation lib whose path-repo wiring changed; `php tools/check-path-repos.php` reports closed.

---

## Steps (each = one PR, each runs the full pipeline above)

### Step 0 — Scaffold `sugar-reel`
Use the **scaffold-library** skill. Creates `sugar-reel/{composer.json,phpunit.xml,README.md,
CALIBER_LEARNINGS.md,src/Reel.php,tests/}`, plus root `composer.json` require+repositories,
`MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md` table, `docs/index.html` tile,
`docs/lib/sugar-reel.html`, `.github/workflows/vhs.yml` `all=(...)` array, `codecov.yml`.
- composer.json: PHP `^8.3`, `minimum-stability:dev`, `prefer-stable:true`, deps
  `candy-core,candy-buffer,candy-sprinkles,candy-palette,candy-input,candy-async,candy-ansi,
  candy-mosaic,candy-flip` (+ `candy-testing` dev) with **full transitive path-repo closure** —
  copy the block shape from `sugar-charts/composer.json`; verify with
  `php tools/check-path-repos.php --fix` (use **path-repo-closure** skill).
- `MATCHUPS.md` row (Apps section) and `PROJECT_NAMES.md` entry for `SugarReel` — mirror existing
  row format. No single upstream (cite tplay/glyph/video-to-ascii in README "prior art").
- `src/Reel.php` is a stub facade with `::new()`/`::open()` returning a not-yet-playable instance so
  the lib installs + tests green from day one.
- **Verify:** `cd sugar-reel && composer install && vendor/bin/phpunit` green; check-path-repos closed.

### Step 1 — Probe + VideoSource + metadata
`Source/Probe.php` (locate ffmpeg/ffprobe/ffplay; reuse the `which` pattern from
`candy-core/src/Util/Editor.php:154`), `Source/VideoSource.php` (ffprobe JSON → w/h/duration/fps/
hasAudio). Graceful "binary missing" path. Unit-test with a fake `command -v` resolver + canned
ffprobe JSON fixture (no real ffmpeg in CI).

### Step 2 — RgbFrame + FfmpegDecoder + GifDecoder fallback
`Decode/RgbFrame.php`, `Decode/Decoder.php` (iface), `Decode/FfmpegDecoder.php` (proc_open, read
exact `W*H*3` chunks; arg **array**, no shell), `Decode/GifDecoder.php` (wrap candy-flip `Decoder`
+ `Downsampler` → `RgbFrame`). Auto-select ffmpeg vs GIF by extension/availability.
Unit-test the chunk-framing logic by piping a canned rawvideo byte buffer through a stream (no
ffmpeg); live ffmpeg test skipped when absent.

### Step 3 — Renderers (Mode enum + LumaRamp + Ascii/HalfBlock)
`Render/Mode.php`, `Render/LumaRamp.php` (256-entry LUT, named ramps), `Render/FrameRenderer.php`,
`Render/AsciiRenderer.php` (grayscale/256 via `Color::toAnsi256Index()`/truecolor via
`toAnsiForeground()`), `Render/HalfBlockRenderer.php` (bridge `RgbFrame`→`ImageSource`→ candy-mosaic
`HalfBlockRenderer`), `Render/RendererFactory.php` (`auto()` picks best supported via
`Mosaic::probe()`/`Probe::colorProfile()`). Snapshot-test each mode's exact SGR bytes against a
3×3 synthetic frame golden.

### Step 4 — Player Model + controls + delta repaint
`Player.php` (TEA `Model`; mirror `candy-flip/src/Player.php` structure but pull frames from a
`Decoder` iterator), player `Msg/` types, `Sync.php` (wall-clock pacing/skip). `init()` →
`Cmd::tick()`; `update()` handles `TickMsg` (advance/skip to target frame), `KeyMsg`
(space=pause, ←/→ seek, `[`/`]` speed, `0-9`/`m` mode switch, `q`/Esc quit via `Cmd::quit()`);
`view()` renders current frame (delta-repaint via candy-buffer `DiffEncoder` for buffer-backed modes).
Behaviour-test with `ProgramSimulator` + `ScriptedInput` (no real video — inject canned frames).

### Step 5 — Audio sync
`AudioPlayer.php` (spawn `ffplay -nodisp -autoexit` or `mpv --no-video` via `Cmd::exec` arg-array at
t0; `stop()` on quit), wire `Sync.php` to track the audio wall clock with frame-skip resync. Skip
silently when no audio stream or no player binary. Test: `Sync` math unit-tested; audio spawn gated
on binary presence.

### Step 6 — Graphics backends (sixel / kitty / iterm2) + auto-mode polish
`Render/GraphicsRenderer.php` delegating to `Mosaic::sixel()/::kitty()/::iterm2()`; extend
`RendererFactory::auto()` to prefer sixel/kitty when `Mosaic::diagnose()` reports support, else
half-block, else 256, else ascii. Snapshot-test the protocol envelope bytes.

### Step 7 — Examples, VHS, docs polish, end-to-end demo
`examples/play.php` (+ a tiny sample/synthetic clip or generated test pattern so CI needs no asset),
`.vhs/*.tape` (TokyoNight theme, quoted values), flesh out `README.md` (install, modes table,
controls, prior-art credit), `CALIBER_LEARNINGS.md`, `docs/lib/sugar-reel.html`. Add `sugar-reel`
to `vhs.yml` `all=(...)`. Final full `vendor/bin/phpunit` + a manual `examples/play.php` smoke run.

> **Export (deferred):** a later optional Step 8 would add `Export/` — self-contained replayable
> ANSI/bash script first (video-to-ascii style), then GIF/mp4 via an ffmpeg **encode** pipe
> (glyph style: `palettegen`+`paletteuse` for GIF). Not in v1 scope.

---

## Verification (end-to-end)
- Per lib: `cd sugar-reel && composer update && vendor/bin/phpunit` green; repeat for any touched
  foundation lib. `php tools/check-path-repos.php` reports closure closed.
- Capability matrix sanity: run `examples/play.php` under a truecolor TTY (half-block), force
  `NO_COLOR`/256 (`Color::toAnsi256Index` path), and a sixel-capable terminal — confirm each mode
  renders and controls (space/seek/speed/mode/quit) respond.
- ffmpeg-absent path: rename/hide ffmpeg → GIF fallback still plays a `.gif`; clear message for mp4.
- Audio: play a clip with sound, confirm A/V stay roughly in sync over ≥30 s (frame-skip working).
- CI: confirm green on the **master push** (not the PR run — force-all PRs go red on detached-HEAD
  `dev-<sha>`), and that `vhs.yml`/`ci.yml` discover `sugar-reel`.
