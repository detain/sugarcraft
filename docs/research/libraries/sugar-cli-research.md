# SugarCli (sugar-cli) — charmbracelet/fang parity research

**Date:** 2026-11-14
**Project:** sugar-cli (PHP CLI presentation helpers, `SugarCraft\Cli\`)
**Sources:** [charmbracelet/fang](https://github.com/charmbracelet/fang), sugar-core `ColorProfile` (detection authority), sugar-style (`Style`/`Border`), docs/MATCHUPS.md pairing row

---

## Executive Summary

fang is a *command framework*: cobra wiring, config loading, self-update, and
styled output composed into one application shell. sugar-cli ports **only the
output layer** — the shapes fang renders, never the machinery that decides
what to render or how the process exits. This document is the parity
source-of-truth: every fang surface gets an explicit disposition, so a future
audit reads this table instead of re-deriving intent from docblocks.

Parity verdict: **output-shape parity is the contract; the command, config,
and process surfaces are deliberately unported** (library-only, per
`sugar-cli/README.md` § What this is not).

## fang surface inventory

| fang surface | sugar-cli disposition | Where / why |
|---|---|---|
| Version command (`fang.VersionCmd`, `-v` injection) | OUT-OF-SCOPE | Command wiring is the app's job; sugar-cli returns strings and owns no argv, no flags, no version type. |
| Self-update (GitHub releases updater) | OUT-OF-SCOPE | Process/network surface; a presentation library that phones home is a non-starter. App or tooling decides. |
| `fang.json` user config (theme, style key) | OUT-OF-SCOPE | sugar-cli reads no config and writes no state. `Theme` is the *persistence boundary*: the app loads the user's choice and passes a `Theme` (or `Theme::byName()`) in. |
| Cobra help sections (typed flags: required/optional/default, `FLAGS` / `GLOBAL FLAGS` split) | OUT-OF-SCOPE (shape ported) | `HelpText` renders the same kind of page — USAGE line, description, titled two-column `KEY  description` grids — but flag metadata is whatever the caller puts in its key strings; no cobra typing. |
| Colorprofile detection (lipgloss/termenv env handling) | SHIPPED (different authority) | `Theme::detect()` delegates the whole ladder to sugar-core `ColorProfile::detect()` — the roster authority is sugar-core's docblock, not Go `colorprofile`. The default answer is memoized on an input fingerprint (`Theme.php`, SC-T2). |
| Image splash (`x/splash` half-blocks) | SHIPPED as text art | `Logo` is first-party Unicode box-drawing art (`sugarcraft()` preset, 66 cells — see `Logo.php:14-22`: no upstream Logo counterpart; the image route exists in `sugar-image`, not here). `Logo::fromAscii()` serves code-page hosts. |
| Exit codes (`fang.ExitError` plumbing) | OUT-OF-SCOPE | Every helper returns a string; STDOUT/STDERR routing and the exit status belong to the caller. |

## Divergences worth naming

**Width never touches a device.** fang (via termenv) queries the terminal for
its size. sugar-cli's `WidthProbe` resolves width from an explicit parameter,
the *exported* `COLUMNS`, or the 80-cell fallback — and never issues a
size ioctl (`tput` subprocesses are equally out). Theme detection does ask
whether a stream is a tty via `stream_isatty()` (an ioctl-backed check inside
PHP, not a subprocess and not a size probe). See README § What this is not.

**Single-cell Unicode output is a hard assumption.** The color ladder governs
escape bytes only; glyphs, rules, and box-drawing chrome are emitted as fixed
UTF-8 with per-presenter ASCII remedies (`Section` `$rune`, `Border::ascii()`,
`Logo::fromAscii()`). See README § Output assumptions.

## Open gates (pending owner ruling — non-blocking for parity bookkeeping)

| Row | Question | Status |
|---|---|---|
| SC-G1 (finding 014) | Should `Theme::detect()` consult device tty-tier capability (beyond the env/stream ladder sugar-core defines)? | pending ruling |
| SC-G2 (findings 003/006) | Ship ASCII-chrome APIs for `Frame` / `StatusLine` (currently no ASCII substitution)? | pending ruling |

## Cross-references

- Cited docblocks: `sugar-cli/src/Theme.php` (detection delegation + memo),
  `sugar-cli/src/Logo.php:14-22` (first-party art, fang splash contrast).
- Pairing rows: `docs/MATCHUPS.md` status table (SugarCli) and upstream
  pairing table (`fang` — output shape only).
- Findings corpus: `reviews/annotations/sugar-cli.md`, `reviews/raw/sugar-cli__*.md`.
