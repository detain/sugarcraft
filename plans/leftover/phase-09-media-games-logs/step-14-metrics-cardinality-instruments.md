# Step 09.14 — candy-metrics cardinality + instrument kinds + native histograms

**Source:** `leftover_updates_later.md` candy-metrics §3.3 + §2 table
**Branch:** `ai/metrics-cardinality-instruments`

## Deliverable

- Label-cardinality control / `DeleteLabelValues`.
- `UpDownCounter`, `AsyncCounter`, `AsyncGauge` instrument kinds.
- Native histograms (low priority — implement only if §3.1 sponsored
  it).

## Files

**Modify:**
- `candy-metrics/src/Registry.php` — track cardinality per metric;
  enforce cap.

**Create:**
- `candy-metrics/src/Instrument/{UpDownCounter,AsyncCounter,AsyncGauge}.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-metrics && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
