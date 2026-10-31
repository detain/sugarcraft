# C-TUI-6.g — round-1 fix record

## FIX-1 (2026-10-31, fixer lane on cc/C-TUI-6.g)

Ground re-verified at intake: tip f73ac4315, five picks on base 29929c305,
porcelain 0; REVIEW-1.md = 9,995 B @ 12:32:28, VERDICT FINDINGS 0C/1M/2m.
Master read-only at intake: 07593d459 (moved under the lane; no rebase, the
two fix picks stack on f73ac4315).

### MAJOR-1 — residual chrome migrated (all five named files; mirrors regenerated per pick)

- fix-1 `c270a384323c85c1472d2d18a8454473251bc28b` (MAJOR-1a):
  - `components/panels/TodoPanel.vue`: migrated 3 chrome strings —
    `web.panels.todo_empty` ('The agent keeps no todo list in this session.'),
    `web.panels.todo_loading` ('Loading…'), `web.panels.todo_summary`
    ('{done} of {total} done' — count rides params). Disclosed NON-CHROME:
    `item.status.replace('_',' ')` sr-only markers are protocol tokens
    (completed/in_progress/cancelled); `list.error` is server text — owner
    ruling keeps both untranslated.
  - `components/MessageMarkdown.vue`: migrated the copy-button set —
    title `web.transcript.copy_code_title` (new, 'Copy this code block') and
    label states reusing existing `web.transcript.copy_idle`/'copy_copied'/
    'copy_failed' (English values byte-identical, so
    codeBlockCopy.spec.ts 'copy'/'copied' pins pass through the bundle).
- fix-2 `27e427e85d27115ad0e7a4cbcedd63a3bd5967ac` (MAJOR-1b + MINOR-1):
  - `components/grid/PaneGrid.vue`: `web.grid.add_hint`, `web.grid.pick_aria`
    (static aria-label → :aria-label), `web.grid.open`, `web.grid.new_session`.
  - `components/approvals/ApprovalsDrawer.vue`: static `aria-label="Approvals"`
    → `:aria-label="t('web.approvals.drawer_title')"` — reuses the existing
    key (same English), zero new keys.
  - `components/panels/CommandPalette.vue`: entries computed() fully migrated —
    `web.panels.palette_group_{actions,commands,panels,sessions}` (all FOUR
    group labels, not just the three named — same root cause; 'Commands'
    renders via {{ entry.group }} identically), `palette_new`,
    `palette_new_browse`, `palette_new_hint`, `palette_clear`,
    `palette_clear_hint`, `palette_panels_close`/`_open`,
    `palette_panel_entry` ('{label} panel' — PANEL_LABELS proper-noun rides
    the param, the SidePanels `web.chrome.panels.empty {label}` idiom).
    Disclosed NON-CHROME: command `label`/`hint` ('/name', argumentHint +
    description join) are server command data; session label (name/preview/id)
    and hint (status token) are user data — untranslated per the ruling.
  - MINOR-1: the `Theme: ${settings.theme} → next` template concat folds to
    `t('web.panels.palette_theme', { theme: settings.theme })`.
  - The clear-history `window.confirm(...)` copy (same computed cluster, not
    line-named by the review but residual chrome in the same list) migrated to
    `web.panels.palette_clear_confirm`; the spec only asserts the confirm was
    called, not its text — green unchanged.
  - Entry.group typed union widened to string (label now translated at entry
    build; filter haystack = translated group+label+hint; `data-entry` ids
    unchanged, so panels.spec 'todo pan' → ['panel-todo'] and
    paletteClearHistory data-entry lookups are unaffected).

### Disclosure correction (the finding's root sentence)

The pick-4 narrative 'SessionTools and TodoPanel carry no chrome strings' was
FALSE for TodoPanel (three chrome strings, now migrated), and MessageMarkdown's
'zero user-visible chrome' overlooked the script-side button.title (migrated).
SessionTools' disclosure stands honest (template composes SidePanels +
CommandPalette, zero literals). Corrected census for §7: 45 migrated .vue
(the five here moved from disclosed-clean to migrated) + 4 done in 6.f + 1
disclosed zero-chrome (SessionTools) of 50.

### MINOR-2 — panels-spec figure correction (REVIEW-1 §Q4 batch3)

Builder REPORT.md's 'panels 18 files / 152 tests' does NOT reproduce at tip:
the panels-dir-scoped run is **15 files / 123 tests** (r1 reviewer figure,
adopted for §7; the builder's was a wider slice-4-era set, zero failures in
either reading). REPORT.md history left byte-untouched per instruction.

### Keys / mirrors / guards

- 22 new `web.chrome.*`-span keys total (4 + 18): run 519 → 541 (grep -c over
  lang/en.php); python dupscan dups=[]; byte-order ascending scan over the 541
  keys: 0 violations; still ONE contiguous run between the C-TUI-6.f markers.
- Both mirrors regenerated each pick (gen-web-strings.php --write + node
  scripts/gen-web-strings.mjs) and both --check legs rc=0.
- phpunit anchors (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS,
  full Test-suffixed stems): `(CliLangCatalogueTest|LangParityTest)::`
  15T/4195A GREEN (r1: 4173A; +22A = the 22 new keys, honest growth);
  `(WebStringsTest|WebStringsDriftTest)::` 9T/576A GREEN (r1: 554A, +22A);
  `TreeWideGuardRosterTest::` 17T/3901A and `DuplicatedTestHelperDriftTest::`
  14T/204A — both byte-match the r1 figures (zero new test files → zero
  durations.tsv rows owed; no new sibling helpers).
- vitest serial (--no-file-parallelism, pre-seeded node_modules, never npm ci):
  fix-1 affected set 4 files/24 tests; fix-2 set (grid+panels+approvals dirs +
  i18nCoverage + codeBlockCopy + transcriptActions) 22 files/174 tests; FULL
  suite 98 files/830 tests, all passing.
- Load-bearing by execution, not claim: the migrated strings ARE the spec-
  pinned text ('keeps no todo list', '1 of 2 done' panels.spec.ts:38/:44,
  'copy'/'copied' codeBlockCopy.spec.ts:55/:78, 'Clear prompt history'
  paletteClearHistory.spec.ts:59, 'todo pan' entry filter) and pass through
  the bundle now. i18nCoverage floor (files>20, distinct keys>40) holds and
  grew honestly.
- Residue greps per pick: 'if (false'/'&& false'/' || true' = 0 in diffs.
- Protocol trio + dist + suite-figure.json + durations.tsv + composer.json:
  zero occurrences in either fix-pick name list (verified `git show
  --name-only`); diff is exactly .vue ×5 + lang/en.php +
  docs/protocol/web-strings.en.json + strings.en.generated.ts.
- Observation (NOT touched, outside finding scope): `stores/settings.ts`
  NOTIFICATION_PROBLEM_TEXT is a raw-English chrome dict consumed by
  ApprovalsDrawer :88 title — .ts stores were outside the 6.g .vue census;
  seam for the lead if the store layer ever joins the migration.
