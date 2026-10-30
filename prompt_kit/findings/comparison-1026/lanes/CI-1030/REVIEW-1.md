# REVIEW-1 — lane cc/CI-1030 (ad-hoc owner lane, base 59ea47407, tip d9c381689)

VERDICT: FINDINGS — 0 CRITICAL / 2 MAJOR / 2 MINOR

Merged-shape: `git merge-tree --write-tree` vs master f11a0b019 CLEAN (tree 1ebe42990); real cherry-pick of the EXCL-start range 59ea47407..d9c381689 in throwaway worktree /home/sites/cc-cmp-CI-1030-review (branch cc/CI-1030-review): all 7 apply clean, tip 9d21718f6. Conflict list: NONE. (Brief's master ad60f7a3f was stale; master moved to f11a0b019 = C-TUI-3.d before review.)

## MAJOR-1 — SelfUpdateTest roster red on the MERGED tree (fix is base-correct, merge-stale)
candy-code/tests/SelfUpdateTest.php:103,134 pin `testTheRosterCarriesTwelveVerbsWithSelfUpdateInAlphaOrder` to 12 verbs. C-TOOL-5.d (d7a2efbf1, landed above the lane base) adds the 13th bin verb `lsp` (index 6 in alpha order). Merged-tree batch-1 gate: 90T/1478A/1F — the sole failure is exactly this test (expected 12 vs actual 13).
Fix: append 'lsp' at position 6 of the expected roster, rename method …TwelveVerbs… → …ThirteenVerbs…, word-number pin 'twelve' → 'thirteen' (docblock if it narrates the count), re-derive from bin/candy-code on the MERGED tree, not the lane tip.

## MAJOR-2 — phpstan-baseline userConfig count bump is impossible; residue attribution stale
candy-code/phpstan-baseline.neon: entry `nullsafe.neverNull` / "Using nullsafe property access ?->userConfig on left side of ??" / path src/Chat.php bumped 3→5 claiming "current-master truth". Merged Chat.php contains exactly FOUR `?->userConfig` sites (:2965,:13656,:16969,:20220 — grep-proved on master, base, lane tip, merged tip; the range never edits those lines). 5 neverNull errors for one message in one file cannot fire; PHPStan 2.1 validates baseline counts, so CI phpstan goes RED on the merged tree ("expected 5, found ≤4"). The same hunk's narrative ("in-flight lanes fix the sites, revert then") is stale: C-SES-8.b / C-TOOL-5.d / C-SEC-8.b / C-SEC-7.e are ALL merged into f11a0b019 and none touched the userConfig lines; their Chat/Bootstrap/EngineBackend/SessionHost additions (+48/+31/+51/+17) are also un-baselined. (Reviewer shell map denies `php`; live phpstan run = weld-gate duty.)
Fix: set count to the true merged-tree number (≤4; derive by phpstan run or exact-site count), re-derive the baseline against the MERGED tree for all four landed-lane file sets, and correct the commit narrative in the follow-up. The 16 added blocks themselves are clean: exact per-error messages with CI-1030 reason comments, zero wildcard/rule-level suppressions (audited hunk-by-hunk).

## MINOR-1 (step-local) — App.php SessionTab::$name real-defect fix has no in-lane regression pin
The defect story is REAL and adjudicated correct: src/App.php read `$tab->name` but SessionTab exposes only `sessionName` (no `name` — verified on merged blob); undefined-property read under strict_types warns and yields null, so the `?: Lang::t('app.tab.chat_default')` fallback always fired — silent per-session-name loss on tab display. Fix `$activeTab !== null ? $activeTab->sessionName : null` is right and the site is production-reachable (handleToolResult path :5260). BUT no test added: a revert of this one-liner reddens nothing. Fix: add a pin (construct App state with a named tab, assert view()/label carries sessionName, not the default) — or weld rider.

## MINOR-2 (step-local) — REPORT.md doc-truth defects (report is committed on-branch; fix same shape)
lanes/CI-1030/REPORT.md claims "MediaSettingsTest also has no durations.tsv row" and same for MenuBarLabelDerivationTest → "runs zero shards". FALSE: both rows exist at base and merged (…durations.tsv base lines 1135/1256; lane 1138/1259) pointing at existing files, so CI did run the stale-openrouter red — it is a genuine class-(a) item, not invisible. Also durations line numbers drift by tree (base 932/961, lane 935/964, master 937/966 — report's "933/962" matches none) and 175875bba's message says residue "12" while the final commit corrects to 13 (narrative churn, disclosed).

## Verified CLEAN
- Merge/conflicts: none (see header).
- Guards green on MERGED tree (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS): ChatTest|MouseWiring|DenialPrefixRoster = 287T OK; TreeWideGuardRoster|CommandFailureTranscript|MenuBarLabelDerivation|AutomaticCompactionModelSummary|ReadmeRosterDrift|EnvRosterDrift|TrustKey|KeyBindingDrift|DuplicatedTestHelperDrift|GlobDialect batch = 397T/48129A OK; batch-1 (incl CliLangCatalogue, PageQuote host) 90T/1478A with SelfUpdateTest the ONLY failure.
- lang tls-sort: fix correct (cli.tls_args mismatch key re-sorted before missing); arm CliLangCatalogueTest:196 assertSame(sorted,actual,SORT_STRING) → strip = red. Non-vacuous.
- PAGE_QUOTE_FIELD_BYTES 282→322 + far-filler 40→46: constant feeds live sweep pattern `.{1,322}?` (:2115); 282 cannot span the 311-byte README survivor → exact-holder roster assertion reddens. Mutation-reasoned non-vacuous; docblock rewritten truthfully for the widened bound.
- ChatTest 5 settle-batch reds: green on merged tree; new helper landedMsgsForSettleCmd asserts MEMBERSHIP + count 1 (negative legs assert empty set + backend->asked empty), not bare positions — structurally sound.
- MouseWiring ctxmeter: exact-id allowlist arm at :167 (Renderer::CTXMETER_ZONE), roster-consistent.
- SkillWorkshop denial-roster row: green under merged DenialPrefixRoster.
- TreeWideGuardRoster 3 new walker licences: each names a walk that verifiably exists (RDI in RestrictedSafeModeFlagsTest + NonInteractiveSessionPersistenceTest; scandir($this->tlsDir) ServeArgsTest:509); tests/Skills dir exists so roster shapes stay live.
- Forbidden files: zero touches suite-figure.json/durations.tsv/dist/composer.json (diff grep-proved). No NEW test files anywhere in range → no DUR mints; ChatTest/MouseWiringTest case-count shift rides informational durations rows (conservation gate judges live totals) — glance at weld K-gate only.
- Hygiene: 7 commits, %ae and %ce separately grepped = detain@interserver.net ×7, zero Co-Authored trailers.

## §7 Riders (class (b)/(c)/(d) leftovers — LISTED, not findings; weld/closeout duty)
1. durations.tsv stale-path rows candy-code/tests/Skills/SkillsHistoryUndoTest.php + SkillWorkshopTest.php (master lines 937/966) point at nonexistent files (real homes tests/Host/Commands/ + tests/Tools/BuiltIn/) → kills 2/4 parallel shards rc=2. File lane-forbidden; weld mints/fixes rows.
2. suite-figure/README staleness pair rides the era (closeout duty, never mid-run).
3. Ad-hoc owner lane: no plan §4 design block → no §4 mark row; lead marks with '✅ d9c381689(+fix) CI-1030 (ad-hoc owner lane)' under nearest §4 heading only if house convention file exists, else skips (disclosed).
4. candy-code-web eslint leg blocked by host-wide composer SIGSEGV era (owner-known); Codacy/codecov external-infra reds not ours.

## Weld instruction (NOT executed — verdict is FINDINGS)
Do NOT pick the range as-is: MAJOR-1/MAJOR-2 reddens the merged tree's own gates. Repair on cc/CI-1030 (or a fixer on top): SelfUpdateTest 13-verb roster + userConfig count to true merged number, then re-verify on a fresh merged shape: batch-1 set, phpstan full run (must be 0 unmatched on merged tree), then pick 59ea47407..<fix-tip> EXCL-start (7+fix commits) onto current master, run the merged-shape guard trio again post-pick + a K-gate glance for the durations riders. Throwaway review branch cc/CI-1030-review @ 9d21718f6 kept (holds merged shape for the fixer); its worktree pruned.
