# sugar-reel — remediation plan **JR2** (finish *everything*: audit defects + all deferred features)

> Companion to [`video_plan.md`](video_plan.md) (original build, completed), [`video_update.md`](video_update.md)
> (21-item post-audit fix, Phases 1–6 merged), and [`video_update_jr.md`](video_update_jr.md) (J1–J3 merged
> as #1014/#1015 and predecessors). This **JR2 plan** is the deliverable for the 2026-06-03 deep audit that
> (a) found a **user-visible regression and two latent crashes the JR phases shipped**, plus a tail of
> silent-failure / perf / doc defects, and (b) inventories **every feature the original `video_plan.md`
> envisioned but that was never built** — Braille mode, dithering, edge-orientation glyphs, audio
> time-stretch, and file Export. The mandate for this plan is explicit: **every missing feature and defect
> is fully finished and implemented — nothing deferred.**
>
> **First action on execution:** none — this file lives at repo root so every spawned agent can read it.

---

## 0. Source-of-truth facts (verified during the JR2 audit — do NOT re-derive)

Confirmed by reading current `master` (HEAD `1707c7d3`) and by direct reproduction. Build on these.

1. **Baseline:** `cd sugar-reel && vendor/bin/phpunit` = **211 passing / 1832 assertions / 4 skipped**, **0
   warnings** (`failOnWarning="true"` — the suite tolerates zero deprecations). The 4 skips are
   binary-*present* gates (tests that skip *because* ffmpeg/ffprobe/ffplay ARE installed). `git log --oneline
   -- sugar-reel/` shows all 10 remediation commits landed: `7e032a25`(P1) … `1707c7d3`(J3).
2. **Everything in F1–F21 + B1/B2/B3 is genuinely fixed and must NOT be touched:** videoTime pacing
   accumulator (no retroactive speed rescale), `rebuildDecoderAt()` closes the old decoder (F21), `/dev/null`
   proc sinks (F7/F8), arg-array `proc_open` with `$path` as a discrete element everywhere (no shell injection
   — the core security property; it holds), `Mode::rowsPerCell()` honored by both decoders (F5), `autoMode()`
   wired, SGR coalescing (F15). `grep -rn 'BT.709' src` → 0; `grep -rn 'charmbracelet/sugar-reel' src` → 0.
3. **`examples/play.php` no-arg path is BROKEN (verified).** [`play.php:79`](sugar-reel/examples/play.php)
   `if ($arg1 === '--help' || $arg1 === '-h' || $arg1 === '') { help(); exit(1); }` — the **empty-arg case
   exits to help**, so `php examples/play.php` never reaches the synthetic demo. [`play.php:84`](sugar-reel/examples/play.php)
   `$pathArg = $arg1 === '' ? 'synthetic' : $arg1;` is **dead code**. This contradicts
   [`README.md:50`](sugar-reel/README.md), the [`play.php:9`](sugar-reel/examples/play.php) docblock, and
   `video_update_jr.md` J1.3. **The test masks it:** [`SyntheticTest.php:143`](sugar-reel/tests/SyntheticTest.php)
   asserts the no-arg stderr contains `'synthetic test pattern'` — a phrase that *also appears in the help
   text*, so it passes for the wrong reason.
4. **`DecoderFactory` crashes on the documented degradation path (verified).**
   [`DecoderFactory.php:43`](sugar-reel/src/Decode/DecoderFactory.php) calls `$decoder->open()`; for a
   non-`.gif` source with ffmpeg absent it falls back to `GifDecoder`, whose `open()`
   ([`GifDecoder.php:51`](sugar-reel/src/Decode/GifDecoder.php)) calls candy-flip
   `Decoder::decode()` which **throws `\RuntimeException`** on bad magic bytes / missing gd / missing file
   ([`candy-flip/src/Decoder.php:31-41`](candy-flip/src/Decoder.php)). [`Player::open()`](sugar-reel/src/Player.php)
   (`:122`) wraps decoder creation in **no try/catch** → uncaught fatal, opposite of the "fail gracefully" /
   "clear error message" promises in the docblocks.
5. **The synthetic GIF is self-inconsistent (verified).** `Synthetic::generate()` writes an `imagegif()`
   stream (header `GIF87a`) then splices a `NETSCAPE2.0` loop app-extension + per-frame Graphic-Control-
   Extensions (`0x21 0xF9`), **both GIF89a-only**. Reproduced: `header=GIF87a has_NETSCAPE=yes has_GCE=yes`.
   The class docblock claims "valid GIF89a." candy-flip accepts both 87a/89a so playback works, but the file
   is non-conformant and the loop/delay blocks are illegal in an 87a stream.
6. **`Mode` enum has exactly 7 cases** — `Ascii, Ansi256, TrueColor, HalfBlock, Sixel, Kitty, Iterm2`
   ([`Mode.php:15-33`](sugar-reel/src/Render/Mode.php)). **`Braille` is absent** — it was listed in the
   `video_plan.md` Mode-enum spec (line 61) but **never implemented**. `rowsPerCell()` returns 2 for HalfBlock,
   1 otherwise; `colsPerCell()` **always returns 1**. The decoders scale height by `rowsPerCell` but **do NOT
   scale width by `colsPerCell`** ([`FfmpegDecoder.php:51`](sugar-reel/src/Decode/FfmpegDecoder.php),
   [`GifDecoder.php:51`](sugar-reel/src/Decode/GifDecoder.php) use only `cellsW`). Braille (2×4 sub-cells)
   needs both axes — see B-G1.
7. **No `Export/` directory exists.** Export was the deferred Step 8 of `video_plan.md`. Dithering
   (Floyd-Steinberg via candy-flip), Sobel edge-orientation glyphs (glyph-style), and audio time-stretch
   (atempo) were all "optional polish / out of scope" in prior plans and are **unimplemented**. candy-flip
   *does* ship `Dither\FloydSteinberg` ([`candy-flip/src/Dither/FloydSteinberg.php`](candy-flip/src/Dither/FloydSteinberg.php)).
8. **`Player` is `final` with an 18-field private ctor** ([`Player.php:73-92`](sugar-reel/src/Player.php)),
   ending `…, ended, loop, ramp='standard', audioFactory=null`. Every `new self(...)` site
   (`open`~125, `openForTest`~169, `withSeek` backward/forward, `withNewFrame`, `mutate`) AND
   `PlayerTest::createPlayerWithOverrides()`'s positional `$values` array must stay in lockstep. **Append any
   new ctor field at the END** to minimize churn; update the helper in the SAME commit.
9. **AudioPlayer uses `proc_terminate($h, SIGSTOP/SIGCONT)` for pause/resume**
   ([`AudioPlayer.php:125,138`](sugar-reel/src/AudioPlayer.php)) and `@shell_exec` in `findMpv()` (`:212`).
   There is **no `__destruct`/shutdown hook** → orphaned ffplay/mpv if PHP dies between `start()` and `stop()`.
10. **`candy-palette` `Capability` has no `KittyGraphics`** — Kitty image mode is correctly gated on
    `KittyKeyboard` (documented caveat, do not "fix"). `sugar-charts` provides a `BrailleGrid` primitive
    (`video_plan.md` line 99) — verify its exact API before using for Braille (B-G1).

---

## 1. Conventions, per-step pipeline, ship cadence  (identical to the parent plans)

Per **step**: (1) **Implementer** — `composer update` in the lib if a local failure smells like stale vendor;
implement exactly that step; conventions: `declare(strict_types=1)`, PSR-12/PSR-4, `final` unless a contract,
immutable `with*()`/`mutate()`, bare accessors, `::new()`; cite prior art (tplay/glyph/video-to-ascii), never
`Mirrors charmbracelet/...` where no upstream exists. **No silent failures** — throw `\RuntimeException`/
`\InvalidArgumentException` rather than returning null for bad input (CONTRIBUTING). (2) **Reviewer** — review
ONLY that step's diff (correctness, conventions, security: every external-CLI arg via **arg-array** to
`proc_open`, never a shell string; reuse existing candy-* libs). (3) **Fixer** — loop reviewer↔fixer to
`CLEAN` (cap 4). (4) **Tester** — **regression-first**: write the test that FAILS on current code (capture the
failure), then make it green; for subprocess code use a backgrounded `pkill` watchdog (`timeout` does NOT kill
`proc_open`/PTY hangs). (5) **Documenter** — README / CALIBER_LEARNINGS / doc-comments /
`docs/lib/sugar-reel.html` as touched. (6) **Ship** — ship-as-you-go (`ship-pr` skill):
`git checkout -b ai/sugar-reel-jr2-<short>` → stage ONLY touched `sugar-reel/` (+ `docs/lib/sugar-reel.html`,
+ root `composer.json` if a dep was added) → commit (author `Joe Huss <detain@interserver.net>`, end body with
the `Co-Authored-By: Claude …` trailer) → push → `unset GITHUB_TOKEN && gh pr create` →
`unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`.

**This machine SKIPS Caliber** — never run `caliber refresh`; if a hook auto-stages caliber-managed files,
unstage them before committing. **`unset GITHUB_TOKEN` immediately before every `gh` call.**

**Bundling:** one **phase = one PR** (2–4 related items). The untracked `video_*.md` planning docs and any
`*_crush*`/`use_libs.txt` files are unrelated — **never stage them**.

**Verification gate before every Ship:** `cd sugar-reel && vendor/bin/phpunit` green (only the 4 binary-absent
skips; no warnings); `php tools/check-path-repos.php` reports **closure clean** if any path-repo wiring
changed; the phase's **Definition of Done** all ticked.

**CI note** (root memory): the run-level conclusion is chronically `failure` on every master push
(empty-OS-matrix quirk). **Do not chase it / do not loop-poll CI.** Spot-check only the lib check-runs:
`unset GITHUB_TOKEN && gh api repos/detain/sugarcraft/commits/<merge-sha>/check-runs --jq '.check_runs[]|select(.name|test("sugar-reel|Path-repo"))|"\(.conclusion // .status)\t\(.name)"'`
→ expect `success` for `Test PHP 8.3 · sugar-reel`, `Test PHP 8.4 · sugar-reel`, `Coverage · sugar-reel`,
`Path-repo closure`, `render (sugar-reel)`.

---

## 2. Traceability — every audit finding & missing feature → JR2 phase

### Part A — Defects (finish the existing implementation correctly)

| # | Finding | Sev | Status | JR2 step |
|---|---|---|---|---|
| C1 | No-arg `play.php` shows help & exits, never plays synthetic; dead code `:84`; masking test | **Critical** | ✅ verified | K1.1 |
| C2 | Non-GIF source + ffmpeg absent → uncaught `\RuntimeException` (crash); "fail gracefully" docs false | **Critical** | ✅ verified | K1.2 |
| C3 | Synthetic GIF declares `GIF87a` header but embeds 89a-only NETSCAPE/GCE blocks | **Critical** | ✅ verified | K1.3 |
| H1 | Insecure synthetic temp file: predictable world-writable `/tmp` path, no O_EXCL/chmod/cleanup (CWE-377/59) | High | confirmed | K2.1 |
| H2 | Unbounded per-tick frame-skip; `Sync`'s "2-frame limit" is a trigger never enforced as a cap → stall feedback | High | confirmed | K2.2 |
| M1 | `AudioPlayer` has no `__destruct`/shutdown → orphaned ffplay/mpv on hard exit | Med | confirmed | K2.3 |
| M2 | `FfmpegDecoder::close()` captures `$exitCode` then ignores it → ffmpeg error indistinguishable from clean EOF (silent failure) | Med | confirmed | K2.4 |
| M3 | `RgbFrame::toGd()` boundary guard off-by-one (`+2 >= len` should be `+3 > len`) → `ord('')` warning risk | Med | confirmed | K2.5 |
| M4 | Graphics renderers do per-frame `toGd → PNG-encode → Mosaic PNG-decode` in the live hot path | Med | confirmed | K4.1 |
| M5 | Half-block parity test asserts `▀` glyph **count** only, not per-cell fg/bg (G1 anti-pattern; drift unguarded) | Med | confirmed | K3.1 |
| M6 | Audio-seek test omits the "start IS called when playing" half of the start-iff-playing DoD | Med | confirmed | K3.2 |
| M7 | `VideoSource` reads `duration` only from the video stream; ignores `format.duration` → `totalFrames=0` → percentage-seek dead for MKV/WebM/many MP4 | Med | confirmed | K3.3 |
| M8 | `proc_terminate($h, SIGSTOP/SIGCONT)` misuses the API for pause/resume | Med | confirmed | K3.4 |
| L1 | `LumaRamp::CHARS3` dense ramp: `\`` / `\\` inside a single-quoted string → stray backslash glyph, length ≠ documented 70 | Low | confirmed | K4.2 |
| L2 | `GraphicsRenderer::render(f, $mode)` ignores its `$mode` arg (switches on ctor mode) — inconsistent contract | Low | confirmed | K4.3 |
| L3 | `FfmpegDecoder::next()` redundant dead branches; unreachable `break` (`:124`) | Low | confirmed | K4.4 |
| L4 | `HalfBlockRenderer` `(int)round($h/2)` vs the 2-rows-per-cell contract; use `intdiv` | Low | confirmed | K4.5 |
| L5 | Unused `use SugarCraft\Mosaic\Mosaic;` import in `HalfBlockRenderer` | Low | confirmed | K4.6 |
| L6 | Synthetic frame 0 has no GCE/delay block while frames 1..N do → first-frame timing asymmetry | Low | confirmed | K4.7 |
| L7 | `Synthetic::generate()` ignores `file_put_contents` failure (silent failure) | Low | confirmed | K4.8 |
| L8 | `VideoSource` `fps` from `r_frame_rate` with no `avg_frame_rate` fallback (VFR overshoot) | Low | confirmed | K3.3 |
| L9 | `Player` `[ended]`/loading status lines not width-clamped to `cellsW` → over-wide line risk (TUI invariant) | Low | confirmed | K4.9 |
| L10 | Magic numbers in `Player` controls + luma formula duplicated 3× (`Player`/`AsciiRenderer`/`LumaRamp`) | Low | confirmed | K4.10 |
| L11 | AudioPlayer 1.0×-only limitation absent from class docblock; `findMpv()` uses `@shell_exec` while rest is arg-array | Low | confirmed | K4.11 + K8 |
| L12 | `FfmpegDecoder::$process` PHPDoc references a non-existent `\Process` type | Low | confirmed | K4.12 |

### Part B — Missing features (build to the original `video_plan.md` vision — fully, not "optional")

| # | Feature | Origin | Status | JR2 phase |
|---|---|---|---|---|
| N1 | **Braille mode** (2×4 sub-cell, 4× density) | `video_plan.md` Mode enum (spec'd, never built) | ❌ missing | **K5** |
| N2 | **Dithering** (Floyd-Steinberg / Bayer) for the Ansi256 path | `video_plan.md` "optional polish"; candy-flip ships the algo | ❌ missing | **K6** |
| N3 | **Edge-orientation glyphs** (Sobel `│ ─ ╱ ╲`) for ASCII | `video_plan.md` glyph-style polish | ❌ missing | **K7** |
| N4 | **Audio time-stretch** for non-1.0× speed (ffmpeg `atempo`) — removes the documented A/V-diverge limitation | `video_update.md` §3.3 "out of scope" | ❌ missing | **K8** |
| N5 | **Export** — self-replay ANSI/bash script + GIF/mp4 via ffmpeg encode | `video_plan.md` deferred Step 8 | ❌ missing | **K9** |

---

## 3. Cross-cutting building blocks (introduce once, reuse)

### B-G1 — `Mode::colsPerCell()` becomes real + decoders honor BOTH axes *(Phase K5)*
Braille is the first mode with `colsPerCell ≠ 1`. Today both decoders scale only height. Generalize the
geometry contract so any sub-cell mode works:
```php
// Mode.php — exhaustive match, no silent default
public function rowsPerCell(): int {
    return match ($this) {
        self::HalfBlock => 2,
        self::Braille   => 4,
        default         => 1,
    };
}
public function colsPerCell(): int {
    return match ($this) {
        self::Braille => 2,
        default       => 1,
    };
}
```
- `FfmpegDecoder::open()` / `GifDecoder::open()` → `frameW = cellsW * $mode->colsPerCell()`,
  `frameH = cellsH * $mode->rowsPerCell()` (the `?? 2`/`?? 1` null-defaults stay).
- `Player::frameToBuffer()` / `detectCellDimensions()` → divide width by `colsPerCell()` too.
- **Regression test:** with the mode-aware fake (B3 from JR), assert a Braille frame decodes at
  `cellsW*2 × cellsH*4` and renders back to exactly `cellsW × cellsH` cells.

### B-G2 — `RawImageSource` fast path (skip PNG round-trip) *(Phase K4.1, reused by K5/K9)*
The Mosaic-delegating renderers encode every frame to PNG then let Mosaic decode it. Add a raw bridge so the
live graphics/sixel/kitty path (and Braille/Export) avoid per-frame PNG:
- If `candy-mosaic`'s `ImageSource` exposes a GD or raw-pixel constructor, prefer it; else add a thin
  `RgbFrame::toPixelGrid()` returning the candy-mosaic `PixelGrid` directly (`PixelGrid::fromGd()` exists per
  `video_plan.md` line 18). One conversion, no PNG.
- Benchmark note in CALIBER_LEARNINGS: per-frame PNG was the graphics-mode fps bottleneck.

### B-G3 — `Reel`/`Player` boolean+enum option threading discipline *(all feature phases)*
Each new user-facing option (`dither`, `edges`, `ramp` already done) is a `readonly` field **appended at the
END** of the `Player` ctor, threaded through `Reel` (`with*()` + accessor + `play()` passthrough), `open()`,
`openForTest()` (with a default), `mutate()`, both `withSeek()` `new self(...)`, `withNewFrame()`, and
`PlayerTest::createPlayerWithOverrides()` `$values` — **in the same commit**. Validate enum/string options in
`Reel::with*()` and throw `\InvalidArgumentException` on bad input (no silent coercion).

---

## Phase K1 — Critical correctness  *(PR: `ai/sugar-reel-jr2-critical`)*

**Why first:** one user-visible regression + two crash/conformance bugs the JR phases shipped. No new surface;
pure correctness.

### Step K1.1 — Fix no-arg dispatch + un-mask the test (C1)
- [`play.php:79`](sugar-reel/examples/play.php): branch **only** `--help`/`-h` to `help(); exit(1)`. Route
  `''` and `'synthetic'` to the synthetic source. Delete the dead `:84` ternary; set `$pathArg` directly.
- Keep `getenv('SUGAR_REEL_COLS/ROWS')` + clamps and the `withLoop` wiring.
- **Tests:** rewrite [`SyntheticTest.php:143`](sugar-reel/tests/SyntheticTest.php) `testPlayPhpNoArgsEmitsNoWarnings`
  to (a) assert **no** `Warning:`/`Undefined` on stderr AND (b) assert the **runtime** marker
  `[synthetic test pattern: /tmp/…]` is present (proving the synthetic branch ran, not help). Add a separate
  `--help` test asserting the help text and that the program does **not** start. Both run `php -d
  error_reporting=E_ALL examples/play.php …` in a subprocess (watchdog-guarded; the synthetic case must not
  block on the TUI — feed it a way to exit, e.g. a tiny `SUGAR_REEL_SELFTEST=1` env that renders one frame and
  quits, or assert on the pre-TUI stderr lines only and kill via watchdog).

### Step K1.2 — Graceful decode-failure path (C2)
- Wrap decoder creation in [`Player::open()`](sugar-reel/src/Player.php) (`:122`) — on
  `\RuntimeException`/`\Throwable` from `DecoderFactory::create()`, construct a Player in an **error state**
  (new `readonly ?string $error` field, appended per B-G3) whose `view()` renders a clear, width-clamped
  message ("cannot decode <path>: <reason>. Install ffmpeg for non-GIF video. q quit") and whose `init()`
  schedules **no tick** (no spin). `q` quits.
- Correct the false docblocks in `DecoderFactory` (`:15`) and `VideoSource` (the "degrade gracefully /
  clear error message shown" claim) so they describe the real behavior now that it's true.
- **Tests:** `openForTest`-style or a factory seam that forces `DecoderFactory::create` to throw → assert the
  Player is in error state, `view()` contains the message ≤ `cellsW` wide, `init()` returns no tick Cmd, and a
  `q` `KeyMsg` quits. Add a `DecoderFactoryTest` case: GifDecoder fallback on a non-GIF buffer surfaces a
  catchable exception (documents the contract).

### Step K1.3 — Synthetic GIF89a conformance (C3)
- In `Synthetic::generate()`: after capturing the first frame's `imagegif()` bytes, set the header version
  nibble `$gif[4] = '9';` so the stream is `GIF89a` **before** splicing the NETSCAPE2.0 + GCE blocks. Make the
  class docblock's "valid GIF89a" claim true.
- **Tests:** extend `SyntheticTest` to assert `substr($bytes,0,6) === 'GIF89a'` AND (already present) candy-flip
  decodes ≥2 frames. Add an assertion that the byte sequence `GIF87a` does **not** appear at offset 0.

**Phase K1 DoD**
- [ ] `php examples/play.php` (no args) plays the animated, looping synthetic — **no** help screen, **no** PHP
      warnings; `--help` shows help and does not start the program; the no-arg test asserts the runtime marker.
- [ ] Non-GIF source with ffmpeg hidden shows a clean width-clamped error and quits on `q` — **no fatal, no
      spin**; docblocks describe the real behavior.
- [ ] Synthetic stream header is `GIF89a`; regression tests fail on `master`, pass after.

---

## Phase K2 — Safety, resource lifecycle & silent-failure  *(PR: `ai/sugar-reel-jr2-safety`)*

### Step K2.1 — Harden the synthetic temp file (H1)
- Replace `DEFAULT_PATH` constant usage with a per-process unguessable path:
  `sys_get_temp_dir().'/sugar-reel-'.bin2hex(random_bytes(8)).'.gif'`. `chmod($path, 0600)` after a successful
  write. Keep a `DEFAULT_PATH` only as an explicit opt-in override param. Register a `register_shutdown_function`
  (or document caller-cleanup) to unlink generated temp files.
- **Tests:** two `generate()` calls return **different** paths; the file mode is `0600` (POSIX-gated); bytes
  start `GIF89a`.

### Step K2.2 — Cap per-tick frame-skip (H2)
- In [`Player::updateTick`](sugar-reel/src/Player.php) skip branch (`:279-291`): clamp the frames consumed in
  one `update()` to `max(1, min($skipCount, (int)ceil($this->fps)))` — never drain an unbounded backlog in a
  single tick; let subsequent ticks catch up. Add a named `private const MAX_SKIP_PER_TICK_FACTOR`.
- Reconcile the `Sync` docblock: state plainly that `shouldSkip` is the **trigger threshold** (>2) and the
  **caller bounds the count**. Optionally add `Sync::framesToSkip(int $current, int $target, float $fps): int`
  encapsulating the cap so the contract lives in one place; update callers + `SyncTest`.
- **Tests:** drive a tick with a large `target - frameIndex` (e.g. 1000) against a spy/fake decoder; assert
  `next()` is called at most `ceil(fps)` times in that single `update()`, and the index advances by the cap,
  not 1000.

### Step K2.3 — AudioPlayer destructor / shutdown guard (M1)
- Add `public function __destruct() { $this->stop(); }` (idempotent `stop()` already guards `is_resource`).
  Optionally `register_shutdown_function([$this,'stop'])` in `start()` for the fatal-error path; ensure no
  double-close.
- **Tests (gated on ffplay):** start a player, let it go out of scope / trigger shutdown, assert no orphan via
  `pgrep ffplay` (watchdog-guarded). Pure-unit: `__destruct` on a never-started AudioPlayer is a no-op.

### Step K2.4 — Surface ffmpeg failure instead of swallowing it (M2)
- [`FfmpegDecoder`](sugar-reel/src/Decode/FfmpegDecoder.php): track `private int $framesYielded`. In
  `close()`, if `proc_close` exit `!== 0` **and** `framesYielded === 0`, throw `\RuntimeException` with a
  message (the process produced nothing and errored — a real failure, not EOF). A non-zero exit *after* frames
  were produced (e.g. SIGTERM on quit) stays silent (expected). Optionally keep a bounded tail of stderr (the
  F7 "optional diagnostics" path) for the message.
- This composes with K1.2: `Player::open()` already catches → clean on-screen error.
- **Tests (gated on ffmpeg):** feed a deliberately-corrupt/empty input; assert the thrown failure carries a
  non-empty reason. Clean decode of a tiny generated clip still closes silently.

### Step K2.5 — Fix `RgbFrame::toGd()` boundary guard (M3)
- [`RgbFrame.php:49`](sugar-reel/src/Decode/RgbFrame.php): change `if ($offset + 2 >= $byteLen)` to
  `if ($offset + 3 > $byteLen)` so exactly-3-bytes-remaining is read and a 1-byte-short buffer can't `ord('')`
  past end (would warn under `failOnWarning`).
- **Tests:** a buffer one byte short of `w*h*3` renders without warning (last pixel clamps to black); an
  exactly-sized buffer round-trips every sample pixel via `imagecolorat()`.

**Phase K2 DoD**
- [ ] Synthetic temp file is unguessable, `0600`, cleaned up; no fixed shared path.
- [ ] Per-tick skip is bounded; a 1000-frame backlog cannot block one `update()`; Sync doc matches behavior.
- [ ] No orphaned audio process after GC/shutdown; ffmpeg hard-failure is surfaced (not swallowed); `toGd`
      boundary is exact. Regression tests fail on `master` first.

---

## Phase K3 — Test guards & probe accuracy  *(PR: `ai/sugar-reel-jr2-guards`)*

### Step K3.1 — Strengthen the half-block parity test (M5)
- Rewrite [`PlayerTest::testHalfBlockInlineMatchesMosaicRenderer`](sugar-reel/tests/PlayerTest.php) (`:1295`)
  to parse **both** outputs (inline `Player::frameToBuffer` view and `(new HalfBlockRenderer())->render()`)
  into per-cell `(glyph, fg, bg)` tuples and assert tuple-equality (same dims, same `▀` placement, same
  truecolor fg/bg per cell). Tolerate only harmless SGR ordering / trailing-reset differences. This actually
  guards the F14 drift the count-only test missed. Add a code comment in both source files pointing at the
  guard.

### Step K3.2 — Complete the audio-seek behavioral test (M6)
- Using the existing `audioFactory` seam + `SpyAudioPlayer`, add the missing case: seek while **not paused** →
  assert the new AudioPlayer's `start()` **was** called (the "start-iff-playing" DoD's other half). Keep the
  paused case (start NOT called) and the unconditional pure-math `startMs` offset test.

### Step K3.3 — Probe duration/fps accuracy (M7, L8)
- [`VideoSource::fromFfprobeJson`](sugar-reel/src/Source/VideoSource.php): `duration` falls back to
  `$data['format']['duration']` when the video stream lacks it (already requested via `-show_format`); `fps`
  prefers `avg_frame_rate` when present and sane, else `r_frame_rate` (keep the robust rational
  `parseFrameRate`). Result: `totalFrames` (→ percentage-seek) works for MKV/WebM/stream-duration-less MP4.
- **Tests:** canned ffprobe JSON fixtures — (a) stream lacks `duration`, format has it → non-zero duration;
  (b) `avg_frame_rate` differs from `r_frame_rate` → avg wins; (c) both absent → 0 (digit-seek no-op, already
  handled). Live gated test unchanged.

### Step K3.4 — Correct pause/resume signaling (M8)
- Replace `proc_terminate($h, SIGSTOP/SIGCONT)` with explicit `posix_kill(proc_get_status($h)['pid'],
  SIGSTOP/SIGCONT)` guarded on `function_exists('posix_kill')` + `defined('SIGSTOP')`; fall back to no-op (audio
  drifts but doesn't crash) when POSIX is unavailable. Comment the intent.
- **Tests (gated):** pause then `isPlaying()` semantics unchanged; resume continues; no orphan.

**Phase K3 DoD**
- [ ] Half-block parity test compares per-cell fg/bg (not just glyph count) and fails if colors diverge.
- [ ] Audio-seek test covers BOTH paused (no start) and playing (start) on seek; offset pinned by pure math.
- [ ] `totalFrames` derived from container duration when stream lacks it; `avg_frame_rate` preferred; tests
      cover all three fixtures. Pause/resume uses `posix_kill`; suite green.

---

## Phase K4 — Render perf & low-severity cleanup  *(PR: `ai/sugar-reel-jr2-cleanup`)*

Bundles the perf win (M4) with every Low. Mostly mechanical; one diff.

- **K4.1 (M4, B-G2):** add the raw-pixel bridge; route `GraphicsRenderer` (live sixel/kitty/iterm2 path) and
  `HalfBlockRenderer` through it to drop per-frame PNG encode/decode. Note the win in CALIBER_LEARNINGS.
- **K4.2 (L1):** rebuild `LumaRamp::CHARS3` as a correctly-escaped (heredoc or double-quoted) ramp; add a unit
  assertion `strlen(CHARS3) === <documented N>` so it can't silently drift; verify no stray `\`.
- **K4.3 (L2):** make `GraphicsRenderer::render(RgbFrame, Mode $mode)` honor its `$mode` argument (switch on
  the param like the other renderers) OR drop the param-vs-ctor ambiguity by removing the ctor mode; pick the
  param-driven form for interface consistency; update `GraphicsRendererTest`.
- **K4.4 (L3):** simplify `FfmpegDecoder::next()` to a single post-loop
  `return $bytesRead === $this->frameBytes ? new RgbFrame(...) : null;`; remove the unreachable `break` and
  redundant branches. Behavior identical; framing tests already cover it.
- **K4.5 (L4):** `HalfBlockRenderer` height → `intdiv($frame->h, 2)`; document the even-height contract.
- **K4.6 (L5):** remove the unused `use SugarCraft\Mosaic\Mosaic;` import.
- **K4.7 (L6):** prepend a Graphic-Control-Extension (delay) before synthetic frame 0 so all frames pace
  evenly.
- **K4.8 (L7):** `Synthetic::generate()` throws `\RuntimeException` when `file_put_contents` returns `false`.
- **K4.9 (L9):** width-clamp/truncate the `[ended]` and loading status lines (and any appended hint) to
  `cellsW` in `Player::view()`/`renderPlaceholder` so they never exceed the row (TUI invariant). Add a
  narrow-terminal `view()` test asserting max line width ≤ `cellsW`.
- **K4.10 (L10):** extract `Player` control magic numbers (seek step, speed step/bounds, clamp bounds) to
  named `private const`s; replace the duplicated inline luma formula in `Player::frameToBuffer` and
  `AsciiRenderer::render` with `LumaRamp::compute($r,$g,$b)` (one formula).
- **K4.11 (L11a):** add the "audio plays at 1.0× only; off-1.0× speed diverges A/V" note to the `AudioPlayer`
  class docblock (until K8 removes the limitation) and switch `findMpv()` from `@shell_exec` to the
  `Probe::which()` helper for consistency.
- **K4.12 (L12):** fix the `FfmpegDecoder::$process` PHPDoc to `resource|false|null` (drop the bogus
  `\Process`).

**Phase K4 DoD**
- [ ] Graphics/half-block render without per-frame PNG; `CHARS3` correct & length-asserted; `GraphicsRenderer`
      honors its `$mode` arg; dead branches gone.
- [ ] Synthetic frames pace evenly + write-failure throws; status lines width-clamped; magic numbers/luma DRY
      centralized; docblocks honest. **No golden churn** outside any ramp-test goldens; suite green.

---

## Phase K5 — Braille mode (N1)  *(PR: `ai/sugar-reel-jr2-braille`)*

**Why:** the only render mode the original plan spec'd that was never built; 4× density (2×4 dots/cell) and the
first mode needing `colsPerCell ≠ 1` — exercises the geometry contract end-to-end.

### Step K5.1 — Generalize geometry (B-G1)
- Add `Mode::Braille = 'braille'`; implement the `match`-based `rowsPerCell()=4` / `colsPerCell()=2`; update
  `Mode::label()` (exhaustive — no default arm).
- Decoders honor both axes (B-G1). `Player::frameToBuffer`/`detectCellDimensions` divide by both.

### Step K5.2 — `BrailleRenderer`
- `Render/BrailleRenderer.php implements FrameRenderer`: map each 2×4 pixel block to a Unicode Braille code
  point (`0x2800 + dotmask`), dot on/off by luminance threshold (reuse `LumaRamp::compute`), with optional
  per-cell truecolor fg (average block color via candy-palette) for color terminals. **Reuse** `sugar-charts`
  `BrailleGrid` if its API fits (verify signature first — fact 10); else hand-roll the 8-dot mask (documented
  prior art: tplay/glyph). Honor the selected ramp/threshold.
- `RendererFactory::create()` routes `Mode::Braille → new BrailleRenderer(...)`; `autoMode()` precedence stays
  graphics → halfblock → braille → ansi256 → ascii (braille is a *text* upgrade over ascii where truecolor
  exists but graphics don't — document the placement). Add Braille to the capability-aware `m` cycle
  (it's always renderable — pure Unicode).
- **Tests:** geometry regression (decode at `cellsW*2 × cellsH*4`, render to `cellsW × cellsH`); snapshot a 2×4
  synthetic frame → exact Braille code point + fg SGR golden; mode-cycle reaches Braille; `Reel::withMode(Braille)`
  end-to-end.

### Step K5.3 — Wire `Reel`/`play.php`/docs
- `play.php` accepts `braille` in the mode arg list; README modes table + `docs/lib/sugar-reel.html` gain a
  Braille row ("2×4 Braille dots, 4× density, any UTF-8 terminal"). VHS optional.

**Phase K5 DoD**
- [ ] `Mode::Braille` renders 2×4 dot cells at 4× density; geometry contract honors both axes; auto + `m` cycle
      include it; `Reel::withMode('braille')` works end-to-end; goldens added; docs updated; suite green.

---

## Phase K6 — Dithering (N2)  *(PR: `ai/sugar-reel-jr2-dither`)*

**Why:** the Ansi256/ASCII paths band badly on gradients; candy-flip already ships Floyd-Steinberg.

- Add `Reel::withDither(bool|enum $mode)` (`none|floyd|bayer`, default `none`) threaded per B-G3 to `Player`
  → `AsciiRenderer`. When enabled on the Ansi256 path, run `candy-flip Dither\FloydSteinberg::dither(\GdImage,
  $palette)` against the 256-color cube palette (candy-palette) before the ramp/quantize step; Bayer as a
  cheap ordered-dither alternative. Truecolor/graphics modes ignore it (no quantization needed).
- **Tests:** a smooth 2-color gradient frame, dithered vs not, produces a measurably larger set of distinct
  256-indices (less banding); `none` is byte-identical to today (no golden churn); bad option throws.
- Docs: README "dithering" subsection; CALIBER_LEARNINGS note (reuses candy-flip, not reinvented).

**Phase K6 DoD**
- [ ] `withDither('floyd'|'bayer'|'none')` selectable, validated; Ansi256 banding reduced (asserted); default
      unchanged; reuses candy-flip; docs updated; suite green.

---

## Phase K7 — Edge-orientation glyphs (N3)  *(PR: `ai/sugar-reel-jr2-edges`)*

**Why:** glyph-style structural ASCII — a Sobel pass picks `│ ─ ╱ ╲` by gradient orientation where edges are
strong, falling back to the luminance ramp elsewhere. Big perceptual win for line art / faces.

- Add `Reel::withEdges(bool $on = true)` (default off) threaded to `Player`/`AsciiRenderer`. On the ASCII
  path: compute Sobel Gx/Gy per cell (from the luma already computed), and when gradient magnitude exceeds a
  threshold, emit the orientation glyph (`atan2(Gy,Gx)` → one of `│ ─ ╱ ╲`); else the ramp char. Pure integer
  math in the hot loop (precompute thresholds). No new dependency.
- **Tests:** a synthetic frame with a clean diagonal edge renders `╱`/`╲` along the edge and ramp chars in flat
  regions; `edges=false` is unchanged (no golden churn); threshold boundary coercion test.
- Docs: README "edge glyphs" + prior-art credit (seatedro/glyph).

**Phase K7 DoD**
- [ ] `withEdges()` overlays orientation glyphs on strong edges, ramp elsewhere; off by default (no churn);
      hot loop stays integer; docs + credit; suite green.

---

## Phase K8 — Audio time-stretch for non-1.0× speed (N4)  *(PR: `ai/sugar-reel-jr2-atempo`)*

**Why:** removes the standing "audio diverges off-1.0×" limitation. ffmpeg/ffplay `atempo` pitch-corrects
playback rate.

- `AudioPlayer` gains a `?float $speed` (default 1.0): when building the ffplay/mpv command, append
  `-af atempo=<speed>` (ffplay) / `--speed=<speed>` (mpv) — chain `atempo` for factors outside `[0.5,2.0]`
  (e.g. `atempo=2.0,atempo=1.5` for 3×). Arg-array only (no shell). When `speed == 1.0`, emit nothing
  (byte-identical to today).
- `Player`: on `[`/`]` speed change **and** on seek/loop while audio exists, rebuild the AudioPlayer at the new
  speed and current offset (reuse the K3-era realign path + the `audioFactory` seam). Guard: only rebuild audio
  on a *committed* speed change (debounce isn't needed — speed steps are discrete).
- Update README/CALIBER_LEARNINGS to **remove** the 1.0×-only limitation (and drop the K4.11 caveat note);
  document the atempo chaining and its `[0.5,100]` practical range.
- **Tests (gated on ffplay):** spy AudioPlayer asserts the command carries `atempo=<speed>` for 1.25×/0.75×/3×
  (chained); pure-unit `buildCommand()` arg-array assertion for the chaining math; `speed==1.0` adds no filter.

**Phase K8 DoD**
- [ ] Non-1.0× speed pitch-corrects audio via `atempo` (chained beyond [0.5,2.0]); speed change/seek rebuild
      audio at the new rate+offset; 1.0× unchanged; limitation removed from docs; suite green; no orphan procs.

---

## Phase K9 — Export (N5)  *(PR: `ai/sugar-reel-jr2-export`)*

**Why:** the deferred Step 8 — make playback reproducible/shareable. Two sub-steps so each PR stays reviewable.

### Step K9.1 — Self-replay ANSI / bash script (video-to-ascii style)
- `Export/AnsiExporter.php`: iterate the decoder→renderer pipeline to completion, capture each frame's emitted
  ANSI + an inter-frame delay, and write either (a) a raw `.ansi` cat-able stream with timing, or (b) a
  self-contained `.sh` that `printf`s frames with `sleep` between them (clears screen per frame). `Reel::export($path, ExportFormat::AnsiScript)`.
- **Tests:** export a 3-frame synthetic to a script; assert frame count, that it starts with the alt-screen/
  clear sequence, ends with a reset, and (for `.sh`) is syntactically valid (`bash -n`).

### Step K9.2 — GIF / mp4 via ffmpeg encode (glyph style)
- `Export/VideoExporter.php`: pipe rendered frames (as an image sequence or a `rawvideo` stream) into an
  ffmpeg **encode** subprocess (arg-array): GIF via `palettegen`+`paletteuse`, mp4 via libx264. Gated on
  ffmpeg presence (clear error otherwise). `Reel::export($path, ExportFormat::Gif|Mp4)`.
- For terminal-glyph capture (ascii/braille), render frames to an off-screen image (GD) at a fixed cell font
  metric so the export looks like the terminal; for graphics modes, export the source-resolution frames.
- **Tests (gated on ffmpeg):** export the synthetic to a `.gif`; assert the output is a valid multi-frame GIF
  (candy-flip decodes ≥2 frames) sized as requested; pure-unit asserts the ffmpeg arg-array (no shell) and the
  palettegen/paletteuse / libx264 flags.
- `examples/play.php` (or a new `examples/export.php`) demonstrates `--export out.gif`; README "Export"
  section; `docs/lib/sugar-reel.html` updated; MATCHUPS note that Export closes the deferred Step 8.

**Phase K9 DoD**
- [ ] `Reel::export()` produces a working self-replay ANSI/bash script AND (ffmpeg present) a valid GIF/mp4;
      arg-array only; gated tests + pure-arg tests; example + docs; suite green; `pgrep ffmpeg` empty after.

---

## 4. End-to-end verification (after K1–K9)

1. **Per-lib green:** `cd sugar-reel && composer update && vendor/bin/phpunit` — 0 failures, only binary-absent
   skips, **no warnings**; `php tools/check-path-repos.php` reports closed (any dep added in K6/K9 wired with
   its transitive path-repo closure).
2. **No-arg demo:** `php examples/play.php` → animated, looping synthetic, **no warnings**, no help screen;
   `--help` shows help and does not start; `q` quits.
3. **Degradation:** ffmpeg hidden + `.mp4` → clean width-clamped error, no fatal, no spin; `.gif` still plays.
4. **Modes:** every mode rendered and reachable via `m` (capability-aware) — `ascii`, `ansi256`, `truecolor`,
   `halfblock`, **`braille`**, plus `sixel`/`kitty`/`iterm2` where supported. Picture stays terminal-sized in
   each; resize re-scales (decoder rebuilt; no over-wide/over-tall lines).
5. **Controls:** space, ←/→ seek (video+audio together), `[`/`]` speed (**no jump/freeze; audio now
   pitch-corrects via atempo**), `0`–`9` percentage-seek lands proportionally (works for MKV/WebM via
   `format.duration`), `m` cycles supported modes only.
6. **New features:** `withRamp` × `withDither('floyd')` × `withEdges()` × `withMode('braille')` compose; each
   visibly changes output; bad option → `InvalidArgumentException`.
7. **Export:** `Reel::export()` → replayable script + (ffmpeg) GIF/mp4; outputs validate.
8. **Hang/leak audit:** `pgrep ffmpeg|ffplay|mpv` empty after quit; repeated backward-seeks/speed-changes don't
   accumulate processes; AudioPlayer `__destruct` reaps on GC; temp GIFs cleaned up.
9. **CI:** lib check-runs `success` on the **master push** (not the force-all PR run); `vhs.yml`/`ci.yml` still
   discover `sugar-reel`; VHS regenerated where a phase changed visible output (K1.3/K5 at most — via candy-vcr,
   ~6 min/tape, GIFs committed).

---

## 5. Sequencing, risk & rollback

- **Order is dependency-driven:** K1 (critical) → K2 (safety) → K3 (guards/probe) → K4 (cleanup, incl. B-G2
  perf bridge reused later) → **K5 (Braille, introduces B-G1 geometry)** → K6 (dither) → K7 (edges) →
  K8 (atempo) → K9 (export). Each is an independent PR; revert in reverse order.
- **Highest blast radius:** any **ctor field add** (`error` in K1.2; `dither` in K6; `edges` in K7) — touches
  every `new self(...)` site AND `PlayerTest::createPlayerWithOverrides()`. **Append at the END, update the
  helper in the same commit, run the full suite before ship** (fact 8 / B-G3). If two phases each add a field,
  the second re-syncs the helper for both. K5's geometry change touches both decoders + `Player::frameToBuffer`
  — land it alone within its PR and rely on the geometry regression test.
- **Golden/GIF churn is intentional in exactly these spots:** K1.3 (GIF89a header — synthetic bytes), K5
  (Braille goldens), K6/K7 (only the *new* dither/edge test goldens; existing snapshots must NOT move with the
  feature **off**), K9 (export fixtures), and any VHS regen. **If any OTHER committed snapshot changes, STOP —
  it's a render regression** (TUI-invariants memory).
- **New deps:** K6 reuses candy-flip (already a dep — verify `Dither\FloydSteinberg` is on the path-repo
  closure). K9 GIF/mp4 uses the ffmpeg **binary** (no new composer dep). If any new `sugarcraft/*` require
  appears, `php tools/check-path-repos.php --fix` + `composer update`.
- **Nothing is out of scope this time.** The prior plans' "out of scope" list (audio time-stretch, Export,
  braille/dither/edge-glyph polish) is exactly Part B and is now in scope and required.

---

## 6. Quick estimate

| Phase | Items | Size |
|---|---|---|
| K1 — Critical correctness | C1, C2, C3 | S–M |
| K2 — Safety & silent-failure | H1, H2, M1, M2, M3 | M |
| K3 — Guards & probe accuracy | M5, M6, M7, M8, L8 | S–M |
| K4 — Render perf & cleanup | M4 + L1–L12 | M |
| K5 — Braille mode | N1 (+B-G1) | M |
| K6 — Dithering | N2 | S–M |
| K7 — Edge glyphs | N3 | S–M |
| K8 — Audio time-stretch | N4 | M |
| K9 — Export | N5 (2 steps) | M–L |

**9 PRs** (K9 = two steps). Every functional change lands with a regression test that **fails on current
`master`** first (no-arg-help for C1, throw-on-non-gif for C2, GIF89a-header for C3, bounded-skip for H2,
format-duration for M7, per-cell-color parity for M5, atempo-arg for K8, animated-export for K9, 2×4-geometry
for K5) — closing both the JR-residual defects and the original `video_plan.md` feature backlog in full.
