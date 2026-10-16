# Step 03.13 — sugar-dashboard depends on sugar-charts, kill chart duplication

**Source:** `leftover_updates_later.md` Dash-11 + SSOT-05
**Branch:** `ai/dash-depends-charts`

## Deliverable

`sugar-dashboard/src/Plot/Chart/*` overlaps with `sugar-charts/src/*`. Two
parallel chart libraries. Keep sugar-charts canonical; sugar-dashboard
imports.

## Files

**Audit:** Compare each `sugar-dashboard/src/Plot/Chart/<Name>.php` against
`sugar-charts/src/*.php` and either:

- **Direct duplicate** → delete the dash version. Update consumers to
  `use SugarCraft\Charts\<Name>`.
- **Dash adds value** (GaugeWithDetail, WordCloud, Treemap, Dendrogram,
  Sankey, Sunburst, etc.) → keep in dash; document the rule "leaf
  chart primitives = sugar-charts; visualisations + dashboard-specific
  widgets = sugar-dashboard".

**Delete duplicates** (verify each before deleting):
- `Bar.php` (vs `sugar-charts/src/BarChart.php`)
- `Heatmap.php` / `HeatMapChart.php` (vs `sugar-charts/src/Heatmap.php`)
- `OHLC.php` (vs `sugar-charts/src/OHLC.php`)
- `Sparkline.php` (vs `sugar-charts/src/Sparkline.php`)
- `Chart.php` (vs `sugar-charts/src/Chart.php`)
- `LineChart.php` if both have it.

**Modify:** `sugar-dashboard/composer.json`:
- `require` adds `"sugarcraft/sugar-charts": "@dev"`.
- `repositories[]` adds path-repo.
- `tools/check-path-repos.php` green.

**Modify:** every `use SugarCraft\Dashboard\Plot\Chart\<Deleted>` import →
`use SugarCraft\Charts\<Name>`.

## Tests

`cd sugar-dashboard && vendor/bin/phpunit` green. Chart-rendering snapshots
unchanged.

## Acceptance

- `grep -rn "class Bar\|class OHLC\|class Sparkline" sugar-dashboard/src/Plot/Chart`
  returns nothing for the deleted classes.
- `composer validate` green.

## Notes

- Document the boundary in `sugar-dashboard/README.md`: "Charts come from
  `sugarcraft/sugar-charts`. sugar-dashboard adds dashboard-specific
  visualisation widgets (GaugeWithDetail, Treemap, Sankey, etc.)."

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
