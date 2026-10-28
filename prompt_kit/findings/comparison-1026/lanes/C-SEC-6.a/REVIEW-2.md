# C-SEC-6.a REVIEW-2 (fresh independent r2 cure-review, 2026-10-28)

Lane: `/home/sites/cc-cmp-C-SEC-6.a`, branch `cc/C-SEC-6.a`, ONE commit `675241a04` (parent = declared base `e4a705244`; master advanced separately — merge-base verified). Spec: BRIEF.md/plan §4 C-SEC-6.a, §1, §2, §6.1. Round-1: REVIEW-1.md (1 MAJOR / 1 MINOR / 2 NIT); FIX-1.md claims adjudicated against disk.

## VERDICT: CLEAN — 0C/0M/0m. Both r1 findings cured; NITs landed. Approve for weld.

## Phantom/state gate — PASS
- tip 675241a04 == claimed FINAL TIP; `master..HEAD` = exactly 1; porcelain 0.
- `%ae` and `%ce` grepped separately = `detain@interserver.net` both; `%b` Co-Authored/Co-developed = 0.
- File set = the 9 declared files exactly (git diff --name-only), zero out-of-scope touches; no suite-figure.json, no durations.tsv, no dist/, no src/Protocol/**.

## MAJOR cure — bare `/permissions forget` on unreadable config — CONFIRMED FIXED
`PermissionsCommand::forgetRule()` restructured: ONE guarded `savedBody` read serves BOTH the bare-verb usage reply and the `forget_none` re-read; failure routes through a new `readFailed()` helper emitting `host.permissions.read_failed`. `savedRules()` calls the same helper — one sentence, two verbs, cannot drift (diff shows both surfaces share it). Targeted-branch `remove()` sits in its own try → `write_failed` refusal (r1's own probe recorded this as the designed answer for `forget 7`/`forget zzz`).
- Pin `testBareForgetOnAnUnreadableConfigRefusesExactlyLikeSaved`: corrupt top-level-list config; asserts `errored` true, `isRefused()` false, `'Could not read the saved rules'` present, NO `Usage:` text above the failure, and `assertSame($savedBody, $body)` — byte-identical refusal to `/permissions saved` on the same file.
- Non-vacuity (mechanically re-reasoned; no mutation executed — reviewer permission profile + sweep hazard): removing the try makes `list() → door()->current()` raise the `holds a JSON list, not an object` RuntimeException straight out of `run()` (r1's probe reproduced exactly this escape pre-fix) → the pin ERRORS, red. Weakening the route (plain reply, different Lang key, or body ≠ saved's) is caught by the errored-flag, substring and assertSame legs respectively. The pin's premise that current() throws is itself proven by the pin being green: only a real caught throw can produce the read_failed body.

## MINOR cure — list()/remove() fabricating and over-counting — CONFIRMED FIXED
Single `SavedRules::loadableRow()` (is_array + string pattern + `PermissionAction::tryFrom` non-null) now backs BOTH `list()` display/count and `remove()`'s `<n>` numbering — the predicate is exactly the one r1 prescribed, and it mirrors the two skip arms of `Bootstrap::permissionRules()` (rule_no_pattern / rule_no_action; cross-read at Bootstrap.php:7638-7660). Rejected rows normalize out of the landing write because `SettingsWriter::permissionRulesRefusal()` judges whole arrays by the loader itself (SettingsWriter.php:550-578 — docblock claim verified on disk, not just asserted).
- Pin `testListShowsAndNumbersOnlyTheRowsTheLaunchWouldLoad`: 4-row fixture (no-pattern / action 'nope' / non-array / 1 valid); list() === exactly the valid row; `remove(1)` targets the SHOWN row; stored ends `permissionRules: []` (bad rows normalized); post-write list agrees.
- Non-vacuity: unfiltered list() returns the fabricated rows → assertSame red (r1 pre-fix probe saw precisely these); numbering off raw storage makes remove(1) hit the no-pattern row → red; keeping bad rows in the write-back raises the schema refusal → error. Every drift leg reddens a named assert.
- Disclosed residual (accepted, in-spec): a shape-valid but GRAMMAR-unparseable pattern still rides as loadable (shown/numbered); the docblock names this and keeps grammar the loader's jurisdiction — list/numbering self-consistency (the safety property) is preserved either way, and a landed forget carrying such a row refuses visibly via the write door, never silently.

## NITs 3/4 — CONFIRMED
- Main-tree REPORT.md now reads "9 keys" and the lang diff adds exactly 9 `host.permissions.*` keys.
- M3 wording in REPORT.md: "short-circuits only an empty delta, not unchanged values" — matches the SettingsWriter truth r1 measured.
- In-step SettingsWriterCensus flip rode commit 675241a04 (git show: persistRules->write row replaced by SavedRules append->write + remove->write with adjudication comment); census guard green in battery.

## Gates reproduced (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code, -c phpunit.xml; PtyRunToolTest never in any filter)
- New pair: **Tests: 24, Assertions: 100** — EXACT FIX-1 claim (per-file: 14T/38A + 10T/62A).
- Combined family: **352T/13727A, Failures: 1** — EXACT claim; the sole failure named `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog`.
- Guard battery (GlobDialect|EnvRosterDrift|TrustKey|ConfigWriteProducer|ReadPathCensus|StderrEmitterCensus|CommandDocsDrift|SettingsWriterCensus|PermissionFsmExtractionParity|KeyBindingDrift): **OK 447T/68244A** — EXACT claim (DocFigure/ReadmeRoster/LangParity legs ran inside the combined filter; 13-family claim = these two shapes together).
- Foreign base-red re-proven inherited: the test's two inputs are byte-identical base↔tip — the `tool names a pattern can start with` roster paragraph sits at line 760 in both dumps, paragraph text identical (only hunk is @@911, +6 lines), and the diff touches zero src/Tools files. `Schedule`'s missing roster row is owed by the C-AGT-5.a era, not this lane.
- Environmental note (not a finding): every `--filter` process reports "PHPUnit Deprecations: 2" incl. pre-lane classes (PermissionsCommandTest, SettingsWriterCensusTest alone); both new files standalone report OK with zero notices. Not lane-attributable.

## Laws walked — all HOLD
§6.1: display/removal never auto-grants (writes only REMOVE; append keeps legacy allow-shape); You-tier only, never Project; every access through the injected SettingsWriter door — SavedRules.php opens no file itself (zero fopen/file_get_contents/AtomicJsonFile in the file). E646 n/a. No new proc_open/fork site (tearDown exec rm is the house cleanup shape, not a spawn). Test literals: `Bash(git status *)` shapes are paren/space-bearing → not GlobDialect-corpus-admitting; no `**Refused:**`-shaped literals; GlobDialect green. declare(strict_types=1)/final/PSR-4 one-type-per-file; user strings via Lang::t; comments say why.

## Non-blocking observations (no ruling owed here)
- `forget <n>` on a corrupt config answers under the `write_failed` sentence (the caught throw came from the READ inside remove()); the embedded {error} names the true cause and r1 recorded this shape as designed — vocabulary nuance only.
- `new SavedRules` vs `::new()` — r1 judged the neighborhood convention (Permissions ns: 11 new vs 2 ::new); unchanged, accepted.

## Weld duties (carry)
- `scripts/parallel-tests-durations.tsv`: 2 rows — `candy-code/tests/Permissions/SavedRulesTest.php`, `candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php`. Modified test (SettingsWriterCensusTest) needs none. No regen riders owed: no src/Protocol/** and COMMANDS.md was regenerated in-step (CommandDocsDrift green).
- en-only lang keys → §6.3-h 11-locale LAST carry does NOT apply.
- Standing PermissionsIntroDriftTest base-red: C-AGT-5.a era owner, not this lane.

---

## Appendix — independent r2b re-verification (cure-reviewer, later same day)

A second fresh cure-review pass re-ran this review end-to-end against disk; every claim above REPRODUCED EXACTLY:
- Phantom: tip 675241a04, `master..HEAD` = 1, porcelain 0, %ae/%ce separate greps detain both, 0 trailers; 9-file declared set, zero forbidden surfaces.
- Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code): new pair **24T/100A**; combined family **352T/13727A, sole failure** `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog`; guard battery **OK 447T/68244A**; `CommandDocsDrift|SettingsWriterCensus` standalone OK 11T/4761A (COMMANDS.md hint row regenerated in-step — no regen rider owed).
- Base-red inheritance RE-PROVEN: the tested paragraph ("The tool names a pattern can start with…") dumps byte-identical at :760 in `e4a705244` and `675241a04`; the test file is not in the commit; zero src/Tools touches.
- Non-vacuity UPGRADED from reasoning to EXECUTED proof: swapping the pre-fix `da90aceb0` src pair in (git-show file writes only; no git state ops), the MAJOR pin ERRORS with `RuntimeException: …/config.json holds a JSON list, not an object; fix it by hand first` (SettingsWriter.php:296) and the MINOR pin FAILS showing the fabricated `pattern ''/action 'nope'` rows. Restoring tip source: porcelain empty (cmp-clean), both pins green again (2T/12A).
- NIT-3/4 application CONFIRMED on disk: REPORT.md reads "9 keys" (lang hunk adds exactly 9 `host.permissions.*` + the widened `cmd.permissions.hint`) and the M3 sentence now says "short-circuits only an empty delta, not unchanged values"; FIX-1.md present.
- One NEW NIT (non-blocking, prose only): `docs/PERMISSIONS.md` @@911 hunk says `/permissions saved` "numbers every stored entry" — post-MINOR-fix only LOADABLE rows are shown/numbered (shape-junk rows are dropped; grammar-dead rows still ride, disclosed). True for clean configs and self-corrected on-screen by the header's live "(N)" count, so NIT not MINOR; welder may fold "every" → "every loadable" alongside the standing C-AGT-5.a doc row, or leave as-is.

VERDICT CONFIRMED: CLEAN — 0C/0M/0m, +1 non-blocking NIT (new, above). Approve for weld; weld duties unchanged.
