# Step 09.02 — sugar-animation area downsample + Floyd-Steinberg + local color + transparency

**Source:** `leftover_updates_later.md` sugar-animation P3-P6
**Branch:** `ai/flip-dither`

## Deliverable

Four quality improvements:
- Area-averaged downsampling (vs nearest-neighbor).
- Floyd-Steinberg dithering.
- Local color tables (per-frame palette).
- Transparency / disposal-method handling.

## Files

**Modify:**

- `sugar-animation/src/Downsampler.php` — area-average mode.
- `sugar-animation/src/Dither/FloydSteinberg.php` (new).
- `sugar-animation/src/GifDecoder.php` — local color tables + disposal.

## Tests

- One test per feature.

## Acceptance

- `cd sugar-animation && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
