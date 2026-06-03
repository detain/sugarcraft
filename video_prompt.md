# sugar-reel remediation — new-session startup prompt  (RESUME @ Phase 3)

*Paste everything below the line into a fresh Claude Code session (run from the repo root,
`/home/sites/sugarcraft`) to continue the fix plan. **Phases 1 and 2 are already shipped and
merged — do NOT redo them. Start at Phase 3.***

---

You are continuing a remediation on the **`sugar-reel`** library (terminal video player:
mp4/gif → ascii/ansi/half-block/sixel/kitty). A post-build audit found 21 issues (F1–F21); a
detailed, phased fix plan lives in [`video_update.md`](video_update.md) (7 PRs across 6 phases,
F1–F21 → phase.step traceability, two shared helpers, per-phase Definition-of-Done, end-to-end
verification matrix). **Phases 1 and 2 are done.** Your job: finish **Phase 3 → 4 → 5A → 5B → 6**,
in that order (dependency-driven — don't reorder), one PR per phase, ship-as-you-go.

**Read first:** [`video_update.md`](video_update.md) (authoritative plan — read Phases 3–6 + §0/§1/§3/§7/§8),
then skim `sugar-reel/src/` so your model matches the *current* code (Phases 1–2 already changed it).
[`video_plan.md`](video_plan.md) is the original build plan — context only, already completed.

## Progress so far — DONE, do not redo
- ✅ **Phase 1 — Resource safety** (PR #973, merged): **F7** ffmpeg stderr → `/dev/null` file sink
  (dropped the `$stderr` property); **F8** audio subprocess gets `/dev/null` file sinks for stdin/out/err
  (no more SIGPIPE on a closed pipe; dropped the dead `=== 0` guard); **F9** end-of-stream handling —
  `Player` gained `public readonly bool $ended` + `private readonly bool $loop`, helpers `onReachedEnd()`
  and `seekTickCmd()`, a `[ended]  0 restart  q quit` status line in `view()`, and `Reel::withLoop()`.
- ✅ **Phase 2 — Geometry** (PR #974, merged): **B1** `Mode::rowsPerCell()` (HalfBlock 2, else 1) +
  `colsPerCell()` (1); **B2** `Player::rebuildDecoderAt()` (**already exists** — closes the old decoder,
  which is the **F21** fix); **F5** `GifDecoder` + `FfmpegDecoder` decode at `cellsH * rowsPerCell(mode)`
  (GIF now fills the screen in HalfBlock); **F2** the `m` handler rebuilds the decoder and cycles ALL 7
  modes (the stale "skip Sixel/Kitty/Iterm2 until Step 6" loop is gone). New test doubles:
  `tests/GeometryFakeDecoder.php` (mode-aware) and `tests/SpyDecoder.php` (counts `close()`).
- **Current baseline:** on `master`, clean tree, `cd sugar-reel && vendor/bin/phpunit` =
  **183 passing / 4 skipped** (the 4 skips are binary-*absent* tests that skip because
  ffmpeg/ffplay/ffprobe ARE present here — expected). Phase commits: 1 = `7e032a25`, 2 = `2742abd0`.
- The untracked `*_crush*` / `open_crush.md` / `.candy-crush-plan/` and the `video_*.md` planning docs
  are unrelated to sugar-reel — **leave them untracked**, never stage them.

## Key facts learned last session — saves re-investigation
- **`rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array` already exists** in
  `Player.php`. Semantics (this RESOLVED an ambiguity in the plan — keep it): when
  `videoPath === '/fake'` it **re-opens the injected decoder** (so a mode-aware fake regenerates frames
  at the new mode) — *not* pure mutate-only; otherwise it `decoder->close()` + `DecoderFactory::create()`
  + advances to `frameIndex`. Backward-seek, the `m` handler, and the loop-restart all route through it.
  **Reuse it for Phase 4's resize (F10) — do not write a second rebuild path.**
- **`elapsed` is still named `elapsed`.** Phase 3.2 renames it → `videoTime`. The Phase-1 EOF/seek code
  still uses the old `elapsed = idx / (fps * speed)` formula in `onReachedEnd()` and `withSeek()` — Phase
  3.2 must convert those to the `videoTime = frameIndex / fps` model when it does the rename.
- **`Player` ctor param order ends:** `..., videoPath, audioPlayer, ended, loop`.
  `PlayerTest::createPlayerWithOverrides()` builds a Player **positionally** via the private ctor
  (`array_values($values)`). If you add/reorder ctor params (e.g. Phase 3 may touch `openForTest`), update
  that `$values` array in the **same order** or *every* PlayerTest fataly breaks. Phase 3.1 also adds a
  `totalFrames` param to `openForTest(...)` and DELETES the reflection `setTotalFrames` helper.
- **CI run-level conclusion is chronically `failure` on EVERY master push** (an empty-OS-matrix quirk —
  it red-X's pure-docs pushes too; unrelated to sugar-reel). **Do not chase it and do not loop-poll CI
  to completion.** Confirm only that the **lib check-runs** are green with ONE spot-check:
  `unset GITHUB_TOKEN && gh api repos/detain/sugarcraft/commits/<merge-sha>/check-runs --jq '.check_runs[]|select(.name|test("sugar-reel|Path-repo"))|"\(.conclusion // .status)\t\(.name)"'`
  — look for `Test PHP 8.3 · sugar-reel`, `Test PHP 8.4 · sugar-reel`, `Coverage · sugar-reel`,
  `Path-repo closure`, `render (sugar-reel)` = success.
- **cs-fixer is NOT a CI gate** (no workflow runs it). The repo's *actual* style uses `static fn(` (no
  space) and `enum X: string` (no space); the `.php-cs-fixer.dist.php` config disagrees on both. **Match
  the surrounding code; never run `php-cs-fixer fix`** — it would churn established style across files.
- F7/F8 live tests are watchdog-guarded **characterisation** tests (a true hang/SIGPIPE repro is itself a
  hazard, and this box is headless/no-ALSA so SIGPIPE never fires). The established spawning-test pattern
  — a backgrounded `pkill` watchdog (cancelled in `finally`) + a PID-unique temp filename, plus
  `SDL_AUDIODRIVER=dummy` for ffplay — lives in `tests/AudioPlayerTest.php::testLiveAudioSpawnStartsStopsAndLeavesNoOrphan`
  and `tests/FfmpegDecoderTest.php::testLiveDecodeWithStderrSinkClosesCleanly`. Reuse it; `timeout` does
  NOT kill a `proc_open` hang.

## Execution model — worked well, keep it
Supervisor stays lean. Per phase: spawn **ONE implementer agent** (one at a time — never concurrent; they
collide on shared files like `Player.php`) with a precise spec; **regression-first is mandatory** — the
agent writes the test, proves it **FAILS on the un-fixed code** (captures the failure), then fixes → PASS,
and reports the `git diff` + both states. The supervisor then **reviews the actual diff itself**, runs the
verification gate, and ships. This is the whole point: the old tests masked these bugs (reflection-injected
state, asserts that only check "something changed").

## Verification gate before every ship
`cd sugar-reel && vendor/bin/phpunit` green (only the 4 binary-absent skips; no deprecations/warnings —
`failOnWarning` is on); `php tools/check-path-repos.php` reports **closure clean** if any path-repo wiring
changed; the phase's DoD checklist all ticked.

## Hard guardrails — these bite if missed
- **Skip Caliber on this machine.** Do NOT run `caliber refresh`. (There is currently no caliber
  pre-commit hook; if one appears and auto-stages caliber-managed files, ignore the nag and unstage them.)
- **`composer update` in the lib only before trusting a *local* phpunit failure** — per-lib `vendor/`
  goes stale (gitignored; CI unaffected). The current vendor is fine (baseline is green).
- **External CLIs via arg-array to `proc_open`, never a shell string.** No `$path` ever reaches a shell.
- **Ship cadence:** `git checkout -b ai/sugar-reel-<short>` → stage ONLY the touched `sugar-reel/` files →
  commit (author `Joe Huss <detain@interserver.net>`, end body with the `Co-Authored-By: Claude …` trailer)
  → `git push -u origin <branch>` → `unset GITHUB_TOKEN && gh pr create` →
  `unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`.
  **`unset GITHUB_TOKEN` immediately before every `gh` call.** End each phase on a clean `master`.
- **Golden/GIF churn is intentional in ONLY two places:** Phase 5B.3 (Ansi256/TrueColor SGR coalescing)
  and Phase 6.5 (VHS via **candy-vcr**, not upstream vhs; ~6 min/tape; GIFs are committed). Regenerate
  goldens/GIFs in the same commit. If ANY other phase alters a snapshot, stop and investigate — it means a
  render regression.

## Remaining phase arc (see `video_update.md` for the concrete diffs, file:line anchors, and tests)
- **Phase 3 — Timeline** (`ai/sugar-reel-timeline`) — *highest blast radius; touches the pacing core +
  `SyncTest`*: **F1** populate `totalFrames` in `Player::open()` (`round(duration*fps)`); add it to
  `openForTest` and delete the reflection `setTotalFrames`; digit-seek no-ops when `totalFrames<=0`.
  **F4** video-time accumulator — rename `elapsed`→`videoTime`, advance it by `delta*speed` per tick, so a
  speed change affects only *future* pacing (no retroactive jump/freeze); **drop the `$speed` param from
  `Sync::targetFrame`** and update all callers. **F6** seek realigns audio (stop old, `new AudioPlayer(path,
  round(targetIndex/fps*1000))`, start if not paused); document the known limitation that audio still plays
  at 1.0× off-speed. **F12** delete the dead `Sync::__construct(fps,speed)` + `Sync::reset()` (Sync becomes
  static-only).
- **Phase 4 — Autodetect** (`ai/sugar-reel-autodetect`): **F3** add `RendererFactory::autoMode(): Mode`,
  have `auto()` delegate, and wire `Reel` (`?Mode $mode` null=auto, `withAutoMode()`) → resolved `Mode` →
  `Player::open`; fix `examples/play.php auto`. **F10** handle `WindowSizeMsg` in `Player::update()` →
  `updateResize()` **reusing `rebuildDecoderAt()`**; clamp `cols≥10,rows≥5`; the runtime's initial
  `WindowSizeMsg` now sizes the video (constructor size becomes a provisional override). **F2-tail**
  capability-aware `m` cycle (only offer modes the terminal supports, via the `autoMode` probe).
- **Phase 5A — Dead code** (`ai/sugar-reel-deadcode`): **F13** delete `src/Msg/FrameMsg.php`, its import,
  the dead `if ($msg instanceof FrameMsg)` branch in `update()`, and update `tests/MsgTest.php`. **F16**
  `VideoSource::probe` → `proc_open($cmd, …)` arg-array (no `implode`/shell), stderr to `/dev/null`.
- **Phase 5B — Render perf** (`ai/sugar-reel-render-perf`): **F17** `RgbFrame::toGd()` allocation-free
  (`imagesetpixel($img,$x,$y,($r<<16)|($g<<8)|$b)` on a truecolor image). **F14** half-block parity test
  guarding the inline-Buffer path vs the Mosaic `HalfBlockRenderer` (don't rewrite the buffer path). **F15**
  Ansi256/TrueColor SGR coalescing in `AsciiRenderer::render()` (emit a color once per run, single `\x1b[0m`
  at line/frame end) — **intentional golden churn**, regenerate the affected goldens in the same commit.
- **Phase 6 — Docs/examples** (`ai/sugar-reel-docs-examples`): **F11** replace the fabricated
  `Mirrors charmbracelet/sugar-reel` doc-comments in `Mode.php`, `RendererFactory.php`, `AsciiRenderer.php`,
  `HalfBlockRenderer.php`, `GraphicsRenderer.php` with the no-upstream prior-art note (tplay/glyph/
  video-to-ascii) — `grep -rn 'charmbracelet/sugar-reel' src/` must return 0. **F18** `Reel::withFps(?float)`.
  **F19** fix `examples/play.php` (no-arg → synthetic, no warnings; `getenv()`; `-h`) and introduce a single
  **animated** `src/Synthetic.php` used by both `Reel::play()` and `play.php` (drop the dup
  `buildSyntheticGif`; synthetic demo `->withLoop(true)`). **F20** `LumaRamp` ramp selection
  (`withRamp(string)`, expose minimal/standard/dense; reword the BT.601 doc). Then README (modes/controls
  incl. resize+loop, the audio-at-1.0× limitation, prior-art credit), `CALIBER_LEARNINGS.md` (rowsPerCell,
  rebuildDecoderAt, WindowSizeMsg, /dev/null sinks), `docs/lib/sugar-reel.html`, and regenerate the
  `.vhs/*.tape` GIFs with **candy-vcr** — **intentional GIF churn**. Confirm `sugar-reel` stays in
  `.github/workflows/vhs.yml` `all=(...)`.

## Start now
On `master` (clean, **183/4**). Run the baseline `phpunit` to reconfirm, then begin **Phase 3**: state its
scope + your first regression test in 3–4 lines — the strongest one is the **video-time accumulator** test
(drive N ticks to ~frame K, press `]` to 1.25×, assert the next target advances by only ~`delta*1.25*fps`
with **no** `0.25*videoTime*fps` skip-storm; and `[` from mid-play causes **no** multi-tick freeze), which
fails on `master` because `elapsed` is re-scaled retroactively. Proceed through the pipeline to a merged PR,
then continue 4 → 5A → 5B → 6. Ask only on a genuinely ambiguous decision — otherwise keep shipping.
