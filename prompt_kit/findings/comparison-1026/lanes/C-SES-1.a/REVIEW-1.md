# C-SES-1.a round 1 — VERDICT: FINDINGS (0C/0M/1 step-local MINOR/2 NIT)

Substance verified statically (all pass):
1. Gate + placement: persist block at NonInteractive.php:653-683 inside `if ($ownsSession && $opened !== null && $sessionStore !== null)` + instanceof EnhancedSessionStore (:675), inside the same try whose existing catch is one stderr line with unchanged exit. Nothing appended in the step observer — buffer (:405-411) summed once after $mint() (Usage::sum null-preserving, SpendLedger::account null-no-op at SpendLedger.php:137, plus() carries unpricedModel first — echo/unmeasured runs fabricate no row). Sink arity matches account's $record($usage,$model); appendUsageRecord folds cost_usd/cost_unpriced cache in same txn (EnhancedSessionStore.php:2481-2484). EngineBackend::model() exists (:819).
2. Rows: text uses — via Lang::t('cli.session.list.spend_none') when null else SpendLedger::boundUsd (≥-spelling, one app spelling, :86-89); JSON adds spentUsd/spendUnpriced by array-union (no collision with snake_case cols).
3. New test is a real pin: loopback billed endpoint + priced/unpriced/echo/resume/same-stderr-line cases would fail pre-fix (no -p append caller existed). No fabricated paths, no glob-shaped or **Refused:**-shaped literals.
4. §1.8 in-step: README paragraphs in same commit; candy-code/lang/ ships en.php only → LangParityTest safe; DuplicatedTestHelperDriftTest SELECTION_ENV_KEYS licens legit (const byte-identical to NonInteractiveSessionPersistenceTest:46-52, alpha-slotted, honest reason).
5. Scope/law: exactly the brief's 7 files, one commit, author/committer detain, plain message, zero trailers.

Findings:
- [MINOR step-local] tests/Cli/NonInteractiveUsageLedgerTest.php:110 — scandir($path) is an unresolved walker site in a file naming the package root (:320 dirname(__DIR__,2)); TreeWideGuardRosterTest:1911/:2958 will redden — the byte-identical twin NonInteractiveSessionPersistenceTest carries exactly such a row (:607). Fix: add 'Cli/NonInteractiveUsageLedgerTest.php' => ['scandir($path)'], to WALKS_A_DIRECTORY_THE_TEST_MADE in-lane.
- [NIT] Subcommands.php:1393 %-9s pads bytes not cells: ≥$0.5000 (10B/8c) and — (3B/1c) misalign ~±1-2 visual cells; pins use \s+ so green — cosmetic only.
- [NIT disclosed] Brief "each buffered usage" → lane accounts one summed row per run; totals/unpriced identical, per-step row granularity differs (docblock justifies via lazy-mint ordering — acceptable).

Gates: shell denied phpunit — unrun. Weld re-runs: --filter 'NonInteractive|SessionSubcommands|SpendLedger' (claimed 166T/818A), TreeWideGuardRoster (RED unless roster row lands), DuplicatedTestHelperDrift, five-guard pair-only (expect live 25,665 = +6T). Duties: durations.tsv row for NonInteractiveUsageLedgerTest.php + suite-figure/README re-pin at weld.
