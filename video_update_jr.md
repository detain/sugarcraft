# sugar-reel — remediation plan **JR** (finish the Phase-6 + Phase-5B gaps)

> Companion to [`video_update.md`](video_update.md) (the 21-item, 6-phase post-audit fix plan — **Phases 1–5
> shipped correctly; all 7 PRs #973/#974/#978/#980/#982/#984/#985 are merged**). This **JR plan** closes the
> gaps a follow-up audit (2026-06-03) found in the *final* phase: **F19 (synthetic source) was essentially
> skipped, F20 (ramp selection) was only half-built, and two planned test guards (F14 parity, F6 audio) were
> never added** — plus a tail of doc/correctness defects. Nothing here fails the suite today (192✓ / 4 skip),
> but the **synthetic demo is broken from a user's POV** (one static frame that ends instantly + two PHP
> warnings) and two safety nets are missing.
>
> **First action on execution:** none — this file lives at repo root so every spawned agent can read it.

---

## 0. Source-of-truth facts (verified during the JR audit — do NOT re-derive)

These were confirmed by reading the current `master` code; build on them.

1. **All 6 phases of `video_update.md` are merged.** Git: `git log --oneline -- sugar-reel/` shows
   `d4e729de` (Phase 6), `68baa2b2` (5B), `d54c97a4` (5A), `3f2dd3ef` (4), `a25eec96` (3), `2742abd0` (2),
   `7e032a25` (1). The `video_prompt.md` "resume @ Phase 3" handoff is **stale** — do not trust it; trust
   the code.
2. **Baseline:** on `master`, clean tree, `cd sugar-reel && vendor/bin/phpunit` = **192 passing / 4 skipped**
   (the 4 skips are binary-*absent* tests that skip *because* ffmpeg/ffplay/ffprobe ARE present). `failOnWarning`
   is on, so the suite tolerates **zero** deprecations/warnings.
3. **DecoderFactory routes by extension** ([`DecoderFactory.php`](sugar-reel/src/Decode/DecoderFactory.php)):
   `preg_match('/\.gif$/i', $source)` → `GifDecoder`; else ffmpeg → `FfmpegDecoder`; else GifDecoder fallback.
   **⇒ the synthetic source MUST be a real, decodable `*.gif` file** (both `Reel::play()` and `play.php` feed
   the path straight into `Player::open()` → `DecoderFactory::create()`).
4. **PHP's GD cannot write *animated* GIFs.** `imagegif()` writes exactly one frame. This is *the* reason the
   current synthetic is a single static frame. To animate it you must assemble a multi-frame GIF89a yourself.
   Two viable routes (pick one — see B1):
   - **Reuse:** [`candy-vcr/src/Encode/PhpGifEncoder.php`](candy-vcr/src/Encode/PhpGifEncoder.php) — class
     `SugarCraft\Vcr\Encode\PhpGifEncoder` with `encode(...)` / `isAvailable()` / `name()`. **But sugar-reel
     does NOT currently depend on candy-vcr** (`grep -c candy-vcr sugar-reel/composer.json` → 0), so this adds
     a `sugarcraft/candy-vcr` require **and** a path-repo (+ its transitive closure) — run
     `php tools/check-path-repos.php --fix`. candy-vcr is a *VCR/testing* lib; pulling it into a runtime
     component is arguably wrong coupling.
   - **Self-contain (PREFERRED):** hand-roll a tiny animated-GIF assembler in `src/Synthetic.php` using the
     classic **GD-per-frame-splice** technique — render each frame with `imagecreatetruecolor` → `imagegif()`
     into an output buffer (`ob_start`/`ob_get_clean`), then stitch the frames into one GIF89a with a
     `NETSCAPE2.0` looping app-extension + a Graphic-Control-Extension (delay) per frame. GD does the LZW per
     frame, so you never implement LZW. ~120–180 LOC, **no new dependency**. This keeps sugar-reel's dep
     surface minimal — recommended.
5. **`Player` is `final`** ([`Player.php:51`](sugar-reel/src/Player.php)) → you CANNOT subclass it to inject a
   test seam. Audio is constructed inline (`new AudioPlayer(...)`) at
   [`Player.php:751`](sugar-reel/src/Player.php) (seek) and [`:363`](sugar-reel/src/Player.php) (loop restart);
   `openForTest()` hardcodes `audioPlayer: null` ([`:183`](sugar-reel/src/Player.php)). So F6 currently has
   **no test seam** — that's why it's untested. See B2 for the fix.
6. **`Player` ctor param order** ([`:71`](sugar-reel/src/Player.php)) — memorize it; every change that adds a
   field must update **all** `new self(...)` sites AND the test helper:
   ```
   decoder, mode, speed, paused, videoTime, frameIndex, currentFrame, lastTickTime,
   fps, totalFrames, cellsW, cellsH, videoPath, audioPlayer, ended, loop
   ```
   **`new self(...)` / ctor-call sites:** `open()` ~125, `openForTest()` ~169, `withSeek()` backward ~764,
   `withSeek()` forward ~802, `withNewFrame()` ~832, `mutate()` ~860.
   **Test helper:** `PlayerTest::createPlayerWithOverrides()` builds the Player **positionally** via
   `\Closure::bind(fn(...$a)=>new Player(...$a))` over `array_values($values)` (~`PlayerTest.php:1163`). Its
   `$values` array is hand-ordered to match the ctor. **If you add a ctor field and forget this array, every
   PlayerTest fatals.** TIP: **append new fields at the END (after `loop`)** to minimize churn.
7. **candy-palette has no "KittyGraphics" capability.** `Capability` cases are: TrueColor, Color256, Color16,
   NoColor, **Sixel**, **KittyKeyboard**, **ITerm2**, Hyperlinks, BracketedPaste, FocusEvents, BasicAscii
   ([`candy-palette/src/Probe/Capability.php`](candy-palette/src/Probe/Capability.php)). So Kitty *image* mode
   is gated on the *keyboard* protocol (`KittyKeyboard`) — the closest available proxy. This is a documented
   caveat, **not** something to "fix" (no better signal exists); see J3 step 4.
8. **The current `LumaRamp` ramp infra is correct and tested** ([`LumaRamp.php`](sugar-reel/src/Render/LumaRamp.php)):
   `ramp($name)` (memoized 256-entry LUT), `RAMPS = [minimal, standard, dense]`, `char($luma, $ramp='standard')`,
   `compute($r,$g,$b)`. It is simply **not wired to anything user-facing** — both call sites pass no ramp.

---

## 1. Conventions, per-step pipeline, ship cadence  (identical to the parent plan)

Per **step**: (1) **Implementer** — `composer update` in the lib if a local failure looks like stale vendor;
implement exactly that step; follow conventions (`declare(strict_types=1)`, PSR-12/PSR-4, `final` unless a
contract, immutable `with*()`/`mutate()`, bare accessors, `::new()`); cite prior art (tplay/glyph/
video-to-ascii), never `Mirrors charmbracelet/...` where no upstream exists. (2) **Reviewer** — review ONLY
that step's diff (correctness, conventions, security: every external-CLI arg via **arg-array** to `proc_open`,
never a shell string; reuse). (3) **Fixer** — loop reviewer↔fixer to `CLEAN` (cap 4). (4) **Tester** —
**regression-first**: write the test that FAILS on current code (capture the failure), then make it green; for
subprocess code use a backgrounded `pkill` watchdog (`timeout` does NOT kill `proc_open`/PTY hangs). (5)
**Documenter** — README / CALIBER_LEARNINGS / doc-comments / `docs/lib/sugar-reel.html` as touched. (6)
**Ship** — ship-as-you-go (`ship-pr`): `git checkout -b ai/sugar-reel-jr-<short>` → stage ONLY touched
`sugar-reel/` (+ `docs/lib/sugar-reel.html`, + root `composer.json` if a dep was added) → commit (author
`Joe Huss <detain@interserver.net>`, end body with the `Co-Authored-By: Claude …` trailer) → push →
`unset GITHUB_TOKEN && gh pr create` → `unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch` →
`git checkout master && git pull --ff-only`.

**This machine SKIPS Caliber** — never run `caliber refresh`; if a hook auto-stages caliber-managed files,
unstage them before committing. **`unset GITHUB_TOKEN` immediately before every `gh` call.**

**Bundling:** one **phase = one PR** (2–4 related items), per the repo's preference. The untracked
`video_*.md` planning docs and any `*_crush*` files are unrelated — **never stage them**.

**Verification gate before every Ship:** `cd sugar-reel && vendor/bin/phpunit` green (only the 4 binary-absent
skips; no warnings); `php tools/check-path-repos.php` reports **closure clean** if any path-repo wiring changed;
the phase's **Definition of Done** all ticked.

**CI note** (root memory): the run-level conclusion is chronically `failure` on every master push (empty-OS-matrix
quirk — red-X's pure-docs pushes too). **Do not chase it / do not loop-poll CI.** Spot-check only the lib
check-runs:
`unset GITHUB_TOKEN && gh api repos/detain/sugarcraft/commits/<merge-sha>/check-runs --jq '.check_runs[]|select(.name|test("sugar-reel|Path-repo"))|"\(.conclusion // .status)\t\(.name)"'`
→ expect `success` for `Test PHP 8.3 · sugar-reel`, `Test PHP 8.4 · sugar-reel`, `Coverage · sugar-reel`,
`Path-repo closure`, `render (sugar-reel)`.

---

## 2. Traceability — gap → JR phase

| # | Gap (carried from `video_update.md`) | Sev | Status today | JR Phase.Step |
|---|---|---|---|---|
| F19 | Synthetic source not de-duped/animated/looping; false "loops" comment; no-arg warnings | **High** | ❌ skipped (only `getenv`/help done) | **J1.1–J1.4** |
| — | VHS demo gif shows the static synthetic (predates Phase-6 commit) | Low | ❌ stale | **J1.5** |
| F20 | Ramp not user-selectable (`withRamp` absent); both call sites hardcode default | Med | ⚠️ infra only | **J2.1–J2.2** |
| F20-doc | BT.709/601 mislabel in 3 docblocks | Low | ⚠️ class-comment only | **J2.3** |
| — | README ascii-ramp characters don't match any real ramp | Low | ❌ wrong | **J2.3** |
| F14 | Half-block parity test (inline Buffer vs Mosaic renderer) | Med | ❌ never added | **J3.1** |
| F6 | Audio-realign-on-seek test (+ test seam) | Med | ⚠️ code present, untested | **J3.2** |
| — | Kitty image mode gated on `KittyKeyboard` cap — undocumented caveat | Low | ⚠️ note it | **J3.3** |
| — | `[ended]  0 restart` hint misleads when `totalFrames==0` | Low | ⚠️ edge | **J3.3** |

> **Solidly done in Phases 1–5 — do NOT touch:** F1,F2,F3,F4,F5,F7,F8,F9,F10,F11,F12,F13,F15,F16,F17,F18,F21
> + B1(`Mode::rowsPerCell`)/B2(`rebuildDecoderAt`)/B3(mode-aware fakes), all with real regression tests, and the
> G1 reflection cleanup (`setTotalFrames` helper deleted). The four CALIBER_LEARNINGS patterns
> (rowsPerCell, rebuildDecoderAt, WindowSizeMsg, /dev/null sinks) are present.

---

## 3. Cross-cutting building blocks

### B1 — `src/Synthetic.php` (one animated source, used everywhere) *(J1.1)*
Single source of truth for the demo pattern. Public API mirroring the repo style:
```php
namespace SugarCraft\Reel;

final class Synthetic
{
    /** Default on-disk path for the generated demo GIF. */
    public const DEFAULT_PATH = '/tmp/sugar-reel-synthetic.gif';

    /**
     * Generate an ANIMATED rainbow-gradient GIF (≥16 phase-shifted frames) and
     * return its path. Falls back to a tiny static 1×1 GIF when ext-gd is absent
     * (so callers never fatal). No single upstream — synthetic test pattern.
     *
     * @param int $w 120  @param int $h 60  @param int $frames 16  @param int $delayCs centiseconds/frame
     */
    public static function generate(
        string $path = self::DEFAULT_PATH,
        int $w = 120,
        int $h = 60,
        int $frames = 16,
        int $delayCs = 8,
    ): string { /* GD-per-frame-splice; see §0 fact 4, route 2 */ }
}
```
- **Animate** by phase-shifting the gradient per frame, e.g. `b = 255 * (($x + $y + $f*$w/$frames) % $w)/$w`
  (a hue sweep that visibly moves). ≥16 frames.
- Both consumers call it: `Reel::play()` (replace the private `buildSyntheticGif()` body with
  `Synthetic::generate()`) and `examples/play.php` (replace its `buildSyntheticGif()` function +
  `SYNTHETIC_GIF_PATH` const with a call to `Synthetic::generate()`).
- The GD-absent fallback keeps the existing 1×1 transparent-GIF bytes currently at
  [`Reel.php:200`](sugar-reel/src/Reel.php).

### B2 — Player audio test seam (inject an audio factory) *(J3.2)*
`Player` is `final`, so add an **optional injected factory** so a test can intercept `AudioPlayer` creation:
- New ctor field **appended at the END** (fact 6): `private readonly \Closure $audioFactory`
  with signature `fn(string $path, ?int $startMs): AudioPlayer`.
- `open()` default: `audioFactory: static fn(string $p, ?int $ms = null): AudioPlayer => new AudioPlayer($p, $ms)`;
  build the initial `$audioPlayer` via `($audioFactory)($videoPath, null)` when `$source->hasAudio`.
- Replace the two inline `new AudioPlayer($this->videoPath, $ms)` ([`:751`](sugar-reel/src/Player.php),
  [`:363`](sugar-reel/src/Player.php)) with `($this->audioFactory)($this->videoPath, $ms)`.
- Thread `audioFactory` through `mutate()`, both `withSeek()` `new self(...)`, `withNewFrame()`, and
  `onReachedEnd()`'s `mutate(...)` (mutate carries it automatically once added).
- `openForTest(..., ?\Closure $audioFactory = null, ?AudioPlayer $audioPlayer = null)` — default the factory to
  the real one when null; allow injecting a spy `AudioPlayer` as the initial audio.
- **Update `PlayerTest::createPlayerWithOverrides()` `$values`** to include `audioFactory` in the matching
  (last) position, defaulting to the real factory.
- **Lighter fallback** (if you want zero ctor churn): add only `?AudioPlayer $audioPlayer = null` to
  `openForTest`, drive a paused seek with a spy `AudioPlayer` as initial audio, assert the spy's `stop()` was
  called once; cover the offset with a **pure-math** assertion that `round($targetIndex/$fps*1000)` equals the
  expected ms. (`AudioPlayer` is non-`final` and `buildCommand()` is `protected`, so a recording spy subclass
  is trivial — see `tests/AudioPlayerTest.php` for the live spawn pattern.) The factory seam is preferred
  because it lets you assert the **new** player's `startMs` directly.

---

## Phase J1 — Synthetic source (F19)  *(PR: `ai/sugar-reel-jr-synthetic`)*

**Why first:** it's the only **user-visible** breakage and it unblocks a meaningful VHS regen. Closes the exact
defects the parent plan's Step 6.3 listed but never implemented.

**Current broken state (evidence):**
- Two copies of the generator: [`Reel.php:194-221`](sugar-reel/src/Reel.php) (private `buildSyntheticGif`) and
  [`examples/play.php:78-103`](sugar-reel/examples/play.php) (function `buildSyntheticGif`).
- Both produce **one static frame** (`imagegif($im, …)` once). `Reel::play()` never sets loop, so
  `Reel::new()->play()` / `php examples/play.php synthetic` shows a still gradient that hits EOF after ~1 tick →
  `[ended]`.
- False comment still verbatim at [`play.php:74-77`](sugar-reel/examples/play.php): *"The GifDecoder fans this
  single frame out so the player loops over it repeatedly."*
- No-arg invocation emits **two** `Undefined array key 1` warnings — [`play.php:110`](sugar-reel/examples/play.php)
  guards only the first `$argv[1]` read with `?? ''`; `|| $argv[1] === '-h' || $argv[1] === ''` are unguarded.
  Repro: `php -d error_reporting=E_ALL -r '$argv=["play.php"]; var_dump(($argv[1]??"")==="--help"||$argv[1]==="-h"||$argv[1]==="");'`
  → 2 warnings.

### Step J1.1 — Create `src/Synthetic.php` (B1)
Animated (≥16 frames) GD-per-frame-splice GIF generator + GD-absent fallback. Namespace `SugarCraft\Reel`.

### Step J1.2 — Wire `Reel::play()` to it
- Replace the body of `buildSyntheticGif()` (or delete it) so the unbound path uses `Synthetic::generate()`.
- **Make the synthetic demo loop:** when `$path === ''`, force `$loop = true` for the synthetic Player so it
  actually repeats (the parent plan's "synthetic demo `->withLoop(true)`"). E.g. in `play()` compute
  `$loop = $this->path === '' ? true : $this->loop;` and pass that to `Player::open()`. (Document that the
  built-in demo always loops.)

### Step J1.3 — Fix `examples/play.php` (F19 core)
- Replace its local `buildSyntheticGif()` + `SYNTHETIC_GIF_PATH` with `Synthetic::generate()` (import
  `SugarCraft\Reel\Synthetic`).
- **No-arg warnings:** guard EVERY `$argv[1]` read. Rewrite the dispatch as e.g.
  `$arg1 = $argv[1] ?? '';` then branch on `$arg1` (`--help`/`-h` → help+exit; `''`/`'synthetic'` → synthetic;
  else file). No bare `$argv[1]` anywhere.
- **Kill the false comment** at lines 74-77; describe the now-animated, looping synthetic honestly.
- For the synthetic branch, run it through the looping facade (`Reel::open($path)->withLoop(true)->…` or rely on
  J1.2 if you route synthetic through `Reel::new()`), so the example loops like the facade demo.
- Keep `getenv()` for `SUGAR_REEL_COLS/ROWS` (already correct) and the clamp.

### Step J1.4 — Tests (regression-first)
- `SyntheticTest`: `Synthetic::generate()` returns a path to a file whose bytes start with `GIF8` and that
  **candy-flip decodes into ≥2 frames** (decode via `SugarCraft\Flip\Decoder::decode($path, 8, 8)` and assert
  `count` ≥ 2 — this is the regression that fails against the old single-frame generator). Gate on
  `extension_loaded('gd')`; assert the fallback path returns a valid tiny GIF when GD is stubbed/absent (or
  just assert the happy path under GD and `markTestSkipped` otherwise).
- A `play.php`-level guard is awkward (it launches the TUI); instead unit-test the **arg-parsing** by extracting
  nothing — simplest is to assert `Synthetic` is the single source (no `buildSyntheticGif` symbol remains:
  `grep -c buildSyntheticGif src/ examples/` → 0). Optionally a tiny CLI smoke: run
  `php -d error_reporting=E_ALL examples/play.php --help` in a sub-process and assert **no** `Warning:` on
  stderr and exit handled (help path doesn't start the program).

### Step J1.5 — Regenerate VHS
- Re-render `.vhs/play.tape` → `.vhs/play.gif` with **candy-vcr** (NOT upstream vhs; ~6 min/tape; GIFs ARE
  committed — root memory). The tape already runs `php examples/play.php synthetic`; now it shows motion. If the
  static end-screen made the old tape boring, bump the `Sleep` after `Space` so the loop is visible. Confirm
  `sugar-reel` stays in `.github/workflows/vhs.yml` `all=(...)`.

**Phase J1 DoD**
- [ ] One `src/Synthetic.php`; **zero** `buildSyntheticGif` symbols remain (`grep -rn buildSyntheticGif src/ examples/` → 0).
- [ ] Synthetic GIF is animated (candy-flip decodes ≥2 frames) and the demo **loops** (facade + example).
- [ ] `php -d error_reporting=E_ALL examples/play.php` (no args) and `--help` emit **no PHP warnings**.
- [ ] False "loops" comment gone; example comments describe real behavior.
- [ ] `.vhs/play.gif` regenerated via candy-vcr; suite green.

---

## Phase J2 — Ramp selection wiring + doc truth (F20)  *(PR: `ai/sugar-reel-jr-ramp-docs`)*

**Why:** the parent plan's DoD "Ramp selectable" is unmet — the infra exists but nothing threads it. Both render
paths hardcode the default: [`Player.php:614`](sugar-reel/src/Player.php) and
[`AsciiRenderer.php:56`](sugar-reel/src/Render/AsciiRenderer.php) both call `LumaRamp::char((float)$luma)`.

### Step J2.1 — Thread a `ramp` through `Reel` → `Player`
- `Reel`: add `private readonly string $ramp` (default `'standard'`); thread through ctor, `new()`, `open()`,
  and the private `with()`; add `public function withRamp(string $name): self` and a bare `ramp(): string`
  accessor. **Validate** the name against `LumaRamp` ramps (`minimal|standard|dense`); on unknown, either throw
  `\InvalidArgumentException` (repo style: "no silent failures") or coerce to `'standard'` — prefer the throw
  for an explicit bad arg, matching CONTRIBUTING's guidance.
- `Reel::play()` passes `$this->ramp` to `Player::open(..., ramp: $this->ramp)`.
- `Player`: add `string $ramp` ctor field **appended at the END** (after `loop`; or after `audioFactory` if J3
  lands first — coordinate ordering). Thread through `open()`, `openForTest(..., string $ramp = 'standard')`,
  `mutate()`, both `withSeek()` `new self(...)`, `withNewFrame()`. Update
  `PlayerTest::createPlayerWithOverrides()` `$values` (fact 6).
- Use it: [`Player.php:614`](sugar-reel/src/Player.php) → `LumaRamp::char((float)$luma, $this->ramp)`.

### Step J2.2 — Thread `ramp` into the direct-render path (Ansi256)
- `AsciiRenderer`: add `public function __construct(private readonly string $ramp = 'standard') {}` and use
  `LumaRamp::char((float)$luma, $this->ramp)` at line 56.
- `RendererFactory::create(Mode $mode, string $ramp = 'standard')` → pass `$ramp` into `new AsciiRenderer($ramp)`
  for the Ascii/Ansi256/TrueColor cases (HalfBlock/graphics ignore it). Keep the param **optional** so existing
  callers/tests don't break.
- `Player::renderDirect()` → `RendererFactory::create($this->mode, $this->ramp)`.
- (Note: TrueColor & Ascii go through `Player::frameToBuffer` → covered by J2.1; Ansi256 goes through
  `renderDirect` → covered here. Both paths now honor the selected ramp.)

### Step J2.3 — Doc truth: BT.601 + README ramp chars
- Fix the **BT.709→BT.601** mislabels (the weights `(77,150,29)>>8` are BT.601/SMPTE-C, not BT.709):
  - [`LumaRamp.php:91`](sugar-reel/src/Render/LumaRamp.php) `compute()` docblock (says BT.709 + 0.2126/0.7152/0.0722).
  - [`AsciiRenderer.php:18`](sugar-reel/src/Render/AsciiRenderer.php) "Luminance formula: BT.709".
  - [`RgbFrame.php:11`](sugar-reel/src/Decode/RgbFrame.php) "computed as BT.709" (and `RgbFrame` doesn't compute
    luma at all — drop or correct the stray line).
  - Match the wording already used in `LumaRamp`'s **class** docblock (the one correct spot).
- **README ramp chars** ([`README.md:71`](sugar-reel/README.md)) currently `` .:-;+=*#@`` — replace with the
  **actual** default (`standard`) ramp `` .,:;i1tfLCG08@`` and, now that ramps are selectable, add a short note
  documenting `minimal`/`standard`/`dense` + `Reel::withRamp('dense')`. (Optionally a `--ramp` example.)
- Minor DRY (optional): replace the inlined luma formula in `Player::frameToBuffer` (`:613`) and
  `AsciiRenderer::render` (`:55`) with `LumaRamp::compute($r,$g,$b)` so there's one formula. Low priority.

**Phase J2 DoD**
- [ ] `Reel::withRamp('dense'|'minimal'|'standard')` changes the rendered characters end-to-end (Buffer path AND
      Ansi256 direct path) — proven by a test that renders the same frame with two ramps and asserts the output
      char sets differ.
- [ ] Unknown ramp name handled per policy (throw or documented coercion) with a test.
- [ ] No docblock claims BT.709 while using BT.601 weights; README ramp chars match the real default ramp.
- [ ] Suite green; **no golden churn** outside any ramp-test goldens you intentionally add.

---

## Phase J3 — Test guards & caveats (F14, F6, minors)  *(PR: `ai/sugar-reel-jr-test-guards`)*

**Why:** two safety nets the parent plan promised were never added; both protect already-shipped behavior.

### Step J3.1 — Half-block parity test (F14)
- **Context:** the runtime renders HalfBlock through the **inline** `Player::frameToBuffer` path
  ([`Player.php:536-539`](sugar-reel/src/Player.php) routes HalfBlock to the `else`/Buffer branch). The Mosaic
  [`HalfBlockRenderer`](sugar-reel/src/Render/HalfBlockRenderer.php) (wired at
  [`RendererFactory.php:96`](sugar-reel/src/Render/RendererFactory.php), it delegates to
  `MosaicHalfBlockRenderer` at `HalfBlockRenderer.php:41`) is **never hit by the Player** — only by direct
  factory use/tests. So the two impls can silently drift. The parent plan chose to KEEP both and add a parity
  test (do **not** rewrite the buffer path — avoids golden churn).
- **Test:** for a small synthetic `RgbFrame` (e.g. 4×4 with known colors), render it both ways:
  (a) the inline path — construct a HalfBlock Player via `openForTest`/`createPlayerWithOverrides` with that
  frame as `currentFrame` and assert on `view()`; (b) `(new HalfBlockRenderer())->render($frame, Mode::HalfBlock)`.
  Parse both into per-cell `▀` + (fg,bg) color pairs and assert **equivalent** (same dimensions, same `▀`
  glyph count, same fg/bg per cell). Tolerate harmless format differences (SGR ordering, trailing reset) — the
  invariant is "same colored half-blocks." Document the intentional inline-vs-Mosaic split in a comment in both
  source files so future readers know the parity test guards it.

### Step J3.2 — Audio-realign-on-seek test (F6) + seam (B2)
- Add the B2 audio-factory seam (or the lighter fallback). Then:
- **Test (behavioral):** open a `/fake` Player **with a spy `AudioPlayer`** (recording subclass) as initial
  audio, **paused**, then `withSeek(targetIndex)` (forward or backward). Assert: old spy `stop()` called exactly
  once; a **new** AudioPlayer was created with `startMs == (int)round($targetIndex/$fps*1000)`; `start()` NOT
  called while paused (and IS called when not paused). With the factory seam you can capture the new `startMs`
  directly; otherwise assert the offset via the pure-math path.
- **Test (pure math, unconditional):** assert the offset formula for a couple of `(targetIndex, fps)` pairs so
  the arithmetic is pinned even without ffplay.
- This faithfully closes the **G1** gap for F6 (the only high/med fix that shipped untested).

### Step J3.3 — Document the two caveats (no code-behavior change)
- **Kitty-via-keyboard cap** (fact 7): add a one-line note where the capability is read
  ([`RendererFactory.php:45`](sugar-reel/src/Render/RendererFactory.php) and
  [`Player.php:479`](sugar-reel/src/Player.php)) and a CALIBER_LEARNINGS entry: *Kitty image mode is gated on
  `Capability::KittyKeyboard` because candy-palette exposes no `KittyGraphics` capability; revisit if one is
  added.*
- **`[ended] 0 restart` edge:** when `totalFrames == 0` (live streams; and a synthetic GIF whose probe yields no
  duration), digit-seek is a no-op ([`Player.php:459`](sugar-reel/src/Player.php)) so `0` can't restart. Either
  (a) make the status line conditional — show `0 restart` only when `totalFrames > 0`, else show
  `space replay` / omit; or (b) allow `0` to restart even when `totalFrames==0` by treating it as
  `withSeek(0)`. Prefer (a) (smaller blast radius); add a `view()` test asserting the hint text matches the
  `totalFrames` state. (Now that the synthetic loops via J1, this is mostly a stream concern.)

**Phase J3 DoD**
- [ ] Parity test fails if the inline HalfBlock cell colors and the Mosaic renderer's diverge; passes today.
- [ ] Audio realign: a spy proves old-stopped + new-at-correct-`startMs` + start-iff-playing; pure-math offset
      test runs unconditionally; `openForTest` can inject audio (no more hardcoded `null`-only).
- [ ] Caveats documented (source note + CALIBER_LEARNINGS); ended-hint matches `totalFrames` state with a test.
- [ ] Suite green.

---

## 4. End-to-end verification (after J1–J3)

1. **Per-lib green:** `cd sugar-reel && composer update && vendor/bin/phpunit` — 0 failures, only binary-absent
   skips; if candy-vcr was added (J1 route 1), `php tools/check-path-repos.php` reports closed.
2. **Synthetic demo:** `php examples/play.php` (no args) → **no PHP warnings**, animated gradient that **moves
   and loops**; `q` quits cleanly; `Reel::new()->play()` likewise loops.
3. **Ramp:** `Reel::open(f)->withRamp('dense')` vs `->withRamp('minimal')` visibly differ in ascii/ansi256;
   bad name → `InvalidArgumentException` (or documented coercion).
4. **Parity:** the F14 test guards inline-vs-Mosaic HalfBlock.
5. **Audio seek:** F6 spy test green; no orphaned ffplay (`pgrep ffplay` empty) in any live-gated test
   (watchdog-guarded).
6. **Docs:** `grep -rn 'BT.709' sugar-reel/src` → 0 mislabels; README ramp chars match `standard`.
7. **VHS:** `.vhs/play.gif` shows motion; `vhs.yml`/`ci.yml` still discover `sugar-reel`.
8. **CI:** confirm the lib check-runs are `success` on the **master push** (not the force-all PR run).

---

## 5. Sequencing, risk & rollback

- **Order:** J1 (user-visible + unblocks VHS) → J2 (ramp + docs) → J3 (test guards). Each an independent PR;
  revert in reverse order.
- **Highest blast radius:** any **ctor field add** (B2 `audioFactory` in J3, `ramp` in J2). They touch every
  `new self(...)` site AND `PlayerTest::createPlayerWithOverrides()` — append at the end, update the helper in
  the **same** commit, run the full suite before ship. If both J2 and J3 add a field, the **second** PR must
  re-sync the helper for **both** fields.
- **Dependency add (J1 route 1 only):** adding `sugarcraft/candy-vcr` needs its path-repo + transitive closure
  in `sugar-reel/composer.json` — `php tools/check-path-repos.php --fix`, then `composer update`. **Route 2
  (self-contained `Synthetic.php`) avoids this entirely and is preferred.**
- **Golden/GIF churn is intentional in exactly two spots:** any ramp-test goldens you add (J2) and the VHS regen
  (J1.5). If any **other** snapshot changes, STOP — it's a render regression (TUI-invariants memory).
- **Out of scope (note, don't build):** audio time-stretch for non-1.0× speed (documented limitation stays);
  a real `KittyGraphics` capability in candy-palette; `Export/`; braille/dither/edge-glyph polish; rewriting the
  inline HalfBlock buffer path.

---

## 6. Quick estimate

| Phase | Items | Size |
|---|---|---|
| J1 — Synthetic source + VHS | F19 (4 steps) + VHS regen | M (VHS ~6 min render) |
| J2 — Ramp wiring + doc truth | F20 wiring (2) + BT.601/README docs | S–M |
| J3 — Test guards + caveats | F14 parity, F6 seam+test, 2 caveats | M (ctor seam churn) |

3 PRs. Every functional change lands with a regression test that **fails on current `master`** first
(animated-frame-count for F19, two-ramp-diff for F20, inline-vs-Mosaic for F14, spy-startMs for F6) — directly
closing the residual **G1** gaps the final phase left open.
