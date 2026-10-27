# C-CTX-1.b — DONE: e681bd046 on cc/C-CTX-1.b (base 85be78e05, 7 files, +232/−20, clean, NOT pushed)

Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS):
- --filter 'Rule|Context': rc=0 — Tests: 2439, Assertions: 10674, PHPUnit Deprecations: 2 (pre-existing at base; --filter 'Rule|Frontmatter': rc=0 — 909/4305, same 2).
- five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift): 133/15341, ONLY the staleness pair red — pinned 25537 vs live 25648 (lane adds +4 methods; remainder is base-era drift). suite-figure.json/README/durations untouched per law; re-pin is closeout duty.

Landed:
- src/Context/Rule.php (hot file, minimal hunks): alwaysApply:false no longer ANDs into enabled when paths/globs exist — such imports stay enabled+path-scoped (never standing-spliced; RulePathNudge delivers tool-time). New globListField splits comma-string scalars for BOTH paths: and globs:. Pattern-less alwaysApply:false → disabled at parse with new Rule::DISABLED_ALWAYS_APPLY recorded in readonly $disabledBy (native enabled:false veto leaves it null). Class docblock + buildTriggers/mutate comments truth-flipped in-step. boolField still throws on non-bool (skip-ledger pin green).
- src/Commands/RulesCommand.php: one aggregate /rules hint (counts imported packs disabledBy===alwaysApply; natives + globbed excluded); RulesHostCommand ob_start reuse verified needs-nothing.
- lang/en.php: cmd.rules.alwaysapply_hint + _one (alphabetical insert; only-locale lib).
- docs/MEMORY.md: Cursor frontmatter mapping table (6 rows) in the Instruction-files section; DocFigure collision-checked first (no arm pins that region) — guard green.

Deviations (disclosed):
- tests/Support/FrontmatterKeyAuditTest.php in-step flip: its alias-fold pin asserted the OLD alwaysApply AND-semantics on a globs+false fixture; flipped to enabled+path-scoped (ignoredFrontmatter absence kept). Root-cause law + gate-green; brief listed 2 test files, this pin contradicted the mandated semantics directly.
- RulesCommandTest: repaired an accidental newline loss in an existing test in the same hunk region.

Seams (not lane-owned):
- RulesState::effectiveRule refuses to ENABLE parse-disabled packs, so the hint's "turn one on" is truthful only for future work — follow-up: honor disabledBy==='alwaysApply' in session toggle, or wording ruling. Globbed packs ARE toggleable (the plan's escape).
- src/Support/FrontmatterKeyAudit.php:56-59 docblock "folds into enabled at the parse boundary" now half-stale (out of files.modify) — closeout doc edit.
- cmd.rules.effect.self-disabled wording names 'enabled: false' for alwaysApply-off rows — imprecise, pre-existing.

New test files: none (RuleLoaderTest +2 methods, RulesCommandTest +2 methods).

## Fixes round 2
- Commit 3cedf52a0 (parent e681bd046, 3 files, +6/-5, author/committer detain, zero trailers, NOT pushed).
- lang/en.php cmd.rules.alwaysapply_hint(+_one) re-worded to the honest remedy ("stays off until `alwaysApply: false` is removed from its frontmatter"); RulesCommandTest hint pins flipped in-step. Design wording (design-prv-ctx.md:123) overridden by lead ruling for doc-truth; the session-toggle re-admission of disabledBy===alwaysApply packs stays a recorded follow-up seam.
- src/Support/FrontmatterKeyAudit.php docblock (:59) truth-flipped from the old AND-fold to: with patterns enabled+path-scoped, pattern-less switches off at parse.
- Gates on tip: --filter 'Rule|Frontmatter' rc0 909T/4305A (2 pre-existing deprecations, byte-identical to round-1 figures); five-guard 133T/15341A, ONLY staleness pair red (pinned 25537 vs live 25648 — unchanged by this commit, +0T).
