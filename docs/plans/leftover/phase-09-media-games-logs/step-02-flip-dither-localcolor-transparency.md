# Step 09.02 — candy-flip area downsample + Floyd-Steinberg + local color + transparency

**Source:** `leftover_updates_later.md` candy-flip P3-P6
**Branch:** `ai/flip-dither`

## Deliverable

Four quality improvements:
- Area-averaged downsampling (vs nearest-neighbor).
- Floyd-Steinberg dithering.
- Local color tables (per-frame palette).
- Transparency / disposal-method handling.

## Files

**Modify:**
- `candy-flip/src/Downsampler.php` — area-average mode.
- `candy-flip/src/Dither/FloydSteinberg.php` (new).
- `candy-flip/src/GifDecoder.php` — local color tables + disposal.

## Tests

- One test per feature.

## Acceptance

- `cd candy-flip && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
