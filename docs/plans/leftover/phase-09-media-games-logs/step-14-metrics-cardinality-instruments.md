# Step 09.14 — sugar-metrics cardinality + instrument kinds + native histograms

**Source:** `leftover_updates_later.md` sugar-metrics §3.3 + §2 table
**Branch:** `ai/metrics-cardinality-instruments`

## Deliverable

- Label-cardinality control / `DeleteLabelValues`.
- `UpDownCounter`, `AsyncCounter`, `AsyncGauge` instrument kinds.
- Native histograms (low priority — implement only if §3.1 sponsored
  it).

## Files

**Modify:**

- `sugar-metrics/src/Registry.php` — track cardinality per metric;
  enforce cap.

**Create:**

- `sugar-metrics/src/Instrument/{UpDownCounter,AsyncCounter,AsyncGauge}.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-metrics && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
