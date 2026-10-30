# C-CTX-5.b — REVIEW-2 (round 2, fresh reviewer)

VERDICT: CLEAN. 0 CRITICAL / 0 MAJOR / 0 MINOR. Both round-1 minors verified cured at b09090f5c; the disclosed Chat disclosure is sound (with a refined rationale, below). Ship-eligible.

Tip b09090f5c verified on disk: porcelain clean, %ae/%ce detain@interserver.net 1× each, zero Co-Authored, FIX-1.md (5,907 B) rides the branch. Diff master(85463f3fd, moved)…HEAD: 10 files, all in the r1-ruled set (design files + the 3 band-numeral pins + FIX-1.md); zero weld-only files; `prompt_kit/`, docs other than CONTEXT.md untouched.

## Gates (as-run, cwd candy-code in the lane worktree, plain pipe, PtyRun exclusion moot — no filter matched it)
- AheadOfNeedSkipRulesTest: **9T/129A OK** (2 deprecations = house pre-existing).
- Family: **93T/529A OK**. `--filter SessionHost`: **84T OK**.
- Guard union (GlobDialect|DuplicatedTestHelperDrift|SwallowingCatch|DuplicatedDocBlockLine|SymbolCitation|DocFigure|EnvRoster|TrustKey|ConfigWriteProducer|ReadmeRoster): **226T/69,139A, sole failure = SwallowingCatchSrcCensus:137 naming foreign src/Tools/BuiltIn/SkillTool.php::execute** — standing red (8ad2de199), untouched by this diff. r1's 208T did NOT reproduce; 226T is my as-run number with the class list above.

## Cure verification
1. **MINOR-1** SessionHost.php:1017-1024: idle watch now reads `$this->backend()`, EngineBackend→`model()` else `''`, folds into `[...$this->aheadOfNeed, 'model' => …]` — mirrors the dispatch arm exactly; harm comment present. M1 reasoning: deleting the trio makes `held['model'] ?? 'ABSENT'` red ONLY in testTheIdleWatchStampsItsModelForTheSwitchRule (every other consumer passes `?? null`, which still matches). Non-vacuous.
2. **MINOR-2** CompactionService.php:1364 `aheadOfNeedModelMatches` (null-legacy kept, EXACTLY ONE copy — grep union: def :1364, callers :1350 + SessionHost:2432); `:1348` delegates — negation `heldModel!==null && heldModel!==currentModel` is byte-equivalent to the old branch, request truth table unchanged (gate legs 7T… pins both halves incl. the empty-idle-pair). Drop arm model-only; M2 reasoning: revert → only testThePaidInFlightIdleSlotSurvivesTheSubFloorDrop reddens (band <64k, slot in-flight, same-model `''`/`''` passes the model half). Widening the matcher with a floor → gate-test red too. Non-vacuous.
3. **SCENARIO CORRECTION — I AGREE, independently verified**: admitPrompt's fold splices+nulls a landed slot at SessionHost.php:1641-1646 (`summaries!==[] && fingerprint matches` → `$this->aheadOfNeed = null`), and `dispatch()` — where the drop arm lives (:2429) — is called only afterward at :1718. Landed slots therefore cannot reach the drop arm on a fold-firing submit; the reachable victim was the IN-FLIGHT paid slot (dialed+billed, `id` latch at :851 drops the orphaned return, request arm refuses re-ask under the floor). The pin models exactly that (PendingAheadBackend unresolved promise, release() proves the return lands).
4. **Chat drop-arm floor half (disclosed) — ACCEPTED, with a stronger rationale than the fixer's**: "the estimate only grows" is false in general (an adopted fold rewrites `$baseHistory` smaller, and the splice at Chat.php:10183 skips in-flight slots, so sub-floor drops ARE reachable). But every Chat slot is minted ≥floor (sole mint :11462 under `allowed(≥floor)`), and any history shrink rewrites the transcript the slot's fingerprint digests — so the same drop arm's staleness half (or the splice's fingerprint check) discards the slot anyway; the floor half nulls early a summary that could never splice or survive. No paid USABLE summary is ever lost; identical to pre-step for landed slots. NIT-grade prose, no action; weld row may note the reasoning.
5. Small-window by-design pair pinned both hosts: `...OnTheHost` (:331) + `...OnChat` (:351), green in the 9T run.
6. Docs: CONTEXT.md "64,000-token floor" == `AHEAD_OF_NEED_TOKEN_FLOOR = 64_000` (:908); the model-only-drop sentence is code-true on SessionHost and the doc never claims floor-drops-held, so Chat's superset drop stays truthful. No ARCHITECTURE re-derivation owed: DocFigure arm green; landed-blob demand met — `git show 85463f3fd:candy-code/docs/ARCHITECTURE.md | sed -n '155p'` = "…dispatch arms for 64" (lane adds zero dispatch arms).

## Sweep
No new emitters/sprintf/Lang keys/timeouts (E646-clean); test file final + strict_types; tearDown reflection-cancels the idle alarm (no loop residue); PendingAheadBackend fixture appended in-file (no new test FILE, no durations drift); band-bump trio re-verified numerically (3×46k → ~69.3k > 64k floor).

## §4 Weld carries
- durations.tsv row: `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php` at **9 tests** (welder-minted; row set unchanged by fix — no new file).
- Suite figure delta: **+9T/+129A SUPERSEDING +7T/+76A** (exact: 9T/129A reproduced; sibling test-file edits add zero counts).
- Numeral riders: NONE owed (DocFigure green, ARCHITECTURE :155=64 landed, CONTEXT.md consistent). Weld row should list the 3 band-numeral sibling files as in-step pin flips (r1 legitimacy rationale stands) and the foreign-SkillTool census note above.
