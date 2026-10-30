# W24 WAVE-REVIEW-1 (round 1, whole-wave audit)

Reviewed at master 80fe2275b; HEAD moved to 86ad79b52 mid-review (concurrent C-SEC-7.b/C-SEC-8.d = W26/W28 picks landing — expected, wave's own lanes judged on landed content; concurrent-master caveat applies to one transient run, see Gates).

## Scope
plan_comparison.md §3 W24 = 10 steps: C-AGT-6.e, C-CTX-5.b, C-PRV-3.d, C-SEC-6.b, C-SEC-6.d, C-SEC-8.a, C-SEC-8.f, C-SES-6.d, C-SES-7.b, C-SES-7.d. All 10 carry `#### ✅` marks (plan is UNTRACKED by design — all marks working-tree-only, expected convention).

## Bookkeeping
40 mark/pick/cure SHAs cat-file'd; all exist; every master-side pick is a merge-base ancestor of HEAD (the 13 NOT-ON-MASTER names are branch-side SHAs paired with landed picks — convention). 27 unique master-side wave commits: `git log --format='%ae %ce'` all `detain@interserver.net detain@interserver.net`, zero trailers flagged. No cc-cmp-*/cc/* worktree or branch for any wave-24 ID survives (`git worktree list` + `git branch --list 'cc/*'` residue-scan empty for the ten IDs).

## Landed-blob proofs (git show HEAD:<path> / tip reads)
- C-PRV-3.d: `src/Tools/BuiltIn/AdvisorTool.php` present; BuiltIn dir == 40 files matching `docs/ARCHITECTURE.md:31` "40 built-ins"; `lang/en.php:650` CANDY_CODE_ADVISOR_MODEL inside cli.help.screen (weld-cure de4ae441c landed); `Bootstrap.php:10543` getenv on production path; ENVIRONMENT.md:42 row; DUR tsv:234.
- C-CTX-5.b: Chat.php:11535–11562 ahead-of-need block live calling `CompactionService::aheadOfNeedAllowed`; `src/Host/CompactionService.php:908` = `64_000` == `docs/CONTEXT.md:156` "64,000-token floor" (doc-truth pair holds at tip).
- C-SES-7.d: Chat.php:20771–20773 r1-CRITICAL gate `if (!$this->backend instanceof Backend\EngineBackend) { return null; }` LIVE in resumeCostHold(); DUR rows tsv:271/:877 as claimed.
- C-SES-7.b: `ParsedArgs.php` 'session' region carries `--format`/`--name` value flags (weld-erratum region honest); `Subcommands::SESSION_ACTION_FLAGS` 5 refs; `cli.session.import.usage` en.php:1143; tests/Cli/SessionImportSubcommandTest.php present; BinCandyCodeAutoloadGuardTest.php:35 THIRTY-SIX; StderrEmitterCensus+BinGuard run green (133T).
- C-SEC-6.b: `src/Permissions/ProjectNarrowingRules.php` + Bootstrap wiring (production-reachable, not dormant); Subcommands.php:4083 "five doors" rider landed; DUR tsv:967/:968.
- C-SEC-6.d: `src/Hooks/BuiltIn/AuditLogReader.php` + ReadPathCensus line 'Hooks/BuiltIn/AuditLogReader.php|fopen' (guard :660); schema `permission.audit`; generated.ts 2 refs; DUR tsv:422/:978.
- C-SEC-8.a: `src/Context/McpOutputFence.php` consumed by McpToolBridge + McpCallTool (wired); DUR tsv:746 (6T/0.10 as claimed).
- C-SEC-8.f: exactly 7 `cli.login.keychain.*` keys in tip en.php; `src/Support/Keychain.php:376` proc_open bounded (pipe drain + deadline + `ProcessReaper::terminateAndClose`, group-kill on timeout) with DescriptorInheritanceGuardTest:624 'Support/Keychain.php::spawn' row; repo child-lifetime tool scopes candy-code to its own guard (tool :310 comment) — accounted. E646: bounded CLI-helper drain + spend-cap refusal, no blanket LLM timeout added by any W24 lane.
- C-AGT-6.e: `src/Workspace/PrStatusCache.php` + ServerContext.php:18/:470 observe() wiring; schema `"pr"` def :1277; DescriptorInheritanceGuardTest:714 NOT_A_SPAWN row; DUR tsv:979 (27T as claimed).
- C-SES-6.d: pick e306bf4fc touches ONLY `.github/workflows/release-phar.yml` — "workflows-only, no DUR" claim true.
- Protocol rider c0bb77e7c (shared by AGT-6.e + SEC-6.d): one commit carries schema+SERVER.md+generated.ts+dist — single-regen law honored, no double-write collision.

## Cross-lane interactions
- Chat.php: CTX-5.b (:11535+) and SES-7.d (:10157/:20769) coexist structurally, no mutual cancellation. Bootstrap.php: PRV-3.d advisorBackend (:10527+) + SEC-6.b narrowing-rules feed coexist.
- ARCHITECTURE dispatch-arms numeral: SES-7.d proved "64" at its weld; CTX-5.b proved "65" at its (later) weld after C-TUI-3.a /copy; tip blob :155 reads "65" and DocFigureProseDriftTest's live-count arm passes — the two marks are dated points-in-time, not a contradiction.
- Env-var roster law: all getenv('CANDY_CODE_*') newly added across the 27 picks (ADVISOR_MODEL, MAX_COST, PERMISSION_MODE, SESSION_RETENTION_DAYS) have ENVIRONMENT.md rows AND cli.help.screen entries (SESSION_RETENTION_DAYS at en.php:679, PERMISSION_MODE/ADVISOR_MODEL counted); EnvRosterDrift 37T + Help roster arm green.
- Wave-introduced guard red: C-SES-7.d f4e747cf0 shipped the byte-identical HEAVY_CHARS const pair without a roster row (its weld gate list omitted DuplicatedTestHelperDrift); red stood on master from ca1e0b51f until weld-cure a1b075d1e adjudicated the pair into ACCEPTED_CONST_DUPLICATION. Guard green at tip; the C-SES-7.d §4 mark does NOT record this defect its pick shipped (only the C-SEC-7.a mark names it "foreign") — dated erratum owed at §7.

## Gates (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)
- DuplicatedTestHelperDrift: 14T/203A OK. EnvRosterDrift: 37T/40434A OK.
- DocFigureProseDrift run1: 92T + 1 ERROR HelpTest.php:509; run2 + isolated `testEveryBackendSelectionVariable...`: 4/4 OK — did not reproduce, attributed to the concurrent welder moving HEAD 80fe2275b→86ad79b52 under run1 (concurrent-master caveat, no wave defect).
- StderrEmitterCensus|BinCandyCodeAutoloadGuard: 133T OK. ReadmeRosterDrift|GlobDialect|SettingsSchemaDocDrift|ToolDocDrift|KeyBindingDrift: 274T OK.
- Wave functionality: Advisor|PrStatus|AuditLog|McpOutputFence|ProjectNarrowing = 76T OK; SessionImport|ResumeCost*|LoginKeychain = 58T OK.
- Generators: gen-command-docs/gen-tool-docs/gen-settings-doc --check "up to date" rc0 x3; scripts/gen-protocol-schema.php --check rc0.

## Findings
1. W24-LEDGER-1 (ledger-note): §4 C-SES-7.d mark lacks a dated note that pick f4e747cf0 shipped the HEAVY_CHARS duplication red, cured only at a1b075d1e (law (g) class: gate list missed DuplicatedTestHelperDrift). Fix: append dated erratum to the SES-7.d mark; add DuplicatedTestHelperDrift to §7 reviewer-window duty list (already half-recorded via C-PRV-3.d's Help-window note).
2. W24-LEDGER-2 (ledger-note): scripts/parallel-tests-durations.tsv still has no row for candy-code/tests/Cli/SessionImportSubcommandTest.php (11T) — disclosed §7 carry at weld (WEB-5.a precedent); battery regen must mint it (file runs in zero shards until then).

## Verdict
WAVE-VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 ledger-notes. All 10 steps landed what §3/§4 promise; all guards and generators green on the merged tree; hygiene (authors, worktrees, DUR, riders) clean.
