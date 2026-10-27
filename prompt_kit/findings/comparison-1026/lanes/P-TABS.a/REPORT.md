# P-TABS.a — the window-strip kit (foundation) — CLOSED

Commit: 758d4f4abf70b387eb0b9d14899f8fcfc9a49e49 on cc/P-TABS.a (base 6f42f3325), 14 files +1630, author/committer Joe Huss <detain@interserver.net>, zero trailers. NEVER pushed.

## Delivered (all NEW, nothing wired — per plan the strip is pure render over state; P-TABS.g/P-AGENT.b supply the wiring seams)
- src/Tui/Window/WindowKind.php — enum session|agent.
- src/Tui/Window/WindowRef.php — VO (kind, key, label, ?color, ?parentKey); keyIsZoneable = sugar-mouse zone-id charset/length law, shared by strip's mark gate.
- src/Tui/Window/WindowAttention.php — Idle/Working/Waiting/Unseen/Failed; distinct glyphs (NO_COLOR channel), rank derived from cases order, color(Theme).
- src/Tui/Window/WindowViewport.php + WindowViewports.php — scroll/draft/cursor VO normalized at construction; immutable stash/restore; unknown key answers bottom(), never another window's residue.
- src/Tui/Window/WindowEntry.php — parent/child node (session entry carries agent children by runId); with* rails; badge clamps ≥0.
- src/Tui/Window/WindowList.php — ordered entries + focus key; add/update/remove/focus/find/parentOf/badge/attentionCount/summary (Lang tui.window.summary[.none]).
- src/Tui/Window/WindowStrip.php — one-row render: `[+]` first (never dropped), per-session tab (glyph · name ≤NAME_COLS · badge · ×), focused session's chips `▸ @name` right after its tab; densities full/icon/terse chosen by fit; entries that don't fit collapse into trailing `‹N›` (N = sessions not placed; hidden if it itself cannot fit → row degrades to `[+]`-first subset, never over-paints); zones() answers [row0, from, to-excl, id] from the SAME arithmetic, marked pass wraps zones measured on PLAIN widths (MenuBar marked-twin law); hostile keys paint unmarked, never throw.
- src/Tui/Window/AgentColor.php — 8 word names → theme tokens (red=shellError, blue=shellInfo, green=shellSuccess, yellow=shellWarning, purple=shellPrimary, orange=userLabel, pink=assistantLabel, cyan=systemLabel), re-read per call (theme switch lands next frame); else full `#rrggbb` literal must clear Theme::CONTRAST_MIN against shellForeground; anything else null (no guessed colour).
- lang/en.php — +4 keys under tui.window.* (overflow/unnamed/summary/summary.none), block at file end; all four ride as quoted literals under src/Tui/ (TuiLang census both-legs green).

## NEW test files (OWED durations.tsv rows at gate)
- candy-code/tests/Tui/Window/WindowStripTest.php (13T/633A family-run; 20–240 sweep: width law, `+` presence, marked/plain twin cell-identity, zones land on painted cells at every density incl terse (no winx zones emitted at terse — zones() agrees), overflow count == dropped sessions, hostile-key degradation, per-state glyph distinctness on the painted row, wide-unicode budgeting)
- candy-code/tests/Tui/Window/WindowViewportsTest.php (5T: round-trip, replace-not-duplicate, unknown→bottom, ctor normalization, withers derive)
- candy-code/tests/Tui/Window/WindowAttentionTest.php (5T: rank strict-order derived, glyph uniqueness, needsAttention = above Working rank, color-per-theme, entry badge/attention independence)
- candy-code/tests/Tui/Window/AgentColorTest.php (5T: word→token pins, case/space-insensitive + vocabulary roster pin, garbage→null (10 hostile forms incl forged-hex), legible #000000 passes / fg-same-hex + #808080 refused with live contrast re-measure, lowercase normalization, 3-digit shorthand refused)

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'Window'`: OK — Tests: 287, Assertions: 2963 (only 2 pre-existing AccessorDeprecationTest provider deprecations).
- directory `tests/Tui/Window`: OK (27 tests, 633 assertions).
- `--filter 'TuiLang|LangParity'`: OK — 16 tests, 4113 assertions.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: 133 tests, 2F = ONLY the allowed staleness pair (pinned 25,537 vs live 25,820 — live carries my +27 and sibling-lane additions). GlobDialect corpus unmoved (no glob-shaped literals minted; sentinel-strip preg carries parens/{0,} exemption).
- php -l: all 9 src + 4 test files + lang/en.php clean.

## Inherited reds NOT touched (foreign, disclosed)
- TreeWideGuardRosterTest 2F at base WITHOUT my files (re-proved by removal run): `Cli/SelfUpdateTest.php => glob($phar.'.tmp-'.\chr(42))` unrostered walker — the self-update fleet's own roster debt, outside this lane's ownership.

## Decisions/deviations (all documented in-file)
1. Overflow N counts SESSIONS not chips (brief defines '‹N›' without a unit); a focused session's chips ride in its placement chunk — the chunk drops whole.
2. If even `‹N›` cannot fit after trimming, the marker is hidden rather than the row over-painting.
3. AgentColor contrast reference = shellForeground (Theme carries no background Color token).
4. summary() keeps one plural form ('{sessions} windows'); consumers may widen.
5. WindowRef::keyIsZoneable reused for full zone ids (prefixes are charset-clean; length gate applies to the composed id).
6. AgentColor trims whitespace-padded input (YAML-sourced colour text) before word/hex matching.
7. Nothing under App/Tabs, Renderer, Chat, Bootstrap touched — WindowStrip carries no App wiring (plan seam: consumers feed state).

## Round-2 fixes (2026-10-27)

- [MINOR·step-local] AgentColor.php:17-18 — docblock enumeration rewritten to the code-exact
  tokens in prose order (purple/pink/orange/cyan): `shellPrimary/assistantLabel/userLabel/systemLabel`.
  Note: the finding's parenthetical abbreviated the first token as "primary"; :73-76 spelling is
  `shellPrimary`, which the finding names as the governing authority ("exact token spellings as in
  :73-76"). shellMuted (real Theme token, unused by the match) removed from prose.
- [NIT taken] AgentColor.php:7-8 — import order aligned to sibling WindowAttention.php
  (`Code\Theme` before `Core\Util\Color`).
- Gates: php -l clean; `--filter Window` 287T/2963A (figure-unchanged); five-guard 133T with
  ONLY the allowed ReadmeSuiteFigureDrift staleness pair (README 25537 vs live 25820 — the
  step's +4 new test files, closeout re-pin duty). Zero behavior change: diff is 2 comment lines.
