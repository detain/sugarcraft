# C-CTX-5.b — Ahead-of-need skip rules — DONE

Branch `cc/C-CTX-5.b`, base a6c45be43. **Tip: 240f0f425467c7e7324c6b537e8d6e1ffcdd5999** (`git cat-file -t` = commit; %ae/%ce both detain@interserver.net, separate greps =1; zero Co-authored trailers; `git status --porcelain` empty).

## What landed
- `CompactionService`: `public const AHEAD_OF_NEED_TOKEN_FLOOR = 64_000` + static `aheadOfNeedAllowed(int $tierTokens, ?string $heldModel = null, string $currentModel = ''): bool` — the single owner of OpenCode's skip rules (under-floor skip; held-summary-dropped-on-model-switch).
- Chat `dispatchTurn` ahead-of-need block: session model = `backend instanceof EngineBackend ? model() : ''`; drop arm gained `!aheadOfNeedAllowed(..., held['model'] ?? null, $sessionModel)` clause; request arm gated by `aheadOfNeedAllowed($aheadTokens, null, $model)`; latch stamps `'model' => $sessionModel`. Chat slots mint only here (verified: state-map write-back + per-keystroke carry are pass-throughs; HandlesTurnCompletion :485 rebuild spreads, so 'model' survives landing) → no out-of-region edits needed.
- SessionHost `dispatch` block: same shape; stamp rides `requestAheadOfNeed()`'s bool return (`if (request...) { aheadOfNeed = [...aheadOfNeed, 'model' => $currentModel]; }`) because the latch itself is built in the out-of-region method. Drop arm restructured into one aheadOfNeedAllowed || stale-fingerprint condition.
- `docs/CONTEXT.md`: the one skip-rules sentence landed; DocFigure/guard families green on it.
- `tests/Host/SessionHostAheadOfNeedTest.php`: fixture heavy-pair band 41_500→46_000 (~69,300 est) + docblock arithmetic rewritten.

## NEW test file (welder: needs durations.tsv row)
- `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php` — 7 tests / 76 assertions, gate truth table + both hosts × {under-floor skip, model-switch discard, small-window-never-prefetches-but-folds-at-blocking}. Expected suite delta: **+7T / +76A** (sibling edits change no counts).

## Mutation proofs (all cp+md5-restore verified)
- M1 floor branch → `return true`: exactly 5 reds (gate + both hosts under-floor + both small-window).
- M2 model branch → never fires: exactly 3 reds (gate + both hosts model-switch).
- M3 Chat request-arm leg → `&& true`: exactly the 2 Chat floor pins red (proves Chat call-site wired; line 269 pin confirmed).
- M4 SessionHost request-arm leg → `&& true`: exactly the 2 host floor pins red (testTheHostSkips... + testTheSmallWindow...OnTheHost).
- Drop-arm wiring for both hosts is proven transitively by M2 (the only route to the drop arms' model verdict is the neutered branch).

## Gates run
- AheadOfNeedSkipRulesTest: 7T/76A OK.
- Family `'AheadOfNeed|BackgroundSummary|SummarySafeguard|CompactionFallbackParity|SessionHostIdleAuto|IdleAutoCompaction'`: 91T/476A OK.
- Guards: `GlobDialectDifferential|DuplicatedTestHelperDrift|SwallowingCatchCensus|DuplicatedDocBlockLine|SymbolCitationDrift` 42T OK; `DocFigureProseDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift|ReadmeRosterDrift` 166T/44,971A OK.
- All under cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS` plain pipe. 'PHPUnit Deprecations: 2' is pre-existing house noise (AccessorDeprecationTest.php:168/:202 non-public providers) present on the untouched base too.

## Disclosures / deviations
1. **Sibling band bumps outside the brief's files list (3 files):** tests/Chat/BackgroundSummarySpliceTest.php, tests/Chat/BackgroundSummaryStaleFingerprintTest.php, tests/Host/CompactionFallbackParityTest.php — their ~62,500-token mirror-bands sit under the new 64k floor, so their prefetch pins legitimately started skipping (8 reds before the bump). Raised 41_500→46_000 + docblock arithmetic only. SessionHostIdleAutoTest needed no bump (already 46_000). Same band family, zero behavior change, C-CTX-5.b is the only lane touching them this wave.
2. **Fingerprint-object untouched:** the design's "fold the model id into the held-summary fingerprint" is implemented as a 'model' identity key on the held slot + the aheadOfNeedAllowed model half (HistoryFingerprint.php is outside the step's files; folding into the digest object would require threading the model through out-of-region 85% splice-recompute sites — the slot-key form is behavior-equivalent for the skip rule and touches nothing but the step's files).
3. **Idle-watch slots are un-stamped** (requestAheadOfNeed :1016 caller is out of region): `'model' ?? null` → model rule stays inert for idle-requested slots; they still obey the fingerprint/floor checks. Conservative by design; disclosed.
4. `@var` shape lines for both held slots extended with `model` (Chat :1739 region-level, SessionHost :184) — same-slot documentation, in-step with the shape change.
5. DuplicatedTestHelperDrift accusation cured by renaming MY copied const/helper (RECORD→HELD_RECORD, recordsFor→heldRecordsFor) rather than the guard; guard files untouched.
6. Chat.php uses the FQN `\SugarCraft\Code\Host\CompactionService::` at its three new sites (file convention: no import in Chat.php).
7. Fixture test 5's Chat ctor carries no `hooks:` arg (matches the green sibling pins); it is deterministic as written (held slot short-circuits any fork path); no flake seen across the ~8 runs.
8. No protocol/schema surfaces touched → regen-verify-restore of the trio was a no-op; dist/ untouched; forbidden files never staged.

## Wide-sweep attribution (welder info)
- `--filter 'SessionHost'` at tip: 84T OK.
- `--filter 'ChatTest|TurnCompletion|Compaction|Compact'` at tip: 794T, 6F — all 6 reproduce IDENTICALLY at base a6c45be43 in a throwaway worktree (2 probed in isolation: ChatTest::testASettledTurnAsksTheTitleModelForASuggestionAndShowsIt + AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker, same AsyncCmd-instance assert). INHERITED standing reds at the branch point (title-suggestion/breaker tests), NOT caused by this step; gate lists for C-CTX-5.b are all green at tip.
