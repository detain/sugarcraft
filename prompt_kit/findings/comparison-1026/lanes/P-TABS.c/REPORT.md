# P-TABS.c — SessionsPane vertical window list

**Verdict: DONE.** Commit `679609a9b42df30deae35ce582ff534e1c36ff51` on `cc/P-TABS.c` (base 75ba82ddb), worktree `/home/sites/cc-cmp-P-TABS.c`. Never pushed.

## Files
- CREATE `candy-code/src/Tui/Components/SessionsPane.php` — `renderModel(WindowList, Theme, width, rows): string` and `layout(...): [block, list<?string> rowKeys, scrollMax]` (keyed() shape). Row 0 is always `＋ New session` (`new`); window rows keyed `s:<key>` / `a:<runId>`; agent rows indented `└` in `AgentColor::resolve` preset colour (fallback shellForeground); focused row carries shellPrimary+bold+underline highlight; overflow follows the TodoPane anchor rule (focused window kept in view, `＋` row immovable); attention glyph + badge + `· N agents` summary tail per row. Non-zoneable keys (`WindowRef::keyIsZoneable` false) paint with a `null` row key (unclickable, never dropped). Pure — no I/O, no statics. Frame: rounded border titled `tui.sessions.title`, `PaneFrame::render`, chrome rows/cols 2/4 like AgentsPane (no `Pane::Sessions` enum exists yet — plain title key).
- CREATE `candy-code/tests/Tui/Components/SessionsPaneTest.php` — 11T covering the DoD: row keys align painted rows 1:1; never over-wide (width×rows sweep, box = width+4 per AgentSplitColumn::PANE_CHROME contract); agent nesting directly under parent session; focus kept in view on overflow; `＋` always row 0; hostile-key zone suppression; focus highlight rides one row only; preset colours; badge/summary; empty list.
- MODIFY `candy-code/lang/en.php` — added ONLY the `tui.sessions.*` block (4 keys: new/title/unnamed/agents). `tui.window.*` untouched; strictly inside my prefix (P-MENU.b holds `tui.menu.`).

## Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, `vendor/bin/phpunit -c phpunit.xml`)
- `--filter 'SessionsPane'` → `Tests: 11, Assertions: 1457, OK` (the 2 PHPUnit deprecations are pre-existing config-level — reproduced on untouched WindowStripTest runs).
- `--filter 'TuiLang|LangParity'` → `Tests: 16, Assertions: 4149, OK` — key census balanced.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift` → `Tests: 133, Assertions: 15588, Failures: 2` — exactly the allowed staleness pair (pinned 25,537 vs live enum 25,953; program-wide re-pin duty, lane forbidden to edit `suite-figure.json`/README/`durations.tsv`). Pair-only satisfied.

## DURATIONS duty
NEW test file: `candy-code/tests/Tui/Components/SessionsPaneTest.php` (11T) — the gate must add its `scripts/parallel-tests-durations.tsv` row. Lane did NOT edit durations.tsv or suite-figure.json.

## Author law
`%ae` grep-count 1, `%ce` grep-count 1 (separate greps), zero Co-Authored trailers. No push/pull.

## Seams
- `Pane::Sessions` enum case + `render(App)` adapter + renderPane match arm belong to **P-TABS.g** per plan — SessionsPane is reachable-ready (pure component + welded Window kit used as-is, no API gaps found).
- Row-key zones (`win:s:`/`win:a:` siblings live in WindowStrip) are marked by the host via `keyed()`; hostile-key rows yield `null` keys so the Renderer zone pass skips them.
