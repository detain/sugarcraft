# sugar-reel — remediation plan (post-audit fixes)

> Companion to [`video_plan.md`](video_plan.md) (the original build plan, completed). This document
> is the **fix plan** for the 21 issues surfaced by the post-build audit (2026-06-03). It is the
> deliverable for the session that found: percentage-seek dead, runtime mode-switch corrupts
> geometry, `auto()` never wired, speed-change jump/freeze, GIF half-height, audio not realigned on
> seek, ffmpeg/audio subprocess hang & SIGPIPE risks, no EOF handling, ignored `WindowSizeMsg`, and a
> tail of dead code / doc / example defects.
>
> **First action on execution:** none required — this file already lives at repo root so every
> spawned agent can read it.

---

## 0. Source-of-truth facts (verified during the audit — do not re-derive)

These were confirmed by reading the code; build on them rather than re-investigating:

1. **Terminal size is already delivered by the framework.** `candy-core`'s `Program::run()` emits an
   initial `WindowSizeMsg(cols, rows)` ([`candy-core/src/Program.php:173`](candy-core/src/Program.php))
   and re-emits on SIGWINCH ([`:935`](candy-core/src/Program.php)). `WindowSizeMsg` exposes
   `->cols` / `->rows`. The Player currently has **no** `WindowSizeMsg` branch, so it silently keeps
   its constructor size. The fix is to *handle the message*, not to probe the tty.
2. **`candy-buffer\Style` is truecolor-only** — `__construct(?int $fg = null /*0xRRGGBB*/, ?int $bg = null, int $attrs = 0)`. There is no 256-index field. Ansi256 therefore **cannot** round-trip the
   Buffer without quantizing to truecolor; it must stay on the `renderDirect()` path.
3. **`FakeDecoder` ignores `$mode`** ([`sugar-reel/tests/FakeDecoder.php`](sugar-reel/tests/FakeDecoder.php)) — it
   replays a fixed frame list. Geometry regression tests (mode-switch, GIF height) need a
   **mode-aware** decoder double (extend `FakeDecoder` or add `GeometryFakeDecoder`).
4. **candy-flip decodes to a cell grid**, not pixels: `Decoder::decode(path, cellsW, cellsH)` →
   `Frame::$cells` is `cellsH` rows × `cellsW` cols. So a GIF frame is `cellsH` rows tall; HalfBlock
   needs `2*cellsH`.

---

## 1. Conventions, per-step pipeline, ship cadence

Every numbered **step** below runs the same agent pipeline already used by `video_plan.md` §"Per-step
agent pipeline" — repeated here compactly:

1. **Implementer** — `composer update` in the lib first (stale vendor → false failures); implement
   exactly that step's scope; follow conventions (`declare(strict_types=1)`, PSR-12/PSR-4, `final`,
   immutable `with*()`/`mutate()`, bare accessors, `::new()`); no `Mirrors charmbracelet/...` where no
   upstream exists — cite tplay / glyph / video-to-ascii.
2. **Reviewer** — review ONLY that step's diff for correctness, conventions, security (every
   external-CLI arg via arg-array, never a shell string), reuse. Returns findings or `CLEAN`.
3. **Fixer** — apply findings; loop reviewer↔fixer until `CLEAN` (cap 4 rounds).
4. **Tester** — **regression-first**: write the test that FAILS against current code (proving the
   bug), then make it green. PHPUnit 10 from the lib root. For subprocess code, **spawn a backgrounded
   `pkill` watchdog** (a `timeout` wrapper does not kill PTY/proc_open hangs — root memory).
5. **Documenter** — README / CALIBER_LEARNINGS / doc-comments / `docs/lib/sugar-reel.html` as touched.
6. **Ship** — ship-as-you-go (the `ship-pr` skill): `git checkout -b ai/sugar-reel-<short>` →
   commit (author `Joe Huss <detain@interserver.net>`) → push → `unset GITHUB_TOKEN && gh pr create`
   → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`.

**This machine SKIPS Caliber** — do NOT run `caliber refresh`; if a hook auto-stages Caliber-managed
files, unstage them before committing.

**Bundling:** one **phase = one PR** (2–4 related items), per the repo's bundling preference. Phase 5
splits into 5A/5B to keep each PR reviewable.

**Verification gate before every Ship:** `vendor/bin/phpunit` green for `sugar-reel` AND any
foundation lib whose path-repo wiring changed; `php tools/check-path-repos.php` reports closed; the
phase's **Definition of Done** (DoD) checklist all ticked.

**CI note** (root memory): force-all PR runs go red on detached-HEAD `dev-<sha>`; confirm green on the
**master push**, not the PR run.

---

## 2. Traceability — every audit item → phase

| # | Audit finding | Sev | Phase.Step |
|---|---|---|---|
| F1 | Percentage-seek (`0`–`9`) always frame 0 — `totalFrames` never populated | High | 3.1 |
| F2 | Runtime mode-switch (`m`) corrupts geometry; stale "Step 6" gate hides graphics modes | High | 2.3 (+4.3 cycle list) |
| F3 | `RendererFactory::auto()` dead — auto-detect never runs | High | 4.1 |
| F4 | Speed change jumps/freezes (speed applied retroactively to cumulative elapsed) | High | 3.2 |
| F5 | GIF fallback + synthetic render at half height in HalfBlock | High | 2.1 |
| F6 | Seek never realigns audio (`AudioPlayer::$startMs` unused) | Med | 3.3 |
| F7 | ffmpeg stderr never drained → deadlock/hang on noisy input | Med | 1.1 |
| F8 | AudioPlayer closes child stdout/stderr read-ends → SIGPIPE may kill audio | Med | 1.2 |
| F9 | No end-of-stream handling — busy-loops at fps forever, no quit/loop/indicator | Med | 1.3 |
| F10 | `WindowSizeMsg` ignored — fixed 80×24, no resize | Med | 4.2 |
| F11 | Fabricated `Mirrors charmbracelet/sugar-reel` in 5 files | Low | 6.1 |
| F12 | Dead `Sync` instance API (ctor `fps`/`speed`, `reset()`) | Low | 3.2 |
| F13 | Dead `FrameMsg` (never produced; dead `update()` branch) | Low | 5A.1 |
| F14 | Two diverging half-block impls (inline Buffer vs unused Mosaic renderer) | Low | 5B.2 |
| F15 | Ansi256 per-pixel `SGR…reset`, no coalescing/diff | Low | 5B.3 |
| F16 | `VideoSource::probe` builds arg-array then `implode`→shell string | Low | 5A.2 |
| F17 | `RgbFrame::toGd` per-pixel `imagecolorallocate` (slow hot path) | Low | 5B.1 |
| F18 | `Reel::withFps(float)` non-nullable but doc says "pass null" | Low | 6.2 |
| F19 | `play.php` no-arg warnings; dup `buildSyntheticGif`; false "loops" comment; static 1-frame demo | Low | 6.3 |
| F20 | `LumaRamp::char` hard-codes `standard`; BT.709/601 doc confusion | Low | 6.4 |
| F21 | **Backward-seek leaks the ffmpeg process** (new decoder built, old never `close()`d) | Med | 2.2 |
| G1 | Test gaps: digit-seek/mode tests inject state via reflection or only assert "changed" | — | each step's Tester |

---

## 3. Cross-cutting building blocks (introduce once, reuse everywhere)

Two shared helpers eliminate the duplicated, drift-prone logic behind several fixes. Build them in the
phase where first needed (noted), then reuse.

### B1 — `Mode::rowsPerCell()` geometry contract *(Phase 2.1)*
A single source of truth for "how many source pixel-rows one terminal cell consumes," so the decoder,
the Player's buffer builder, and the renderers can never disagree again.

```php
// src/Render/Mode.php
public function rowsPerCell(): int
{
    return $this === self::HalfBlock ? 2 : 1;   // graphics modes resolve via the renderer, treated as 1 here
}
public function colsPerCell(): int { return 1; }
```
- `FfmpegDecoder::open()` → `frameH = cellsH * $mode->rowsPerCell()`.
- `GifDecoder::open()` → decode at `cellsH * $mode->rowsPerCell()` (F5).
- `Player::frameToBuffer()` / `detectCellDimensions()` → use `$mode->rowsPerCell()` instead of the
  inline `match`.

### B2 — `Player::rebuildDecoderAt()` *(Phase 2.2)*
One routine that **closes the old decoder** (fixes F21), creates a new one at a given size+mode, and
advances to a frame index. Backward-seek, mode-switch (F2), and resize (F10) all call it.

```php
/** @return array{0: Decoder, 1: ?RgbFrame} */
private function rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array
{
    $this->decoder->close();                 // F21: never leak the old ffmpeg process
    $decoder = DecoderFactory::create($this->videoPath, $cellsW, $cellsH, $this->fps, $mode);
    $frame = null;
    for ($i = 0; $i <= $frameIndex; $i++) {
        $f = $decoder->next();
        if ($f === null) { break; }
        $frame = $f;
    }
    return [$decoder, $frame];
}
```
Test paths use `videoPath === '/fake'`; keep the existing `/fake` guard (skip rebuild, mutate fields
only) so `FakeDecoder`-based tests keep working — geometry is proven with the mode-aware fake (B3).

### B3 — mode-aware decoder double *(Phase 2, tests only)*
Extend `tests/FakeDecoder.php` (or add `tests/GeometryFakeDecoder.php`) so `open(...,$mode)` regenerates
its frames at `cellsW × (cellsH * $mode->rowsPerCell())`. Lets the Tester assert that after a `m`
mode-switch the rendered row-count still equals `cellsH` (not `2*cellsH` or `cellsH/2`).

---

## Phase 1 — Resource safety & lifecycle  *(PR: `ai/sugar-reel-robustness`)*

**Why first:** these are latent **hangs / silent death**. Fixing them first means the manual smoke
tests in later phases can't deadlock the terminal. No user-visible behavior change except "video no
longer wedges."

**Files:** `src/Decode/FfmpegDecoder.php`, `src/AudioPlayer.php`, `src/Player.php`, `src/Reel.php`.

### Step 1.1 — Drain/redirect ffmpeg stderr (F7)
- **Problem:** stderr pipe is held open and never read ([`FfmpegDecoder.php:75-91`](sugar-reel/src/Decode/FfmpegDecoder.php)); on a noisy/corrupt input ffmpeg blocks once the ~64 KB pipe buffer fills → `fread(stdout)` in `next()` blocks forever.
- **Fix (primary):** redirect child stderr to a sink instead of a pipe:
  - descriptor `2 => ['file', $devNull, 'w']` where `$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'`.
  - Drop the `$stderr` property, its `is_resource` close in `close()`, and the pipe read-end.
- **Fix (optional diagnostics):** if error visibility matters, instead keep `2 => ['pipe','w']`,
  `stream_set_blocking($pipes[2], false)`, and in `next()` opportunistically read-and-discard (bounded
  to e.g. 8 KB kept for an exception message when `proc_close` exit ≠ 0). Choose one; primary is fine.
- **Tests:** unit — feed a canned rawvideo buffer through a stream-backed decoder seam and assert frame
  framing is unchanged (no real ffmpeg). Live (gated on `Probe::ffmpeg()`): decode a tiny generated
  clip and assert ≥1 frame + clean `close()`. Watchdog-guarded.

### Step 1.2 — Give the audio subprocess real sinks (F8)
- **Problem:** `start()` opens stdin/stdout/stderr as pipes then immediately `fclose`s the parent ends
  ([`AudioPlayer.php:64-89`](sugar-reel/src/AudioPlayer.php)); ffplay/mpv writing their status line to a
  reader-less stderr pipe can take SIGPIPE and die → audio silently stops.
- **Fix:** use file sinks, no pipes to close:
  ```php
  $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
  $descriptorSpec = [
      0 => ['file', $devNull, 'r'],
      1 => ['file', $devNull, 'w'],
      2 => ['file', $devNull, 'w'],
  ];
  $this->processHandle = @proc_open($cmd, $descriptorSpec, $pipes);
  // no pipe cleanup needed
  ```
  Keep the `=== false` guard; drop the dead `=== 0` check (proc_open never returns 0).
- **Tests:** gated on `Probe::ffplay()` — `start()` then `isPlaying()` true after a short settle;
  `stop()` → `isPlaying()` false. Confirm no orphaned ffplay (watchdog + `pgrep` assertion).

### Step 1.3 — End-of-stream handling + optional loop (F9)
- **Problem:** when the decoder exhausts, `updateTick` keeps the last frame and reschedules ticks
  forever ([`Player.php:246-261`](sugar-reel/src/Player.php)) — a busy-loop at fps, no quit, no
  indicator.
- **Fix:**
  - Add `public readonly bool $ended` and `private readonly bool $loop` to `Player` (thread through
    ctor / `mutate` / `openForTest`). Add `Reel::withLoop(bool $loop = true)` → pass to `Player::open`.
  - In `updateTick`, when a **normal advance** (or the tail of a skip) calls `decoder->next()` and gets
    `null` while `target > frameIndex` (we wanted a frame and none came):
    - if `$loop`: `[$decoder,$frame] = rebuildDecoderAt(cellsW,cellsH,mode,0)`; reset `videoTime = 0`,
      `frameIndex = 0`, restart audio at offset 0.
    - else: set `ended = true`, `audioPlayer?->stop()`, return `cmd = null` (**stop ticking**).
  - `view()`: when `$ended`, append a status line e.g. `"[ended]  0 restart  q quit"`.
  - Pressing `0` (a percentage seek to 0%) already restarts; ensure a seek clears `ended` and
    reschedules a tick if not paused.
- **Tests:** drive a 3-frame `FakeDecoder` to exhaustion; assert (a) non-loop → `ended === true` and
  the returned `Cmd` is `null` (no more ticks); (b) `withLoop` → wraps to frame 0 and keeps ticking.

**Phase 1 DoD**
- [ ] No code path holds an undrained child pipe; ffmpeg stderr + audio std streams go to sinks.
- [ ] Exhausted decoder stops ticking (non-loop) or wraps (loop); audio stopped on end.
- [ ] New regression tests fail on `master`, pass after the fix; full suite green; watchdog used for
      any live subprocess test.

---

## Phase 2 — Geometry correctness  *(PR: `ai/sugar-reel-geometry`)*

**Why:** the decoder's pixel resolution must always match the render mode's `rowsPerCell`. Today GIF
ignores mode (F5) and runtime `m` changes mode without rebuilding the decoder (F2), so output is
half/double height. Introduces B1 + B2.

**Files:** `src/Render/Mode.php`, `src/Decode/GifDecoder.php`, `src/Decode/FfmpegDecoder.php`,
`src/Player.php`, `tests/FakeDecoder.php`.

### Step 2.1 — `Mode::rowsPerCell()` + GIF honors mode (F5, B1)
- Add `rowsPerCell()`/`colsPerCell()` to `Mode` (B1).
- `GifDecoder::open()` ([`:41-49`](sugar-reel/src/Decode/GifDecoder.php)): decode at
  `FlipDecoder::decode($source, $cellsW, $cellsH * ($mode?->rowsPerCell() ?? 2))`. Remove the stale
  "mode ignored" comment. Now GIF HalfBlock fills the screen like ffmpeg.
- `FfmpegDecoder::open()` ([`:48-50`](sugar-reel/src/Decode/FfmpegDecoder.php)): replace the inline
  `$isHalfBlock` branch with `frameH = cellsH * ($mode?->rowsPerCell() ?? 2)`.
- `Player::frameToBuffer()` / `detectCellDimensions()`: use `$mode->rowsPerCell()`/`colsPerCell()`.
- **Tests:** parity — a synthetic GIF + a canned ffmpeg-shaped buffer at the same cells render the
  **same `bufH`** (== `cellsH`) in HalfBlock; HalfBlock GIF bufH must equal `cellsH` (regression: was
  `cellsH/2`).

### Step 2.2 — `rebuildDecoderAt()` + fix backward-seek leak (F21, B2)
- Add `rebuildDecoderAt()` (B2) — note the `decoder->close()` that fixes F21.
- Refactor `withSeek()`'s backward branch ([`:546-612`](sugar-reel/src/Player.php)) and forward branch
  to share the helper where it makes sense (forward seek may still advance the existing decoder for
  speed; backward seek must rebuild **and close the old one**).
- **Tests:** backward seek on a spy decoder asserts `close()` was called exactly once on the old
  decoder; resulting `frameIndex` is correct.

### Step 2.3 — Rebuild decoder on `m`; drop the stale gate (F2, B3)
- In `updateKey` `'m'` ([`:347-356`](sugar-reel/src/Player.php)):
  - **Remove** the `while (in_array($modes[$nextIdx], [Sixel,Kitty,Iterm2]))` skip loop and its
    "until Step 6" comment.
  - Compute `nextMode` from the cycle list (Phase 4.3 tightens this to *supported* modes; for now cycle
    all implemented modes).
  - For real paths: `[$decoder,$frame] = rebuildDecoderAt($cellsW,$cellsH,$nextMode,$frameIndex)`; build
    the new `self` with `decoder`, `currentFrame`, `mode: $nextMode`. For `/fake`: mutate `mode` only.
  - If currently playing, the in-flight tick continues (no `Cmd` needed); if paused, no tick — both
    already correct.
- Extend `FakeDecoder` to be mode-aware (B3) for the geometry assertion.
- **Tests (replaces the weak `assertNotSame` test):** open mode-aware fake at 80×24; press `m` to each
  mode; assert the rendered output's **line count == 24** for every mode (proves decoder rebuilt to
  match). Assert the graphics modes are now **reachable** in the cycle.

**Phase 2 DoD**
- [ ] GIF and ffmpeg produce identical grid height per mode; HalfBlock fills the terminal for both.
- [ ] `m` rebuilds the decoder; output stays terminal-sized across all modes; graphics modes reachable.
- [ ] Backward-seek/mode-switch never leak a decoder (spy asserts `close()`); suite green.

---

## Phase 3 — Timeline: pacing, percentage-seek, audio realign, Sync cleanup  *(PR: `ai/sugar-reel-timeline`)*

**Why:** the playback clock is wrong in three ways — `totalFrames` is never set (F1), speed is applied
retroactively (F4), and audio isn't repositioned on seek (F6). All three live in the Player↔Sync↔Audio
timeline, so fix together. Also remove the dead `Sync` instance API (F12).

**Files:** `src/Player.php`, `src/Sync.php`, `src/AudioPlayer.php`, `tests/PlayerTest.php`,
`tests/SyncTest.php`.

### Step 3.1 — Populate `totalFrames` (F1, G1)
- `Player::open()`: after probing,
  `$totalFrames = ($source->duration > 0.0 && $fps > 0.0) ? (int) round($source->duration * $fps) : 0;`
  and pass it to the ctor (replaces the literal `0` at [`:124`](sugar-reel/src/Player.php)).
- `openForTest(..., int $totalFrames = 0)` — add the param so tests stop reaching for reflection.
- Digit-seek ([`:339-344`](sugar-reel/src/Player.php)): if `totalFrames <= 0`, **no-op** (can't
  percent-seek an unknown-length stream); else `targetIndex = (int)($percent/100 * $totalFrames)`.
- **Tests:** refactor `testChar0…`/`testChar5…` to set `totalFrames` via `openForTest` (delete the
  reflection `setTotalFrames` helper). Add: `totalFrames == 0` → digit key is a no-op.

### Step 3.2 — Speed re-anchor via video-time accumulator (F4) + Sync cleanup (F12)
- **Root cause:** `target = floor(elapsed * fps * speed)` over **cumulative raw** `elapsed`, so a speed
  change retroactively re-scales all prior time → forward jump (speed up) or multi-second freeze (slow
  down).
- **Redesign** — track *content* time, not wall time:
  - Rename `float $elapsed` → `float $videoTime` (seconds of content shown).
  - `updateTick`: `$delta = $now - $lastTickTime; $videoTime = $this->videoTime + $delta * $this->speed; $target = Sync::targetFrame($videoTime, $this->fps);`
  - **Speed change** (`[` / `]`): mutate `speed` only — future deltas scale; **no jump, no re-anchor.**
  - Seek/mode-rebuild/resume: set `videoTime = frameIndex / fps`; `lastTickTime = microtime(true)`.
  - `Sync::targetFrame(float $videoTime, float $fps): int` — **drop the `$speed` param**
    ([`Sync.php:42-48`](sugar-reel/src/Sync.php)). Update all callers.
- **F12:** delete `Sync::__construct(fps,speed)` and `Sync::reset()` (no callers); `Sync` becomes a pure
  static helper (keep `shouldSkip`/`shouldHold`/`targetFrame`).
- **Tests:** drive N ticks at speed 1.0 to frame ~K; press `]` (1.25×); assert the **next** target only
  advances by ~`delta*1.25*fps` (no `0.25*videoTime*fps` skip-storm). Press `[` (0.75×) from mid-play;
  assert it does **not** enter a multi-tick hold (no freeze). Update `SyncTest` for the new signature.

### Step 3.3 — Seek realigns audio (F6)
- In the seek and mode-rebuild paths, when `audioPlayer !== null` and it had started:
  - `audioPlayer->stop();`
  - `$audio = new AudioPlayer($this->videoPath, (int) round(($targetIndex / $this->fps) * 1000));`
  - if not paused → `$audio->start();` (else leave for the next resume).
  - carry `$audio` into the new `self`.
- `AudioPlayer::buildCommand()` already emits `-ss`/`--start` from `$startMs` — now actually exercised.
- Note (limitation, document; do not fix here): audio still plays at 1.0× regardless of `speed` — any
  non-1.0 speed diverges A/V. Capture in README "known limitations" + a `CALIBER_LEARNINGS` note;
  proper fix (atempo/rubber-band or muting audio off-1.0) is out of scope.
- **Tests (gated on ffplay):** seek forward, assert a **new** AudioPlayer with `startMs ≈ targetFrame/fps*1000`
  is created and the old one stopped (spy/fake AudioPlayer). Pure-math assertion runs unconditionally.

**Phase 3 DoD**
- [ ] `totalFrames` derived from probe; digit-seek lands at `percent*totalFrames` with **no reflection**
      in tests; stream (`0`) → no-op.
- [ ] Speed changes affect only future pacing — no jump, no freeze — proven by a tick-driven test.
- [ ] `Sync` is static-only; `targetFrame` signature updated everywhere; seek repositions audio.

---

## Phase 4 — Environment detection: auto-mode, resize, capability-aware cycle  *(PR: `ai/sugar-reel-autodetect`)*

**Why:** the advertised "auto-detect best mode" never runs (F3) and the framework's `WindowSizeMsg` is
ignored (F10). Both are wiring at the Reel→Player→runtime boundary. Then tighten the `m` cycle (F2 tail)
to only offer supported modes.

**Files:** `src/Reel.php`, `src/Player.php`, `src/Render/RendererFactory.php`, `examples/play.php`.

### Step 4.1 — Wire `auto()` (F3)
- Make "auto" representable end-to-end:
  - `Reel`: store `?Mode $mode` (null = auto). `Reel::open()`/`new()` default `mode = null`.
    `withMode(Mode)` sets explicit; add `withAutoMode(): self` → null.
  - `Reel::play()`: `$mode = $this->mode ?? RendererFactory::auto()->/* resolved Mode */;` — but
    `auto()` returns a `FrameRenderer`, not a `Mode`. **Add `RendererFactory::autoMode(): Mode`** (the
    mode-picking half of `auto()`), and have `auto()` delegate to it. Pass the resolved `Mode` to
    `Player::open()`.
  - `Player::open()` keeps taking a concrete `Mode` (decoder resolution needs it).
- `examples/play.php`: the `auto` arg → call the resolved path (no longer silently HalfBlock). Update
  the in-file note.
- **Tests:** subclass/seam `RendererFactory` (or stub `Mosaic::diagnose()`/`Probe::colorProfile()`) to
  assert `autoMode()` returns Sixel→Kitty→Iterm2→HalfBlock(truecolor)→Ansi256→Ascii in precedence.
  (Existing `RendererFactoryTest` covers `create`; add `autoMode` precedence cases.)

### Step 4.2 — Handle `WindowSizeMsg` (F10)
- `Player::update()`: add a branch:
  ```php
  if ($msg instanceof \SugarCraft\Core\Msg\WindowSizeMsg) {
      return $this->updateResize($msg->cols, $msg->rows);
  }
  ```
- `updateResize(int $cols, int $rows)`: if unchanged, `[$this, null]`; else
  `[$decoder,$frame] = rebuildDecoderAt($cols, $rows, $mode, $frameIndex)`; new `self` with
  `cellsW=$cols, cellsH=$rows, decoder, currentFrame=$frame`. Reschedule a tick iff playing.
- **Consequence:** the Player's constructor size becomes provisional — the framework's **initial**
  `WindowSizeMsg` (Program.php:173) now sizes the video to the real terminal on start, so the hard
  80×24 default and `SUGAR_REEL_COLS/ROWS` become *overrides* only. Document that.
- Clamp incoming size to sane bounds (`cols≥10, rows≥5`) to avoid zero-area buffers.
- **Tests:** send `WindowSizeMsg(120,40)` to a `/fake`-path Player (mutate-only branch) and a
  mode-aware-fake Player; assert `cellsW/cellsH` updated and (real-fake) the decoder rebuilt; no-op when
  size unchanged.

### Step 4.3 — Capability-aware `m` cycle (F2 tail)
- Build the cycle list once: always `[Ascii, Ansi256, TrueColor, HalfBlock]` + each of
  `[Sixel, Kitty, Iterm2]` **only if** `RendererFactory` reports it supported (reuse the `autoMode`
  capability probe). Cycling no longer lands on a graphics mode the terminal can't render (which would
  emit garbage).
- **Tests:** with a stub reporting "no graphics," `m` cycles only the 4 text modes; with "sixel
  supported," Sixel appears in the cycle.

**Phase 4 DoD**
- [ ] `auto` actually resolves a mode via probe; `play.php auto` honored.
- [ ] Resizing the terminal re-scales the video (decoder rebuilt); initial size comes from the runtime.
- [ ] `m` only offers modes the terminal supports; suite green.

---

## Phase 5 — Internal cleanup & consistency

Two small PRs so each stays reviewable.

### Phase 5A — Dead code & no-shell  *(PR: `ai/sugar-reel-deadcode`)*
**Files:** `src/Player.php`, `src/Msg/FrameMsg.php`, `src/Source/VideoSource.php`.

- **Step 5A.1 — Remove `FrameMsg` (F13):** it is never produced (verified — zero `new FrameMsg`). Delete
  `src/Msg/FrameMsg.php`, the import, and the dead `if ($msg instanceof FrameMsg)` branch
  ([`Player.php:198-202`](sugar-reel/src/Player.php)). Update `tests/MsgTest.php`. *(If a future async
  decode wants it back, reintroduce with a producer — but don't keep dead surface now.)*
- **Step 5A.2 — `VideoSource::probe` arg-array, no shell (F16):** replace
  `proc_open(implode(' ', array_map(escapeshellarg(...), $cmd)), …)` ([`:122-130`](sugar-reel/src/Source/VideoSource.php))
  with the **array** form `proc_open($cmd, …)` (no shell, no escaping — mirrors `FfmpegDecoder`). Send
  stderr to `/dev/null`; keep reading stdout via `stream_get_contents`. Output is byte-identical.
- **Tests:** `VideoSource` JSON-parsing tests already cover `fromFfprobeJson`; add a live gated test
  (ffprobe present) that `probe()` still yields correct w/h/fps/hasAudio.

### Phase 5B — Performance & render consistency  *(PR: `ai/sugar-reel-render-perf`)*
**Files:** `src/Decode/RgbFrame.php`, `src/Render/AsciiRenderer.php`, `src/Render/HalfBlockRenderer.php`,
tests + goldens.

- **Step 5B.1 — Fast `RgbFrame::toGd()` (F17):** drop per-pixel `imagecolorallocate`
  ([`:57-61`](sugar-reel/src/Decode/RgbFrame.php)). On a **truecolor** GD image a packed int *is* the
  color, so `imagesetpixel($img, $x, $y, ($r << 16) | ($g << 8) | $b)` — no allocation. (Optional
  further win: build a 24-bit BMP string + `imagecreatefromstring`; gate behind a format-support check.)
  Behavior identical; pixels unchanged.
  - **Tests:** `toGd()` round-trip — `imagecolorat()` equals source RGB for sample pixels (already
    partly covered; assert exactness post-change).
- **Step 5B.2 — Half-block parity guard (F14):** keep the inline `Player::frameToBuffer` HalfBlock path
  (it integrates candy-core's diff) **and** the Mosaic `HalfBlockRenderer`, but add a **parity test**:
  for a sample `RgbFrame`, assert the inline buffer output and `MosaicHalfBlockRenderer::render()`
  produce equivalent `▀ fg/bg` cells, so the two can't drift. Document the intentional split in both
  files (inline = buffered/diffed playback path; Mosaic = standalone/`renderDirect` alternative). Do
  **not** rewrite the buffer path (avoids golden churn).
- **Step 5B.3 — Ansi256 SGR coalescing (F15):** candy-buffer is truecolor-only (fact #2), so Ansi256
  stays on `renderDirect`. In `AsciiRenderer::render()` ([`:62-76`](sugar-reel/src/Render/AsciiRenderer.php)):
  stop emitting `…\x1b[0m` per pixel; track the last-emitted SGR and only emit a new
  `\x1b[38;5;Nm` when it changes from the previous cell, with a single `\x1b[0m` at line/frame end.
  Apply the same run-coalescing to the TrueColor branch for consistency.
  - **Tests:** snapshot — a run of same-color pixels emits the color **once**; regenerate the affected
    Ansi256/TrueColor goldens; assert no `\x1b[0m` between same-color cells.

**Phase 5 DoD**
- [ ] No dead `FrameMsg`; `VideoSource` uses arg-array (no shell); `toGd` allocation-free.
- [ ] Half-block parity test guards against drift; Ansi256/TrueColor SGR coalesced; goldens regenerated.

---

## Phase 6 — Docs, examples, VHS & end-to-end  *(PR: `ai/sugar-reel-docs-examples`)*

**Files:** 5 render `src/**` doc-comments, `src/Reel.php`, `src/Render/LumaRamp.php`,
`examples/play.php`, `src/Synthetic.php` (new), `README.md`, `CALIBER_LEARNINGS.md`,
`docs/lib/sugar-reel.html`, `.vhs/*.tape`.

- **Step 6.1 — Kill fabricated upstream (F11):** replace `Mirrors charmbracelet/sugar-reel …` in
  `Mode.php`, `RendererFactory.php`, `AsciiRenderer.php`, `HalfBlockRenderer.php`, `GraphicsRenderer.php`
  with the established no-upstream prior-art note ("No single upstream — drawn from maxcurzi/tplay,
  seatedro/glyph, joelibaceta/video-to-ascii"), matching `Reel.php`/`Sync.php`. (`grep -rn
  'charmbracelet/sugar-reel' src/` must return 0.)
- **Step 6.2 — `Reel::withFps` (F18):** change to `withFps(?float $fps): self` (or add
  `withAutoFps()`); fix the doc-comment so signature and "pass null to auto-detect" agree.
- **Step 6.3 — `examples/play.php` (F19):**
  - Guard every `$argv[1]` read with `?? ''`; no-arg → synthetic (friendly default) with **no
    warnings**; `-h`/`--help` → help. Drop the contradictory `=== ''` check.
  - Use `getenv()` not `$_ENV` for `SUGAR_REEL_COLS/ROWS`; clarify they **override** the runtime size
    (which now arrives via `WindowSizeMsg`).
  - **De-dup** the synthetic generator: new `src/Synthetic.php` (`Synthetic::generate(): string` →
    multi-frame animated gradient GIF) used by **both** `Reel::play()` (replacing
    `Reel::buildSyntheticGif`) and `play.php`. Make it **animated** (≥16 phase-shifted frames) and have
    the synthetic demo set `->withLoop(true)` so it actually moves and repeats — fixing the false
    "fans this single frame out so the player loops" comment.
- **Step 6.4 — `LumaRamp` (F20):** add ramp selection — `Player`/`Reel::withRamp(string $name)` threaded
  into the buffer/ascii char lookup (use `LumaRamp::ramp($name)[$luma]` instead of the hard-coded
  `char()`), exposing `minimal`/`standard`/`dense`. Reword the class doc so it doesn't claim BT.709
  while using BT.601 weights (state plainly: BT.601 luma `(77R+150G+29B)>>8`, as tplay uses).
- **Step 6.5 — Docs & VHS:** update `README.md` (modes table, controls incl. resize/loop, **known
  limitation**: audio at 1.0× only off-speed; prior-art credit), `CALIBER_LEARNINGS.md` (new gotchas:
  rowsPerCell contract, rebuildDecoderAt, WindowSizeMsg handling, /dev/null sinks),
  `docs/lib/sugar-reel.html`. Regenerate the `.vhs/*.tape` GIFs with **candy-vcr** (not upstream vhs;
  ~6 min/tape; GIFs are committed — root memory). Confirm `sugar-reel` is still in
  `.github/workflows/vhs.yml` `all=(...)`.

**Phase 6 DoD**
- [ ] No fabricated upstreams; `withFps` honest; example warning-free; one animated synthetic source.
- [ ] Ramp selectable; README documents controls + the A/V-speed limitation; VHS regenerated.

---

## 7. End-to-end verification (after all phases)

Run the original plan's capability matrix plus the new regression surface:

1. **Per-lib green:** `cd sugar-reel && composer update && vendor/bin/phpunit` — 0 failures; skips only
   the binary-absent gated tests. Repeat for any foundation lib whose path-repo wiring changed;
   `php tools/check-path-repos.php` reports closed.
2. **Mode matrix** (`examples/play.php`): truecolor TTY → HalfBlock fills the screen; `NO_COLOR`/256 →
   Ansi256 path; sixel-capable terminal → sixel. In each, exercise **every** control:
   - `space` play/pause; `←/→` seek (video **and audio** jump together); `[`/`]` speed (**no** jump or
     freeze, smooth rate change); `0`–`9` percentage seek lands proportionally (**not** frame 0); `m`
     cycles only supported modes and the picture stays terminal-sized in each.
3. **Resize:** drag the terminal larger/smaller mid-play → video re-scales (no over-wide/over-tall
   lines; honors the TUI render invariant).
4. **ffmpeg-absent:** hide ffmpeg → a `.gif` still plays at full height (HalfBlock); a `.mp4` shows a
   clear message, no hang.
5. **EOF:** let a short clip end → non-loop stops cleanly with `[ended]` and **no CPU spin**; `withLoop`
   wraps seamlessly. Synthetic demo animates and loops.
6. **A/V over ≥30 s:** audio stays roughly in sync at 1.0× (frame-skip resync working); seeking keeps
   them together.
7. **Hang/leak audit:** `pgrep ffmpeg|ffplay|mpv` returns nothing after quit; repeated backward-seeks
   don't accumulate ffmpeg processes (F21).
8. **CI:** green on the **master push** (not the force-all PR run); `vhs.yml`/`ci.yml` still discover
   `sugar-reel`.

---

## 8. Sequencing, risk & rollback

- **Order is dependency-driven:** 1 (safety) → 2 (geometry + B1/B2) → 3 (timeline) → 4 (auto/resize,
  reuses B2) → 5A/5B (cleanup) → 6 (docs/examples/VHS). Phases 2–4 each heavily touch `Player.update`;
  shipping them sequentially (ship-as-you-go) avoids self-conflict.
- **Highest blast radius:** Phase 3.2 (videoTime rename + `Sync::targetFrame` signature) touches the
  pacing core and `SyncTest`. Land it alone-ish within its PR; rely on the tick-driven regression test.
- **Golden churn:** only Phase 5B.3 (Ansi256/TrueColor coalescing) and Phase 6.5 (VHS) intentionally
  change committed bytes — regenerate goldens/GIFs in the same commit; no other phase should alter
  snapshots (if it does, that's a red flag to investigate, per the TUI invariants memory).
- **Rollback:** each phase is an independent PR; revert in reverse dependency order. B1/B2 are additive
  (new method/helper) so safe to keep even if a later phase is reverted.
- **Out of scope (note, don't build):** audio time-stretch for non-1.0 speed; `Export/` (deferred Step
  8 in `video_plan.md`); braille/dither/edge-glyph polish.

---

## 9. Quick estimate

| Phase | Items | Rough size |
|---|---|---|
| 1 — Resource safety | F7, F8, F9 | S–M |
| 2 — Geometry | F5, F2, F21 (+B1,B2,B3) | M |
| 3 — Timeline | F1, F4, F6, F12 | M |
| 4 — Autodetect | F3, F10, F2-tail | M |
| 5A — Dead code | F13, F16 | S |
| 5B — Render perf | F17, F14, F15 | S–M |
| 6 — Docs/examples | F11, F18, F19, F20 (+VHS) | M |

7 PRs total (Phase 5 = two). Every functional fix lands with a regression test that fails on `master`
first — directly closing the **G1** gap where the current tests masked the bugs (reflection-injected
state, "asserts only that something changed").
