# C-AGT-6.e — CLOSED. Commit 15be076bc on cc/C-AGT-6.e (base e91eb1d47), tree clean, never pushed.

PR status chips: `pr` field on SessionSummary served from PrStatusCache's off-summary
refresh path (R13, plan §6), gh `pr view` via GhRunner on the serve loop, 60s TTL,
self-retiring watch window, every failure shape → null, never throws, never inline.

Files (8, all in ownership):
- create candy-code/src/Workspace/PrStatusCache.php (observe/sweep/verdict + test seams; single shared one-shot timer chain)
- create candy-code/tests/Workspace/PrStatusCacheTest.php — NEW TEST FILE for durations TSV (+28 units, 60 assertions)
- modify candy-code/src/Protocol/Schema/Definitions.php (SessionSummary pr object, nullable, after branch)
- modify candy-code/src/Protocol/ServerContext.php (summary(): PrStatusCache::observe only; structural pin proves no GhRunner/sweep inline)
- modify candy-code-web StatusBar.vue (anchor pr-chip + pr-dot CSS), SessionSidebar.vue (span session-pr — cannot nest anchors in row link), statusBar.spec.ts, sidebar.spec.ts (mountSidebar gains defaulted `extra` param)

Gates (all targeted, plain pipe):
- php -l: 4/4 clean. Protocol regen chain: gen-protocol-schema.php --write + npm run gen:protocol → trio verified consistent, then RESTORED pre-commit (schema.json, generated.ts unmodified in commit; SERVER.md generator never touched it — no SessionSummary field table exists; [docs:SERVER.md] satisfied as no-op).
- phpunit --filter 'PrStatusCache|SessionSummary|ServerProtocolDocumentationDrift|DuplicatedTestHelperDrift|GlobDialect|TreeWideGuardRoster|ReadmeSuiteFigureDrift|ProtocolSchema': Tests 102 — only reds are the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 27,973): FOREIGN-inherited, suite-figure.json is welder/gate-owned; this step adds ~+28.
- PrStatusCache suite: 28/60 OK (Deprecations: 2 = pre-existing config baseline, GhRunnerTest shows identical).
- vitest --no-file-parallelism statusBar/sidebar/branchBadge: 3 files, 33 passed.
- vue-tsc --build delta vs /tmp/opencode/vue-tsc-cweb4c-base-e14.txt: standing-family only — one line-position shift dormantRoster.spec.ts(56→59) from sibling C-SES-7.e merge landing after the base capture (git diff e91eb1d47 proves I never touched it; identical TS2532 message). Zero new errors.
- Mutations 3/3 discriminating exactly one red each (cp+md5 restore verified, md5 78c984cf…): TTL 60→30 → ttl-stand pin red; exitCode check neutered → strengthened refusal pin (shim prints valid JSON then exits 3) red; fetch-override catch removed → throwing-fetcher pin errors.

Deviations: none from the design block. Dispatcher untouched; dist/ untouched; no proc_open
site added (rides GhRunner). R13 ruling cited in commit body.
