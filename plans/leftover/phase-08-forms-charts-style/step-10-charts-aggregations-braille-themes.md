# Step 08.10 — sugar-charts data aggregations + BrailleCanvas mode + named themes

**Source:** `leftover_updates_later.md` sugar-charts §3.2.1 + §3.2.3 + §2.7
**Branch:** `ai/charts-agg-braille-themes`

## Deliverable

Three features:

- Data aggregation helpers: `bucketByTime`, `movingAverage`,
  `resample` on series data.
- Optional `BrailleCanvas` rendering mode (depend on
  candy-sprinkles after StyleParser move; braille canvas itself
  lives in sugar-dash's `Plot/Braille/` — consume it).
- Named themes (`Theme::dracula()`, `oneDark()`, etc.) — consume from
  candy-sprinkles per step 02.01.

## Files

**Create:**
- `sugar-charts/src/Aggregation/BucketByTime.php`
- `sugar-charts/src/Aggregation/MovingAverage.php`
- `sugar-charts/src/Aggregation/Resample.php`

**Modify:**
- `sugar-charts/src/LineChart.php` and `Chart.php` — `withCanvas(BrailleCanvas)`
  alternative mode.
- `sugar-charts/composer.json` — add `sugarcraft/candy-sprinkles` (for
  themes) and `sugarcraft/sugar-dash` (for braille canvas) if not
  already.

## Tests

- `sugar-charts/tests/Aggregation/<Each>Test.php`.
- `sugar-charts/tests/BrailleChartTest.php` — snapshot.

## Acceptance

- `cd sugar-charts && vendor/bin/phpunit --filter "Aggregation|Braille|Theme"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
