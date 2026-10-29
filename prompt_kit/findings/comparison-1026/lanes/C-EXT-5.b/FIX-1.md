# C-EXT-5.b — FIX-1 (round 1)

Commit: `7237dafc04c7cc939363bce01866b55f3bd77f0e` on `cc/C-EXT-5.b` (cat-file verified), parent `9d0750cd8`. Author+committer detain@interserver.net verified via SEPARATE %ae/%ce greps ==1 each, zero Co-Authored-By. Explicit-path staging (`git add candy-code/src/Chat.php`), tree clean, never pushed.

## Finding fixed
MINOR-1 (step-local, REVIEW-1 §1): `candy-code/src/Chat.php:10882-10888` — `runPaletteAction()`'s header docblock lied for prefill labels.

## Change (docblock-only, +21/−6, one file, inside the already-declared method region)
- After "leaves the draft alone." — added the PREFILL exception clause: a label naming no PaletteAction but matching a live catalog row is a draft write (blank draft filled with the bare command; non-blank kept under the draft-preservation law with a notice), not an action.
- Rewrote the mid-turn paragraph: "refused exactly as Enter" now scoped to ACTION labels; states the seam (a) divergence explicitly — this public arm allows prefill mid-turn (draft writes touch no turn state) while `runSelectedPaletteActionWhileInFlight()` has no prefill case and blanket-refuses it (disclosed, owner ruling pending — MINOR-2 itself untouched per instructions).
- Stated when a prefill actually flows: idle Enter/click reaches it via `runSelectedPaletteAction()` → `runRootPaletteAction()`; the public arm's mid-turn prefill half is unreachable from production because its only caller, `App::runRegistryCommand()` (App/App.php:4811 — caller census re-verified: sole non-test sink), routes slash-visible commands through `runCommand()` and palette-only rows by action labels, so mid-turn prefill flows only from a direct call of `runPaletteAction()`.
- No behavior edits. No phrase in the changed docblock is pinned by any guard (grep census: zero hits in tests/ incl DocFigureProseDriftTest).

## Gates (cwd=candy-code, plain pipe, -u LINES -u COLUMNS)
- `php -l src/Chat.php`: clean.
- `--filter 'Palette'`: **229T / 4175A, 0F, OK** (2 pre-existing deprecations — identical to builder/reviewer runs).

## Untouched (as instructed)
- MINOR-2: out-of-scope product seam — welder/owner visibility, recorded in REVIEW-1.
- NIT: COMMANDS.md "Since C-EXT-5.b" jargon left alone.
- No test added (docblock-only fix; adding a method would shift the live enumeration into the welder's inherited re-pin duty — none of the old wording was pinned, so there is nothing to re-arm).
