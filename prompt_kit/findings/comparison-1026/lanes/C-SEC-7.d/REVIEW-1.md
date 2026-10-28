# C-SEC-7.d — REVIEW-1 (independent reviewer, 2026-10-28)

Scope reviewed: ONE commit 59fa0d4ec on base e4a705244, branch cc/C-SEC-7.d
(worktree /home/sites/cc-cmp-C-SEC-7.d), 732+/104- across 15 files.
Author law: %ae/%ce both Joe Huss <detain@interserver.net>, zero trailers ✓.
File set == BRIEF declared set exactly (incl. hub edits inside LayeredSettings
LAYERED_KEYS region and PermissionSettings new-definition block) ✓.

## Gates reproduced (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code)
- new+adapted: `--filter 'AutoReviewScopeTest|UnflaggedShellReviewTest|ExecReviewerTest'`
  → **43T/135A** EXACT match of builder claim, rc=0.
- `StderrEmitterCensusTest` → **95T**/21412A rc=0 EXACT.
- `BootstrapLaunchFormatConstantsTest` → 18T rc=0 (builder's census-name shorthand).
- doc guards `TrustKey|DocFigure|ReadmeRoster|EnvRoster|SettingsSchema|CliLang|LangParity`
  → 237T rc=0 (superset of the claimed 140T set; all green).
- `LayeredSettings|TrustKey` → 124T rc=0 (superset; claimed 111T — counts differ only
  by which substring classes pull in, every run green; the exact T-number was not
  reproducible with the quoted filter but the result line is what matters).
- big family `Permission|BootstrapLaunchFormatConstants|GlobDialect|Duplicated|SymbolCitation`
  → 2302T/**1F**, the sole failure `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog`
  — INDEPENDENTLY PROVEN INHERITED: lane diff touches neither
  `ToolCatalog::names()` inputs (0-line diff in ToolCatalog.php) nor the
  PERMISSIONS.md roster paragraph (0 Schedule/SendMessage lines in the diff); the
  red is the standing master lag, on the brief's foreign NOT-findings list.
- `gen-settings-doc --check` rc0 could NOT be run directly (reviewer permission map
  refuses bare `php`); covered INSTEAD by SettingsSchemaDocDriftTest green — it is
  the phpunit equivalent, re-deriving the committed SETTINGS.md tables (verified:
  scope=row live, model=restart, 133-key sentence, tier marks).
- "2 PHPUnit Deprecations" appears on EVERY run in this tree including untouched
  UsageTest (rc=0) — process-wide pre-existing, not lane dirt.

## Security walk (auto posture) — all PASS
- Reviewer is consulted only inside `evaluateAuto`; the `rm -rf /` breaker is step 0
  of `decide()` and Deny rules resolve before the mode — autoReview can never widen
  a breaker/Deny refusal. SECURITY_CATEGORIES return Ask before the reviewer is
  reached; PtyRun keystroke-steps return Ask pre-classification. MCP calls never
  reach the unflagged path.
- Unflagged path: baseline verdict is the Allow the list already gave; reviewer can
  only narrow (allow=identity, deny=strike counted by the breaker,
  unavailable→Ask). "can only narrow what would run" holds for writes/all too.
- Scope fail-closed: unset/unknown/bad-shape/unreadable → `shell` (match default +
  try/catch), never a guess-widening. Model fail-closed: not-a-string / no-slash /
  empty-half / unbuildable → one stderr warn + reviewer OFF (no silent title
  fallback burning the wrong credential). Both keys user-tier (roster test flips
  in-step) and RiskClass::Spend.
- E646 honored: no total-request timeout added anywhere; the override backend is
  structurally the `toollessBackend()` shape (ProviderFactory + providerConfigFor +
  thinkingBudget '' overwrite + `new EngineBackend(provider, model, hooksDisabled:true)`
  with ctor-default tools=[]/skills=[] — verified against the real ctor).
- default scope is byte-identical: shell rendering early-returns the pre-C-SEC-7.d
  string (pinned by testShellCallsRenderExactly…), and Bootstrap still gates the
  whole reviewer on `autoReview === true` — lane does NOT pre-empt C-SEC-7.e.
- Red-before 10: reconstructed by inspection, the 10 discriminating tests (writes,
  all, model-override, 5 badOverrides, 3 render) are red on base sources;
  the shell-default and unknown-scope twins pass before AND after — the correct
  default-law shape.
- Sandboxing the two existing review tests: pure-additive hunks, every original
  assertion survives; the re-arm is legitimate (gate now reads UiSettings).
- `seventy-seven` numeral law: README flips 75→77 (NOT "stays"); guard derives the
  word from count(LayeredSettings::userTierOnlyKeys()) and TrustKey guard is green
  at HEAD. Verified.
- Census in-step: Bootstrap 40→43, once 8→11, prose FORTY-THREE, +3 warn-once
  emitters (no new raw-sprintf: BootstrapLaunchFormatConstantsTest green).
- New test file is durations-row-listed in REPORT.md ("gate: add durations row") ✓;
  lane committed neither suite-figure.json nor durations.tsv ✓; no weld-only files;
  no new proc_open/fork sites; lang/en.php only (candy-code has no other locales —
  CliLang guard green).

## Findings
- MINOR (step-local): docs/PERMISSIONS.md (~:558-564) and README.md (~:1553-1560)
  say `writes` adds "Edit, Write, ApplyPatch and WebFetch — shown … with the capped
  pending diff, not their raw arguments". WebFetch has NO diff (AskDiffPreview::for
  answers null for it; renderCall shows the `url:` head + raw arguments — the code
  is right, the sentence over-claims). The lang key `settings.autoReviewScope.help`
  carries the correct qualifier "with the pending diff for the write tools".
  Fix: add the same qualifier in both docs sentences.
- MINOR (step-local): AutoReviewScopeTest::testTheModelOverrideBuildsItsOwnToollessBackend
  asserts EngineBackend class + model only; "tool-less" (the audit 15b-12 law, now
  on a USER-CHOSEN provider for the first time) rides constructor defaults
  un-pinned. Fix: one reflection assert `(new ReflectionProperty(EngineBackend::class,'tools'))->getValue($backend) === []`
  (+ hooksDisabled if practicable) so a future override routed through the
  tool-carrying backend goes red, not quiet.

## Verdict
FINDINGS — no CRITICAL, no MAJOR. Change is real, fail-closed, scope-honest and
law-compliant; both MINORs are small in-lane prose/test fixes.
