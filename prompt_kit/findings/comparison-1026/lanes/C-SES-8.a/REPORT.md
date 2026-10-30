# C-SES-8.a — lane REPORT (live journal)
Base 574e69f1d (== master at cut). Branch cc/C-SES-8.a, worktree /home/sites/cc-cmp-C-SES-8.a.

## Verdict
COMPLETE. usage.rollup RPC + web Usage panel built, gated, committed.
Tips: b95a0e181 (RPC skeleton: UsageMethods + Dispatcher + MethodSchemas row) + 06352783a (UsageMethodsTest + UsagePanel.vue + SidePanels mount + panels.ts id + usagePanel.spec.ts). Both cat-file-verified commits; %ae/%ce = detain each, zero Co-Authored-By. Tree clean; trio paths clean.

## Milestones
- [x] M0 skeleton commit (php UsageMethods + register + schema row) — b95a0e181
- [x] M1 php tests green (UsageMethodsTest 9T/49A, baseline 2 deprecations)
- [x] M2 regen-verify-restore trio — with regen'd trio: drift guards 13T/362A green, generated.spec 3/3 green (NO edits needed), dormantRoster green via UsagePanel caller; trio then `git checkout --` restored (never committed)
- [x] M3 web UsagePanel + mount + spec green — vitest serial 4 files 24/24; vue-tsc error set IDENTICAL to pristine base (21 pre-existing, zero mine)
- [x] M4 final commit 06352783a, gates below, this report

## Gates (lane tree, restored trio)
- env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter UsageMethods → 9T/49A OK (11.4s)
- --filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → 13T/1F: ProtocolSchemaDriftTest:39 stale schema.json (EXPECTED §1.8 — green on regen'd trio, proven)
- five-guard filter → 134T/2F: ONLY ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live, brief says report-not-fix)
- vitest --no-file-parallelism usagePanel+panels+dormantRoster+generated → 24/24 pass (both trio shapes)
- typecheck (vue-tsc --build): 21 errors == pristine-base set (checked via detached worktree @574e69f1d); zero from lane files

## Shape notes
- RPC: scope enum session|all (required); days enum 7|30 else invalid_params naming days; sessionId via SessionMethods::sessionId for scope=session, ignored for all; store null → reason 'no durable ledger store is open here'; ONE rollupFacts() snapshot feeds days+models+derived totals+unpriced bound; retentionDays = Bootstrap::sessionRetentionDays() (env CANDY_CODE_SESSION_RETENTION_DAYS pinned 45 in test); answer keys EXACT [days,models,totals,unpriced,retentionDays,reason]; no write path.
- Panel: ≥-law via spendLabel (totals bit + per-row bits); retention read-only, Settings link ONLY when settings.schema (existing store, loaded once) marks sessionRetentionDays remotely writable; server strings plain {{ }} spans; generation latch drops superseded answers (spec-pinned).
- Test seeding idiom: EnhancedSessionStore::appendUsageRecord($sessionId, Usage::new(totalTokens:, costUsd:, unpricedModel:), 'model').

## CARRIES (weld/wave duties)
1. DUR row: candy-code/tests/Protocol/UsageMethodsTest.php — 9T/49A, ~11.5s.
2. Trio regen at wave: php scripts/gen-protocol-schema.php --write + (cd candy-code-web && npm run gen:protocol); then dist rebuild (vite build). generated.spec.ts needs NO edit (verified green on regen'd trio). dormantRoster stays green via UsagePanel caller (verified).
3. suite-figure re-pin: +9T from this lane on top of the batch-era staleness pair.
4. HARNESS GOTCHA for reviewers of this lane: stale node_modules/.vitest-cache replays the pre-UsagePanel eager-glob and false-reddens dormantRoster — rm -rf candy-code-web/node_modules/.vitest-cache (and .vite/vitest) before vitest, then results match above.
5. Serialized files: SidePanels.vue + panels.ts touched additively (mount line last in v-else-if chain; 'usage' appended last in PANELS + PANEL_LABELS) — downstream P-CTX-BREAKDOWN/C-WEB-3 steps serialize behind this.
6. Not touched: lang/en.php, docs/SERVER.md (regen-only), suite-figure.json, durations.tsv, dist. Co-tenant regions untouched.
