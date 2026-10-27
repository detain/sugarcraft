VERDICT: CLEAN

Axes verified (static; php/shell denied after first git probe — note weld re-run):
1. Scope: diff is exactly the 3 block-named files; no Window/*, Renderer, Chat, App, Bootstrap edits. Every symbol used (WindowList entries/add/update/focus/focusedKey, WindowEntry::session/agent, WindowRef::session/agent/keyIsZoneable, AgentColor::resolve, WindowAttention cases, PaneFrame, PaneLabel, AgentSplitColumn::PANE_CHROME, Renderer::keyed) pre-exists at base 75ba82ddb — Window kit genuinely used as-is.
2. Purity/clickability: component emits zero Mark::zone; zones are host-applied at Renderer.php:773-784 keyed by rowKeys index (body-line i = border+1+i; null keys skipped). Hostile keys (charset excludes `~`, includes `:`) degrade to null; hostile labels pass PaneLabel::safe which flattens \p{C} and strips \p{Co} — no stray PUA halves. Width edges (width 1, rows 0, 120-col) all clamp via max(1,…)/max(0,…); no crash path.
3. Overflow rule byte-shape-identical to TodoPane.php:75 (`max(0, min(anchor-1, count-budget))`), with pinned row-0 subtracted correctly; 3-slot return matches the array{0:string,1:list<?string>,2:int} contract keyed() consumes (AgentsPane.php:59 same).
4. Focus semantics coherent: model focus is session-scoped (WindowList::find excludes agent keys, WindowList.php:111), so session-only highlight is complete; strip style (shellPrimary+bold+underline) mirrors WindowStrip.php:349.
5. Pins non-vacuous: mutation-reasoned each — row-0 pin reddens under scroll-into-window; key-align under drop/shift; nest under indent-drop; hostile under zoneableKey bypass; color test has negative leg (@plain must not borrow red); badge/summary exact plain-line; sweep bounds rows at width+4 per PANE_CHROME. 11T; 1457A figure consistent with 14×6 sweep.
6. Lang: 4 quoted tui.sessions.* keys, all used, en-only, block carries TuiLangTest census comment; no parity exposure.
7. §1.9: no glob-shaped/denial-shaped literals in either new file; new test file's durations.tsv row correctly left to weld (REPORT declares it).
8. Commit: single, subject "P-TABS.c: SessionsPane vertical window list"; author law lead-disk-verified.

NIT (step-local): SessionsPane.php:109 — when windowBudget==0 (rows≤3) maxOffset still reports rows the window cannot paint; wheel clamp becomes a visual no-op. Harmless; no fix required.
Weld duties: re-run SessionsPane + TuiLang|LangParity + five-guard at tip; mint durations.tsv row; suite-figure re-pin carries +11T (live-enum 25,953 disclosed pair-only). Pane::Sessions/App adapter seam = P-TABS.g as REPORT states.

--- lead note: persisted verbatim @934@. stage=clean queued for weld (plain pick 679609a9b, no regen, gates per weld duties).
