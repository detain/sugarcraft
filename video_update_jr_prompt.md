# sugar-reel remediation **JR** — new-session startup prompt

*Paste everything below the line into a fresh Claude Code session (run from the repo root,
`/home/sites/sugarcraft`) to execute the JR fix plan. This finishes the gaps the **final phase** of the
original remediation left behind. Phases 1–5 of `video_update.md` are correct and merged — **do NOT redo
them.***

---

You are running a **follow-up remediation** on the **`sugar-reel`** library (terminal video player:
mp4/gif → ascii/ansi/half-block/sixel/kitty). The original 21-item, 6-phase audit fix (`video_update.md`,
PRs #973/#974/#978/#980/#982/#984/#985) is **fully merged** — but a JR re-audit found the **final phase
(docs/examples) was partly skipped** and **two planned test guards were never added**. The concrete JR plan is
[`video_update_jr.md`](video_update_jr.md) (3 PRs / 3 phases, gap→phase traceability, two building blocks,
per-phase DoD, end-to-end matrix). **Your job: execute J1 → J2 → J3, in that order, one PR per phase,
ship-as-you-go.**

**Read first:** [`video_update_jr.md`](video_update_jr.md) (authoritative — read §0 facts, §1 conventions, §3
building blocks, all three Phases, §4–§5). [`video_update.md`](video_update.md) is the parent plan (context;
Phases 1–5 done). The stale [`video_prompt.md`](video_prompt.md) says "resume @ Phase 3" — **ignore it, it
predates the later merges.**

## What's broken (the JR scope) — verified on `master`
- 🔴 **F19 — Synthetic source skipped.** No `src/Synthetic.php`; the generator is **duplicated**
  ([`Reel.php:194`](sugar-reel/src/Reel.php) + [`examples/play.php:78`](sugar-reel/examples/play.php)), produces
  a **single static frame**, the demo **doesn't loop**, the false *"fans this single frame out so the player
  loops"* comment is still at [`play.php:74-77`](sugar-reel/examples/play.php), and **no-arg invocation emits 2
  `Undefined array key 1` warnings** ([`play.php:110`](sugar-reel/examples/play.php) guards only the first
  `$argv[1]`). VHS gif is the stale static demo.
- 🟡 **F20 — Ramp half-built.** `LumaRamp` has `ramp()`/`minimal`/`standard`/`dense` (tested) but **no
  `withRamp` anywhere**; both render sites hardcode the default
  ([`Player.php:614`](sugar-reel/src/Player.php), [`AsciiRenderer.php:56`](sugar-reel/src/Render/AsciiRenderer.php)).
  Plus BT.709→BT.601 mislabels in 3 docblocks and a wrong README ramp string.
- 🟡 **F14 — No half-block parity test.** The runtime uses the inline `Player::frameToBuffer` HalfBlock path;
  the Mosaic `HalfBlockRenderer` is orphaned in the runtime and can drift unguarded.
- 🟡 **F6 — Audio realign untested.** The code (new `AudioPlayer` at the seek offset) is present
  ([`Player.php:747`](sugar-reel/src/Player.php)) but there's no test and **no seam** (`openForTest` hardcodes
  `audioPlayer: null`).

## Key facts learned — saves re-investigation (full detail in `video_update_jr.md` §0)
- **Baseline:** `cd sugar-reel && vendor/bin/phpunit` = **192 passing / 4 skipped** on a clean `master`. The 4
  skips are binary-*absent* tests that skip because ffmpeg/ffplay/ffprobe ARE present. `failOnWarning` is ON —
  zero tolerance for warnings.
- **Synthetic must be a real `*.gif`** — `DecoderFactory` routes `.gif` → `GifDecoder`
  ([`DecoderFactory.php`](sugar-reel/src/Decode/DecoderFactory.php)).
- **GD cannot write animated GIFs** (`imagegif()` = 1 frame). Two routes for B1: **(preferred)** self-contained
  GD-per-frame-splice assembler in `src/Synthetic.php` (no new dep) — render each frame with `imagegif()` into
  an `ob_*` buffer, stitch into one GIF89a with a `NETSCAPE2.0` loop block + per-frame Graphic-Control-Extension
  delay; **(alt)** reuse `SugarCraft\Vcr\Encode\PhpGifEncoder`
  ([`candy-vcr/src/Encode/PhpGifEncoder.php`](candy-vcr/src/Encode/PhpGifEncoder.php)) — but sugar-reel does
  **not** currently depend on candy-vcr, so that adds a require + path-repo closure
  (`php tools/check-path-repos.php --fix`). Prefer self-contained.
- **`Player` is `final`** → no subclass seam. Audio is built inline at
  [`Player.php:751`](sugar-reel/src/Player.php)/[`:363`](sugar-reel/src/Player.php). For F6, inject a
  `\Closure $audioFactory` ctor field (default `fn($p,$ms)=>new AudioPlayer($p,$ms)`); see `video_update_jr.md`
  §3 B2. `AudioPlayer` IS non-final with a `protected buildCommand()` → recording spy subclass is easy.
- **`Player` ctor order** (memorize; adding a field touches **every** `new self(...)` AND the test helper):
  `decoder, mode, speed, paused, videoTime, frameIndex, currentFrame, lastTickTime, fps, totalFrames, cellsW,
  cellsH, videoPath, audioPlayer, ended, loop`. Sites: `open`/`openForTest`/`withSeek`(×2)/`withNewFrame`/
  `mutate`. **`PlayerTest::createPlayerWithOverrides()` builds positionally via reflection (~`PlayerTest.php:1163`)
  — update its `$values` array or every PlayerTest fatals.** **Append new fields at the END (after `loop`).**
- **candy-palette has no `KittyGraphics` capability** — only `KittyKeyboard`
  ([`candy-palette/src/Probe/Capability.php`](candy-palette/src/Probe/Capability.php)). Kitty image mode is
  gated on the keyboard proxy on purpose; **document it, don't "fix" it.**
- **cs-fixer is NOT a CI gate.** Match surrounding style (`static fn(` no space, `enum X: string` no space);
  **never run `php-cs-fixer fix`** (it churns established style).

## Execution model — keep it
Supervisor stays lean. Per phase: spawn **ONE implementer agent at a time** (never concurrent — they collide on
`Player.php`/`Reel.php`) with a precise spec from `video_update_jr.md`. **Regression-first is mandatory** — the
agent writes the test that **FAILS on current `master`** (capture the failure: animated-frame-count for F19,
two-ramp char-set diff for F20, inline-vs-Mosaic for F14, spy-`startMs` for F6), then fixes → PASS, and reports
the `git diff` + both states. The supervisor then **reviews the actual diff**, runs the verification gate, and
ships. Delegate verification/research to a subagent; don't bloat the main context.

## Verification gate before every ship
`cd sugar-reel && vendor/bin/phpunit` green (only the 4 binary-absent skips; **no** warnings/deprecations);
`php tools/check-path-repos.php` reports **closure clean** if any path-repo wiring changed (only if you took the
candy-vcr route in J1); the phase's DoD checklist all ticked.

## Hard guardrails — these bite if missed
- **Skip Caliber on this machine.** Do NOT run `caliber refresh`; if a hook auto-stages caliber files, unstage
  them before committing.
- **`composer update` in the lib** only before trusting a *local* phpunit failure (vendor goes stale; CI
  unaffected). Current vendor is fine (baseline green).
- **External CLIs via arg-array to `proc_open`, never a shell string.** No path ever reaches a shell.
- **Ctor-field discipline:** any added `Player`/`Reel` field → append at end, update every `new self(...)` AND
  `PlayerTest::createPlayerWithOverrides()` `$values` in the **same commit**, run full suite before ship.
- **Ship cadence:** `git checkout -b ai/sugar-reel-jr-<short>` → stage ONLY touched `sugar-reel/` files (+
  `docs/lib/sugar-reel.html` if touched, + root `composer.json` only if you added candy-vcr) → commit (author
  `Joe Huss <detain@interserver.net>`, end body with the `Co-Authored-By: Claude …` trailer) →
  `git push -u origin <branch>` → `unset GITHUB_TOKEN && gh pr create` →
  `unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`.
  **`unset GITHUB_TOKEN` immediately before every `gh` call.** End each phase on clean `master`.
- **Never stage the planning docs** (`video_*.md`) or unrelated `*_crush*` files.
- **Golden/GIF churn is intentional in only two spots:** ramp-test goldens you add (J2) and the VHS regen via
  **candy-vcr** (J1.5; ~6 min/tape; GIFs committed). Any **other** snapshot change → STOP and investigate (TUI
  render-invariant regression).
- **CI:** the run-level conclusion is chronically red on every master push (empty-OS-matrix quirk). **Don't
  chase it / don't loop-poll.** Spot-check lib check-runs only:
  `unset GITHUB_TOKEN && gh api repos/detain/sugarcraft/commits/<merge-sha>/check-runs --jq '.check_runs[]|select(.name|test("sugar-reel|Path-repo"))|"\(.conclusion // .status)\t\(.name)"'`
  → expect `success` for the `sugar-reel` Test/Coverage/render runs + `Path-repo closure`.

## Phase arc (concrete diffs, file:line anchors, and tests in `video_update_jr.md`)
- **J1 — Synthetic source** (`ai/sugar-reel-jr-synthetic`): create animated `src/Synthetic.php` (≥16 frames,
  GD-splice, GD-absent fallback); replace both `buildSyntheticGif` copies with it; make the synthetic demo
  **loop** (facade + example); fix `examples/play.php` no-arg warnings (guard every `$argv[1]`) and kill the
  false "loops" comment; add `SyntheticTest` (candy-flip decodes ≥2 frames = the regression) + a `--help`
  no-warning smoke; **regenerate `.vhs/play.gif` via candy-vcr**.
- **J2 — Ramp wiring + doc truth** (`ai/sugar-reel-jr-ramp-docs`): add `Reel::withRamp(string)` + `ramp()` and
  thread `ramp` → `Player` (Buffer path, `:614`) and → `AsciiRenderer`/`RendererFactory::create` (Ansi256
  direct path); validate unknown names (throw); fix BT.709→BT.601 docblocks
  ([`LumaRamp.php:91`](sugar-reel/src/Render/LumaRamp.php), [`AsciiRenderer.php:18`](sugar-reel/src/Render/AsciiRenderer.php),
  [`RgbFrame.php:11`](sugar-reel/src/Decode/RgbFrame.php)); correct README ramp chars
  ([`README.md:71`](sugar-reel/README.md) → real `standard` ramp `` .,:;i1tfLCG08@``) + document the 3 ramps.
  Test: same frame, two ramps → different char sets.
- **J3 — Test guards & caveats** (`ai/sugar-reel-jr-test-guards`): F14 half-block parity test (inline
  `frameToBuffer` vs `HalfBlockRenderer`, compare per-cell `▀` fg/bg, don't rewrite the buffer path); F6 audio
  seam (B2 factory) + spy test (old stopped, new at `round(target/fps*1000)` ms, start iff playing) + pure-math
  offset test + `openForTest` audio injection; document the Kitty-keyboard-cap caveat (source note +
  CALIBER_LEARNINGS) and make the `[ended] 0 restart` hint conditional on `totalFrames>0` (with a `view()` test).

## Start now
On `master` (clean, **192/4**). Re-run the baseline `phpunit` to reconfirm, then begin **Phase J1**: state its
scope + your first regression test in 3–4 lines — the strongest is **"`Synthetic::generate()` produces a GIF
that candy-flip decodes into ≥2 frames"**, which **fails on `master`** (today's generator emits one frame).
Build `src/Synthetic.php`, wire both consumers, fix the example warnings/comment + looping, regenerate the VHS,
ship the PR, then continue J2 → J3. Ask only on a genuinely ambiguous decision (e.g. self-contained vs candy-vcr
for the encoder — default to **self-contained** unless told otherwise) — otherwise keep shipping.
