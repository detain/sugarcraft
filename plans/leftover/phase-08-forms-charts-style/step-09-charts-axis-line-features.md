# Step 08.09 — sugar-charts niceNumbers + sliding push + fill + MarkLine

**Source:** `leftover_updates_later.md` sugar-charts §3.1.1-3 + §3.2.2
**Branch:** `ai/charts-axis-line`

## Deliverable

Four chart features:

- `niceNumbers()` axis-tick labeling on `Canvas/Graph.php`.
- `LineChart::push(float $value)` sliding-window streaming.
- `LineChart::withFill(bool)` area fill below curve.
- `MarkLine` annotation (Min / Max / Average reference lines).

## Files

**Modify:**
- `sugar-charts/src/Canvas/Graph.php` — `niceNumbers()` helper for
  tick labels.
- `sugar-charts/src/LineChart.php` — `push()` + `withFill()`.

**Create:**
- `sugar-charts/src/MarkLine.php` (annotation type).

## Tests

- `sugar-charts/tests/LineChartTest.php` — snapshot with each feature.
- `sugar-charts/tests/MarkLineTest.php`.

## Acceptance

- `cd sugar-charts && vendor/bin/phpunit --filter "LineChart|MarkLine"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
