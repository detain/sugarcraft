<img src=".assets/icon.png" alt="sugar-colorprofile" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-colorprofile)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-colorprofile)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-colorprofile?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-colorprofile)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarColorprofile

> Formerly published as `sugarcraft/candy-palette` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Palette\` → `SugarCraft\ColorProfile\`. <!-- @rename-keep -->

sugar-colorprofile — terminal color profile detection and color degradation for PHP 8.3+.

## Features

- **Detect terminal color profile** from environment variables and TTY info
- **ColorProfile enum**: the color-depth vocabulary shared by every surface of this library (8 levels, `TrueColor` 24-bit → `Ansi256` 256-color → `Ansi240` fixed slots 16-255 → `Ansi` 16-color → `Ansi8` basic 8 → `Fgbg` two-color fg/bg → `Ascii` black/white → `NoTTY`); the former renderer-facing `Profile` enum is now a deprecated `class_alias` façade over it — every old `Profile::X` spelling still resolves
- **Color conversion**: downsample RGBA colors to any target profile
- **ProfileWriter**: wrap a stream and automatically match its output to the terminal — `degrade()` on the color rungs, full `stripAnsi()` on `NoTTY`
- **ANSI stripping**: `NoTTY` output runs through `Palette::stripAnsi()`, which
  removes SGR/CSI/OSC/DCS sequences plus SS3, APC/PM/SOS strings, the enumerated
  Fe escape pairs and charset selectors — decoration (`\x1b[1m`, `\x1b[4m`) is
  stripped too, so `NO_COLOR=1` loses bold/underline as well. For a color-only
  strip pin the `Ascii` floor: `Palette::withProfile(ColorProfile::Ascii)`
- **Environment-aware**: reads `CLICOLOR_FORCE`, `FORCE_COLOR`, `NO_COLOR`, `CLICOLOR`, `TERM`, `COLORTERM`, `TERM_PROGRAM`, `WT_SESSION`, `GOOGLE_CLOUD_SHELL`, `TMUX`, `STY`, `REDUCE_MOTION`, `PREFERS_REDUCED_MOTION`
- **Probe class**: static convenience layer over the shared detection chain + infocmp Phase 2 upgrade
- **Perceptual color distance**: CIELAB conversion + ΔE*₇₆ / ΔE*₉₄ / ΔE*₀₀ (CIEDE2000) metrics with an opt-in nearest-palette matcher (`NearestColor`), palette Lab values memoized for hot loops, a PCA-bucketed exact lookup index for repeated queries (`PaletteIndex`), and error-compensated quantization for dither chains (`NearestColor::withError()`)

## Install

```bash
composer require sugarcraft/sugar-colorprofile
```

Requires PHP 8.3+ and no PHP extensions — the probes' optional `posix_*` calls
are feature-detected, and an absent/unreadable `infocmp` child degrades the
phase-2 answer instead of failing.

## Quickstart

```php
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\ProfileWriter;

// Detect the terminal's color profile (hero idiom — pass a stream to gate on
// its TTY-ness; a null stream means env-only detection — no TTY gate)
$profile = Palette::detect(STDOUT);

echo "Your terminal supports: {$profile->label()}\n";

// Downsample a TrueColor color to the detected profile. The reusable-palette
// instance form memoizes the detection for repeated conversions:
$palette = new Palette(STDOUT);
$color = new Color(0x6b, 0x50, 0xff); // #6b50ff
$converted = $palette->convert($color);
echo "Converted: {$converted->toHex()}\n";

// Wrap a stream for automatic color degradation on write
$writer = ProfileWriter::wrap(STDOUT);
$writer->write("\x1b[38;2;107;80;255mFancy text\x1b[0m\n");
```

**Expected output.** Run with `STDOUT` piped (CI, `php demo.php | cat`), the two
`echo` lines print exactly `Your terminal supports: No TTY` and
`Converted: #000000` — the step-0 stream gate forces `NoTTY`, and `NoTTY`
degrades the TrueColor test color to black. On a real terminal both lines depend
on the detected profile: the label names whatever rung won, and `#6b50ff`
collapses toward the nearest color the profile can actually render.

**Terms used here.**

- **SGR** — the `ESC[ …m` escape sequence that carries colors and decoration in
  terminal output bytes.
- **terminfo / `infocmp`** — the on-disk per-`TERM` capability database, and the
  CLI that prints one entry (consulted by the optional phase-2 probe).
- **`COLORTERM`, `NO_COLOR`, `FORCE_COLOR`** (with `CLICOLOR`,
  `CLICOLOR_FORCE`) — the env vars users set to override what a terminal
  advertises; the ladder below resolves them in a fixed order.
- **CIELAB / ΔE** — a perceptually-uniform color space and its distance metric;
  they define "nearest" for the color reduction above.
- **TTY vs pipe** — `stream_isatty()` tells an interactive terminal from
  redirected output; the same environment answers differently on each.

## Profiles

| ColorProfile | Colors    | Description                                     | Detection emits? |
|--------------|-----------|-------------------------------------------------|------------------|
| TrueColor    | 16.7M     | Full 24-bit RGB (SGR 38;2, de-facto xterm)      | yes              |
| Ansi256      | 256       | 216 cube + 24 gray + 16 standard                | yes              |
| Ansi240      | 240       | Fixed palette only — slots 16-255 (chafa INDEXED_240) | no — request-only |
| Ansi         | 16        | Standard terminal colors                        | yes              |
| Ansi8        | 8         | Basic ANSI, no bright variants                  | no — request-only |
| Fgbg         | 2         | Two-color fg/bg by brightness (chafa FGBG)      | no — request-only |
| Ascii        | 2         | Black & white                                   | yes — the falsy-`FORCE_COLOR` floor (ladder step 2) |
| NoTTY        | 0         | No color — `stripAnsi()` drops every escape incl. decoration | yes              |

`Profile` is the deprecated alias of `ColorProfile`; `Profile::ANSI256`
and `Profile::ANSI` keep resolving through deprecated constant bridges.

## The detection ladder

The color-depth answer comes from one canonical 13-step chain (`DetectionChain`) —
canonical across every surface of this library; `sugar-core` carries a separate
fork with its own ladder, see Fleet integration below.
`Probe`, `Palette` and `Probe\TerminalProbe` all consult it, so the same
environment never answers differently **at the env-ladder rung**; the final
surface answers may still diverge, because `Probe` layers an opt-in terminfo
phase (Phase 2) on top and `TerminalProbe` the terminfo and `TERM_PROGRAM`
upgrade phases (Phases 2-3) — the chain's answer is the floor those phases can
raise, never the ceiling. Measured: `{TERM_PROGRAM: WezTerm, TERM: xterm}`
answers Ansi on `Palette` but TrueColor on `TerminalProbe` (Phase-3 upgrade).
`README`, the `DetectionChain` docblock and the tests follow this one numbering
(the chain levels `truecolor`/`ansi256`/`ansi`/`ascii`/`none` map to the enum
cases `TrueColor`/`Ansi256`/`Ansi`/`Ascii`/`NoTTY` respectively). The numbered
list below is duplicated in `DetectionChain`'s class docblock — that docblock
carries the enumeration beside this prose; a ladder change must edit both (no
drift pin guards this genre yet):

1. `CLICOLOR_FORCE=1` → TrueColor (overrides every env leg — step 0 can still outrank it)
2. `FORCE_COLOR` (non-empty) → level: numeric 3+ → TrueColor (as do `true`/`yes`/`on` in any case), `2` → Ansi256, `1` → Ansi; every other spelling (`0`/`false`/`off`/…) → `Ascii` floor (color reduced to the black/white pair, decoration kept)
3. `NO_COLOR` (set and **non**-empty; `""` = unset — the supports-color convention, NO_COLOR's own text is presence-based) → the `NoTTY` rung — strips **all** ANSI including decoration (bold/underline gone); for a color-only strip pin `Palette::withProfile(ColorProfile::Ascii)`
4. `CLICOLOR=0` → none (the `NoTTY` rung)
5. `TERM=dumb` (case-insensitive) → none (the `NoTTY` rung)
6. `COLORTERM=24bit|truecolor|yes` → TrueColor (`yes` is the legacy "this is a
   color terminal" marker, honored here as TrueColor — beyond its historical
   meaning; pin a profile explicitly if you want the conservative reading)
7. `TERM_PROGRAM=iTerm.app` (exact spelling, case-sensitive) → TrueColor (inline image + 24-bit history)
8. `WT_SESSION` (set, non-empty) → TrueColor (Windows Terminal)
9. `GOOGLE_CLOUD_SHELL` (set, non-empty) → TrueColor
10. `TMUX` or `STY` set + base TERM begins `screen`/`tmux` → Ansi256
11. TERM contains `-256color`, or equals `xterm-kitty`/`xterm-ghostty` (case-insensitive) → Ansi256
12. TERM begins `xterm`/`screen`/`tmux` (case-insensitive) → Ansi
13. Default → Ansi

Steps 1-2 outrank step 3 **deliberately**: `FORCE_COLOR`/`CLICOLOR_FORCE` are an
active "give me color anyway" override (the chalk/support-colors convention),
while `NO_COLOR` is a passive preference. The asymmetry is pinned by tests — and
carries an accessibility cost, spelled out under the env-var precedence table
below.
Interpolated env values that ride in source strings are sanitized via
`Env::sanitize()` — `TERM` and friends are attacker-influenced over SSH and a
source string gets printed by downstream diagnostics. The guarantee it gives
is exact: the output is always **valid UTF-8** of **at most 128 bytes** —
C0/C1/DEL are stripped as code points (so multibyte characters whose UTF-8
form merely *contains* a 0x80-0x9F byte, like `€`, survive intact), hostile
malformed input is scrubbed to U+FFFD-substituted well-formed text instead of
emitting orphan lead bytes that would break `json_encode()` of a probe report,
and the length cap lands on a character boundary, never mid-sequence.

### Step 0 — the output-stream TTY gate

A `Palette` constructed with a **non-null stream that is not a TTY** answers
`NoTTY` regardless of the whole ladder — including every force override.
Measured: piped STDOUT with `FORCE_COLOR=3` gives `Palette::detect(STDOUT)` → `NoTTY`
while `Probe::colorProfile()` → TrueColor. `ProfileWriter::wrap($stream, …)`
inherits the gate because it delegates to `Palette::detect($stream, …)`, and a
`NoTTY` answer makes `ProfileWriter::write()` strip the ANSI. So every
"overrides everything"/"past every suppression leg" claim below means every
*env* leg: the gate can still outrank them all when the bytes are headed for a
pipe. Surfaces that gate: `Palette::detect($stream, …)` and `ProfileWriter`
with a non-TTY stream. Surfaces that never gate: `Probe`, `AsyncProbe` and
`Probe\TerminalProbe` — they report what the environment *can* do, not where
the bytes are about to land.

**Phase 2 (infocmp) — probe surfaces only:** `Probe`/`AsyncProbe` consult
`infocmp -1` only when the ladder answers `Ansi` or `Ansi256`; `Tc`/`RGB`
capability *names* (name-position match via `Probe\TerminfoParser`, never a
whole-output regex) upgrade the answer to TrueColor. The Sixel leg accepts
exactly the folded names `sixel`/`sixl`/`sixelscreenmode`; the legacy `Ss1`/`Ss2`
user caps are deliberately NOT markers — they initialize X3.64 status lines, not
sixel graphics. Before any surface execs
the binary, `TERM` must pass one shared name-shape predicate
(`Probe\InfocmpBinary::isSafeTermName()`): alnum first byte, then only the
characters real terminfo names use — so a value beginning with `-` never
reaches infocmp (quoted, it is still an *option* to the program: `TERM=-D`
measured as infocmp exiting 0 and printing its terminfo search path to the
probe's stdout), and the env-anchored chain answer stands. `TerminalProbe` runs its
terminfo phase more broadly: unless the env phase delivered a suppression
decision (the `NoColor` capability — `NO_COLOR`, `CLICOLOR=0`, `TERM=dumb`) or
the falsy-`FORCE_COLOR` `Ascii` floor, it queries the database even when the
env rung already answered TrueColor — its report covers capabilities beyond
color depth. That suppression check is a decision, not one input among many,
which is why it also gates the escape-query phase. A bare `Palette::detect()`
never runs terminfo; it is the ladder plus the step-0 stream gate.

**The synchronous probe is bounded:** every `infocmp` child on the sync faces
launches through one door (`Probe\InfocmpBinary::runBounded()`): a 5 s
wall-clock deadline with SIGTERM → 1 s grace → SIGKILL escalation, an 8 KiB
keep-a-prefix stdout cap, an array-form argv launch with no shell in the
path, the child's stderr drained rather than redirected, and fail-open
semantics — anything short of a clean, on-time dump leaves the env-anchored
chain answer standing. Results are memoized per `TERM` for the process
lifetime (including recorded misses), so a long-lived consumer pays at most
one child per terminal type; `Probe::_reset()` drains the table in tests.

### Env-var precedence

The ladder's env legs, one table row per variable, in ladder order:

| Env var | Ladder step | Effect |
|---------|-------------|--------|
| `CLICOLOR_FORCE=1` | 1 | force TrueColor past every env suppression leg, `NO_COLOR` included (step 1; the step-0 stream TTY gate still outranks it on stream-taking surfaces). Silently re-enables color over `NO_COLOR` — see the a11y caveat under this table |
| `FORCE_COLOR` | 2 | non-empty level override honored by **all** surfaces through the chain (step 2): numeric 3+ → TrueColor (as do `true`/`yes`/`on` in any case), `2` → Ansi256, `1` → Ansi; every other spelling (`0`/`false`/`off`/`no`/…) lands on the `Ascii` floor — decoration kept, color reduced to the black/white pair. Outranks `NO_COLOR` by design — see the a11y caveat under this table |
| `NO_COLOR` | 3 | set to a **non-empty** value (`""` = unset — the supports-color convention, NO_COLOR's own text is presence-based) → no color via the `NoTTY` rung / `NoColor` capability — `stripAnsi()` drops **all** ANSI including decoration (`\x1b[1m`/`\x1b[4m` gone). Contrast: falsy `FORCE_COLOR` lands the `Ascii` floor (step 2) which keeps decoration and reduces color to the black/white pair. Want NO_COLOR to keep bold/underline? Pin `Palette::withProfile(ColorProfile::Ascii)` |
| `CLICOLOR=0` | 4 | suppress color |
| `TERM=dumb` | 5 | case-insensitive; suppresses color — outranks `COLORTERM` (dumb is a promise of minimal capabilities) |
| `COLORTERM=24bit\|truecolor\|yes` | 6 | TrueColor (exact value match, case-insensitive); `yes` is the legacy color-terminal marker, promoted here beyond its historical "some color" meaning |
| `TERM_PROGRAM` | 7 | `iTerm.app` (exact spelling, case-sensitive) → TrueColor rung (step 7); phase 3 additionally maps WezTerm/vscode/Ghostty → TrueColor, iTerm.app → ITerm2, Hyper → Hyperlinks |
| `WT_SESSION` | 8 | set, non-empty → Windows Terminal, TrueColor |
| `GOOGLE_CLOUD_SHELL` | 9 | set to any non-empty value (even `false`) → TrueColor |
| `TMUX` / `STY` | 10 | set + base TERM already begins `screen*`/`tmux*` → Ansi256 (step 10). Under tmux/screen the inner TERM *is* the base — no re-anchoring to the outer terminal happens |
| `TERM` | 11-12 | ladder levels: `*-256color`/`xterm-kitty`/`xterm-ghostty` → Ansi256; `xterm*`/`screen*`/`tmux*` prefix → Ansi (all case-insensitive) |
| `REDUCE_MOTION` / `PREFERS_REDUCED_MOTION` | — | non-empty and not `0/false/no/off` → `Probe::reducedMotion()` true (motion preference, outside the color ladder) |

**Accessibility caveat for the force rows.** An exported `FORCE_COLOR`/
`CLICOLOR_FORCE` — from a shell alias, a dotfile, or a CI image — silently
re-enables color for users who set `NO_COLOR` as an accessibility signal,
because steps 1-2 outrank step 3. Integrators honoring `NO_COLOR` as an
accessibility contract should check `Probe::isNoColor()` explicitly before
honoring any force override.

`NoTTY` labels the zero-color rung; suppression vars land there even on a real
TTY. The two names that surface it are not synonyms: `Probe::isNoColor()` names
the `NO_COLOR` leg only, while the `NoColor` capability is broader — it covers
all three suppression vars (`NO_COLOR`, `CLICOLOR=0`, `TERM=dumb`).
`ProbeReport::colorDescription()` is report prose; the enum's `label()` and
`description()` are the canonical vocabulary.

## Color Degradation

```php
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Palette;

$color = new Color(100, 50, 255, 255);

// Downsample to an explicit profile (static one-off shortcut)
$ansi256 = Palette::toProfile($color, ColorProfile::Ansi256); // nearest cube/gray index
$ansi    = Palette::toProfile($color, ColorProfile::Ansi);    // nearest of the 16 slots

echo $ansi256->toAnsi256Foreground(); // "\x1b[38;5;…m"
echo $ansi->toAnsi16Foreground();     // "\x1b[…m" — 4-bit SGR

// Or convert against the detected terminal
$auto = (new Palette())->convert($color);
```

**The two "no color" rungs differ.** A `NoTTY` palette (what `NO_COLOR`,
`CLICOLOR=0` and `TERM=dumb` land on, and what the step-0 stream gate forces)
runs every write through `Palette::stripAnsi()`: SGR, full ECMA-48 CSI, OSC,
DCS/APC/PM/SOS strings, SS3, the enumerated Fe pairs and charset selectors all
disappear — **decoration too**, so `\x1b[1m` bold and `\x1b[4m` underline are
gone with the color. The `Ascii` floor (a falsy `FORCE_COLOR` spelling, step 2)
instead keeps decoration and reduces color to the black/white pair. Two
accepted gaps in the strip regex family: exotic final bytes outside the
enumerated classes (`ESC Z`, `ESC %…`, `ESC #8`, `ESC |`/`}`/`~`) pass through
as literal text. To keep
bold/underline while dropping color regardless of environment, pin the floor:
`(new Palette())->withProfile(ColorProfile::Ascii)`.

## Perceptual Color Distance (opt-in)

Default nearest-color matching stays Euclidean (RGB) for byte-for-byte back-compat.
For perceptual matching — where blues, greens and near-grays rank correctly — opt in:

```php
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\ColorMath;
use SugarCraft\ColorProfile\DeltaE;
use SugarCraft\ColorProfile\NearestColor;
use SugarCraft\ColorProfile\PaletteIndex;

// sRGB -> linear -> XYZ (D65) -> CIELAB
$lab  = ColorMath::toLab(64, 96, 128);         // ['l' => …, 'a' => …, 'b' => …]
$lab2 = (new Color(200, 30, 90))->toLab();     // same chain, from a Color

// ΔE metrics (CIE 15:2004; CIEDE2000 per Sharma/Wu/Dalal 2005)
$d76 = DeltaE::cie76($lab, $lab2);
$d94 = DeltaE::cie94($lab, $lab2);             // graphic-arts kL = 1
$d00 = DeltaE::cie2000($lab, $lab2);

// Nearest ANSI-256 palette index under a chosen strategy (default: EUCLIDEAN)
$matcher = new NearestColor(ColorDistance::Cie2000);
$index   = $matcher->ansi256(new Color(30, 120, 30));

// Arbitrary palette (list or map), returns the key of the winner
$brand = ['logo' => new Color(10, 20, 30), 'accent' => new Color(240, 250, 255)];
$key   = $matcher->closest(new Color(12, 34, 56), $brand);

// Repeated lookups over one fixed palette: the PCA-bucketed index.
// Answers EXACTLY what the brute-force scan answers (lowest index on ties) —
// it only trades construction cost for a sublinear lookup. Euclidean and Cie76
// only; those are the metrics that admit a distance-preserving rotation.
$idx  = PaletteIndex::fromPalette(NearestColor::palette256(), ColorDistance::Cie76);
$near = $idx->nearest(new Color(30, 120, 30));  // === (new NearestColor(ColorDistance::Cie76))->ansi256(...)

// Error-compensated lookup, chafa chafa-palette.c:1160-1197: pass the
// per-channel accumulator by reference; the query is pushed by error * 0.9/16
// before matching, and the new residual is written back for the next pixel.
$acc  = [0.0, 0.0, 0.0];                                    // start of the scanline
$d1   = $matcher->withError(new Color(90, 90, 90), $acc);    // dither chain step 1
$d2   = $matcher->withError(new Color(91, 90, 89), $acc);    // ... inherits the push
$none = null;
$plain = $matcher->withError(new Color(12, 34, 56), $none);  // null accumulator = plain ansi256() behaviour
```

The 256-entry and 16-entry palette Lab tables are memoized statically (fingerprint-guarded
against `StandardColors` reassignments, so the cache can never freeze a stale table), so a warm
CIEDE2000 search costs ~1 ms on a stock laptop; recomputing the 256 sRGB→Lab
conversions per search would add an estimated ≈40 % on top — the figure is the
`NearestColor` header note, while `NearestColorTest` pins the absolute warm-search
cost and the memoization itself, not the ratio.

The matcher's 256-entry table is the canonical xterm palette: slots 0-15 are the
ANSI-16 set, 16-231 the 6×6×6 cube at channel levels 0/95/135/175/215/255, and
232-255 the `8+10n` gray ramp — derived from `Color::fromAnsi256Index()` and
cross-pinned to sugar-core's decoder (`Ansi256TableParityTest`). Earlier
versions shipped a private even-51-step cube; that table never matched a real
terminal and is gone.

## Probe — Static Environment Detection

The `Probe` class provides the static convenience layer over the ladder above —
color profile, explicit no-color/force-color flags, and reduced-motion
preference. Use it directly when you need raw detection values without
constructing a `Palette` instance.

```php
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\ColorProfile;

// Detect the negotiated color profile
$profile = Probe::colorProfile(); // ColorProfile::TrueColor|Ansi256|Ansi|Ascii|NoTTY
echo $profile->label(); // "TrueColor"

// Check for explicit disable/enable flags
if (Probe::isNoColor()) {
    // NO_COLOR is set to a non-empty value — disable all color output
}
if (Probe::isForceColor()) {
    // CLICOLOR_FORCE=1 — force full color regardless of terminal
}

// Reduced-motion preference (REDUCE_MOTION or PREFERS_REDUCED_MOTION,
// non-empty and not 0/false/no/off)
if (Probe::reducedMotion()) {
    // Skip animations, spinners, and other motion
}
```

### Trust boundary

Detection's inputs are the env vars in the precedence table above — and,
through phase 2, the terminfo database the `infocmp` child consults. The
binary is launched from a fixed absolute-path roster, and every exec surface hands the child a minimal environment built in one place (`Probe\InfocmpBinary::childEnvironment()`: the queried `TERM` and a fixed `PATH` only). `TERMINFO`, `TERMINFO_DIRS` and `HOME` all steer `infocmp`'s lookup (a search path you can inspect with `infocmp -1 -D`), and inheriting them from a parent whose environment an attacker controls would hand that attacker the database; they are therefore never passed. What remains outside this library's reach is the database itself: an attacker who can write to the system terminfo directories still owns the answer, so the name-position guard in `Probe\TerminfoParser` protects the *parse* from a hostile database but cannot vouch for the *source*. Treat every detection answer as a rendering hint, never as an authorization signal: enabling a feature, granting trust, or selecting a transport on the basis of a `Tc`/`RGB`/`Sixel` capability names a privilege the environment holds, not one this library holds.

## ColorProfile Enum

`ColorProfile` is this library's color-depth vocabulary, shared by every surface of it: the detection chain speaks it, `Palette`/`ProfileWriter` degrade against it, and libs that need the raw profile value consume it directly (live roster, derived from `use SugarCraft\ColorProfile\` imports in the production `src/` trees of the monorepo — candy-code references the library in its own `tests/` only: sugar-log, sugar-image, sugar-spring, sugar-video, candy-mdview). `Profile` remains only as a deprecated `class_alias` façade over it. Five of the arms are detection-emittable (`TrueColor`/`Ansi256`/`Ansi`/`Ascii`/`NoTTY` — `Ascii` lands from a falsy `FORCE_COLOR` spelling, step 2 of the ladder); `Ansi240`, `Ansi8` and `Fgbg` are request-side vocabulary only.

```php
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\ProfileWriter;

$profile = Probe::colorProfile();

// Human-readable label
echo $profile->label(); // "TrueColor"

// Request-side arms (Ansi240/Ansi8/Fgbg — detection never emits these):
$writer = ProfileWriter::wrap(STDOUT)->withProfile(ColorProfile::Ansi240); // never touch slots 0-15
```

| Case      | Value       | Label        |
|-----------|-------------|--------------|
| `TrueColor` | `'truecolor'` | TrueColor  |
| `Ansi256` | `'ansi256'`  | ANSI 256     |
| `Ansi240` | `'ansi240'`  | ANSI 240     |
| `Ansi`   | `'ansi'`     | ANSI         |
| `Ansi8`  | `'ansi8'`    | ANSI 8       |
| `Fgbg`   | `'fgbg'`     | FGBG         |
| `Ascii`  | `'ascii'`    | ASCII        |
| `NoTTY`  | `'notty'`    | No TTY       |

## Fleet integration

`sugar-core` carries a **second, parallel color-depth enum** —
`SugarCraft\Core\Util\ColorProfile` — with its own `detect()` implementation.
It is a distinct type (int-backed, five cases `NoTty=0`/`Ascii=1`/`Ansi=2`/
`Ansi256=3`/`TrueColor=4`, against this library's string-backed eight-case
enum), and its consumers do not require this library: `sugar-style` and
`candy-top` resolve their color depth through the Core fork. The
"canonical chain"/"shared vocabulary" language in this README is scoped to
this library's surfaces (`DetectionChain`/`Probe`/`Palette`/`ProfileWriter`);
it never held across the two forks, and the forks answer the same environment
differently. Measured divergences (fresh process, `TERM=xterm-256color`, no
stream argument):

| Env | Core `ColorProfile::detect()` | This library (ladder) |
|-----|-------------------------------|------------------------|
| `NO_COLOR=1` | `Ascii` — suppression floors at the black/white pair, decoration kept | `NoTTY` rung (source `env:NO_COLOR`) — full-strip behavior |
| `FORCE_COLOR=1` | `TrueColor` — any truthy force var jumps straight to full color | `Ansi` — the level table is honored (`1` → 16 colors) |

The structural roots of those flips: the Core fork has no level table and no
full-strip rung for suppression — any truthy `FORCE_COLOR` is `TrueColor`, and
`Ascii` is its suppression floor (`NoTty` arises there only from a non-TTY
stream or `TERM=dumb`, never from `NO_COLOR`/`CLICOLOR=0`); it answers from the
environment alone, with no terminfo phase. This library keeps Chalk's level
semantics, floors a *falsy* `FORCE_COLOR` at `Ascii`, and floors suppression at
the `NoTTY` rung, with `Probe` adding the opt-in terminfo phase on top. Neither
is wrong on its own terms — but anything that can observe both enums must not
assume agreement, and should pin which ladder produced a given value.
Reconciling the forks (a single enum, or a documented bridge between the two)
is an owner-level ruling, recorded as follow-up #26 in
`findings/plan_sugar-colorprofile.md`.

## Architecture

```
SugarCraft\ColorProfile\
├── Color          — RGBA color value object with conversion methods (Color::namedColors() lists standard names)
├── ColorProfile    — THE color-depth enum (8 levels, richest→simplest)
├── Profile         — deprecated class_alias façade over ColorProfile
├── DetectionChain  — shared env ladder (the one precedence core; see the ladder above)
├── Env             — env resolution (hermetic-replace rule), value read, source sanitize
├── Palette         — instance detection over the chain + degrade/strip; adds the stream TTY gate and the describe()/comment() plain-language capability announcements
├── Probe           — static env-probe layer (colorProfile/isNoColor/isForceColor/reducedMotion)
├── AsyncProbe      — ReactPHP child-process infocmp probe (bounded children, settles from the chain)
├── Probe\TerminalProbe — phased capability report (env chain → terminfo → escape queries → fallbacks)
├── Probe\Capability / Probe\ProbeReport / Probe\InfocmpBinary / Probe\TerminfoParser — capability enum, sourced report, resolved infocmp path, name-position terminfo parser
├── ColorMath       — sRGB→linear→XYZ (D65)→Lab conversion
├── DeltaE          — ΔE*₇₆ / ΔE*₉₄ / ΔE*₀₀ metrics
├── ColorDistance   — distance strategy enum (Euclidean/Cie76/Cie94/Cie2000)
├── NearestColor    — nearest-palette matcher (key-guarded memoized Lab, error-compensation carrier)
├── PaletteIndex    — PCA-bucketed exact accelerator for repeated lookups
├── StandardColors  — the 16 ANSI colors (mutable statics deprecated in favour of all()/fromIndex()/catalog(); see the deprecation window below)
├── ProfileWriter   — stream wrapper for automatic color degradation
└── Lang            — i18n strings
```

## Terminal capability probes

Beyond the env ladder above, the library exposes a richer capability surface:

- **`Probe\TerminalProbe`** — phased probe returning a **`Probe\ProbeReport`**:
  phase 1 runs the shared `DetectionChain` for the color-depth rung (so the
  ladder cannot drift between surfaces), phase 2 reads terminfo via
  `infocmp -1`, phase 3 maps `TERM_PROGRAM` advertised capabilities (interactive
  streams only), phase 4 applies floors. Each detected **`Probe\Capability`**
  carries its source string (`env:VAR`, `terminfo:cap` or `fallback:reason`).
  The live roster is the 11 enum cases — `TrueColor`, `Color256`, `Color16`,
  `NoColor`, `Sixel`, `KittyKeyboard`, `ITerm2`, `Hyperlinks`,
  `BracketedPaste`, `FocusEvents`, `BasicAscii` — with `BracketedPaste` and
  `FocusEvents` RESERVED: no probe producer sets them yet, so `has()` stays
  false until one is wired. Terminfo capabilities are matched at the NAME
  position by `Probe\TerminfoParser` (a `des=` string mentioning "Tc" does not
  promote anything) and exec'd at the **`Probe\InfocmpBinary`-resolved absolute
  path** (the same path the availability gate checked — the binary is never
  looked up on PATH, and because `infocmpAvailable()` is an overridable seam
  the terminfo leg re-reads that path and fails closed: a null composes no
  command, so an override can never demote the absolute binary into a
  TERM-shaped command word). `TERM_PROGRAM` maps only terminals that actually
  implement what they advertise — notably `Apple_Terminal` does NOT set `ITerm2`
  (Terminal.app has no OSC 1337 images). Reports answer through
  `allWithSource()`/`has()`/`source()` plus the `colorDescription()` prose, and
  bridge into the shared vocabulary via `toColorProfile()`
  (TrueColor→`TrueColor`, Color256→`Ansi256`, Color16→`Ansi`,
  NoColor→`NoTTY`, empty report→`Ansi` ladder-default parity,
  BasicAscii-only report→`Ascii`).
- **`AsyncProbe::colorProfile(?LoopInterface $loop = null)`** — the same infocmp
  check in a ReactPHP child process; returns a promise. The child spawns ONLY
  on the loop you pass in: called without one, the promise resolves in-place
  through the bounded sync face (an implicit singleton pickup would park the
  child on a loop its caller may never run, settling only in somebody else's
  `Loop::run()`). Every child is bounded (5 s ceiling → TERM, 1 s grace →
  KILL, the KILL timer cancelled when the child exits first, both pipes
  drained into keep-a-prefix 8 KiB buffers so a chatty child can neither
  wedge on a full pipe nor grow the accumulator without end), a spawn that
  fails to launch releases its cap slot and settles from the chain instead
  of throwing, and slot reclamation kills AND reaps children whose loop was
  abandoned — so the public `AsyncProbe::MAX_CONCURRENT_PROBES = 4` cap can
  never wedge, and abandoned probes leave neither zombies nor pipe fds
  behind. **Every async settle answers from the detection chain, except the
  TrueColor promotion read from a `Tc`-declaring child dump** — which itself
  only runs on the Ansi and Ansi256 rungs and sees at most the capped
  prefix; no blocking exec ever runs on the loop thread. The no-loop
  fallback is the sync `Probe::colorProfile()`; its phase-2 `infocmp` exec
  rides the same bounded door as the child (above), but it still blocks the
  calling thread for up to that ceiling — inside a live loop, pass the loop
  and stay off the tick path. Env suppression, a missing infocmp binary, an
  unset or `dumb` TERM, a TERM whose shape infocmp would read as an option,
  a chain rung other than Ansi/Ansi256, the cap being reached, or a
  timeout/kill all settle to the chain's answer. Results are advisory only.

## Overriding detection

- `CLICOLOR_FORCE=1` forces TrueColor past every other env variable — but the
  step-0 stream TTY gate (above) still outranks it: on a non-TTY stream the
  `Palette`/`ProfileWriter` answer is `NoTTY` regardless of any force.
- `FORCE_COLOR` picks the level (`3`/`2`/`1`, falsy → `Ascii` floor).
- Program-side, pin the answer: `(new Palette())->withProfile(ColorProfile::Ansi)`
  (immutable clone) or `ProfileWriter::wrap(STDOUT)->withProfile(...)`.
- A `Palette`/`TerminalProbe` constructed with a non-empty injected `$env` map
  is **hermetic**: the map completely replaces the ambient environment
  (`Env::resolve()`), it does not overlay it — pass every key you care about.
- The **empty** map is not hermetic: `Env::resolve([])` falls through to the
  ambient environment — `[]` means "use what the process already has", never
  "no environment". A test wanting a controlled env must inject at least one
  key (this trap was hit live during review).

## Deprecation: StandardColors mutable statics

Deprecated as of 2026-10-24.

The **16** `public static Color` properties on `StandardColors` (`$black` …
`$brightWhite`) are now **deprecated** as a *writable* surface. They are
process-global mutables: an assignment anywhere rewrites the value for every
reader, and nothing pins them against reassignment.

**Nothing changes today.** The properties keep working byte-for-byte — reads,
writes, and every current consumer behave exactly as before. They are removed
in favor of an immutable, constant-backed equivalent no earlier than 2.0.0
(each tagged `REMOVED-IN: 2.0.0`). Until then this is a deprecation *window*:
migrate now, no rush, no breakage.

Use the non-mutating read API instead (not deprecated):

| Instead of | Use |
| --- | --- |
| `StandardColors::$red` | `StandardColors::fromIndex(1)` or `StandardColors::all()[1]` |
| `StandardColors::$brightBlack` | `StandardColors::fromIndex(8)` |
| enumerating the palette | `StandardColors::all()` (indexed 0–15) · `StandardColors::catalog()` (names) |
| a private/global override table | build your own `array` from `all()` — the `Color` values are immutable, so a local array is already isolated |
| constructing fresh standard colors | `new Color(0xcd, 0x00, 0x00)`, `Color::parse('#cd0000')` or `Color::fromAnsi256Index(1)` |

`fromIndex(int)` is bounds-checked and throws `OutOfBoundsException` outside
`0..15`. A structural test pins both halves of the window: every deprecated
property is still a live writable static (no accidental neuter), and the
**16**-property count here matches the code.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
