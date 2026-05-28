# Step 09.13 — candy-metrics histogram buckets + descriptors

**Source:** `leftover_updates_later.md` candy-metrics §3.1-2 CRITICAL
**Branch:** `ai/metrics-histogram-descriptors`

## Deliverable

- Classic histogram buckets (today `PrometheusFileBackend` emits
  summary instead of histogram).
- Metric descriptor / registration with help text.

## Files

**Modify:**
- `candy-metrics/src/Backend/PrometheusFileBackend.php` — emit
  `*_bucket{le="..."}` for histogram metrics.

**Create:**
- `candy-metrics/src/Descriptor.php` — registration DTO with name, help,
  type, label keys.
- `candy-metrics/src/Registry.php` — `register(Descriptor)`.

## Tests

- `candy-metrics/tests/HistogramBucketsTest.php`.
- `candy-metrics/tests/DescriptorTest.php`.

## Acceptance

- `cd candy-metrics && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
