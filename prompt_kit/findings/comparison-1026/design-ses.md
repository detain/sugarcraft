# design-ses — Sessions, sharing, observability & distribution (C-SES-1 … C-SES-9)

Design pass per `BRIEF-design.md`, made on 2026-10-26 against master `5e9195687`.
- Every MODIFY path below was checked with `ls` against the live tree, and every CREATE path was confirmed absent.
- The `file:line` cites are from the same tree.
- None of these items is LATE, so every step is `phase: normal`.

**Doc order follows dependencies, not item numbers.** C-SES-9 sits between C-SES-3 and C-SES-4 because steps in C-SES-5, C-SES-6 and C-SES-8 depend on it. Every `deps` line points upward or to another area.

**Where cross-item halves live.** A few halves ride a step under a sibling item, so that one lane owns each hot surface.

| Half | Lands in | Why |
|---|---|---|
| C-SES-1's picker spend (SES-1-P4) | C-SES-2.b | Both edit `sanitizeSessionRows` and `SessionPicker::renderRow` |
| C-SES-1's web halves (SES-1-P2, SES-1-P4 sidebar) | C-SES-2.c | One protocol lane changes the `SessionSummary` shape once |
| C-SES-9's SES-3-P3 | C-SES-3.b | Same files: `ShareHostCommand` and `ShareCommand::revoke` |

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-SES-1.a | Headless `-p` spend reaches the ledger; `session list` shows spend | M | [headless] [lang:cli.session.list.spend] [docs:README.md] | none |
| C-SES-1.b | Background announcement obeys the `≥` law | S | none | none |
| C-SES-2.a | FTS index: one-transaction rebuild + persisted build marker | M | none ([hot:EnhancedSessionStore.php]) | none |
| C-SES-2.b | Picker shows body hits + snippets + per-row spend; `/` filter reaches FTS | M | [chat:buildSessionPicker,sanitizeSessionRows,runSessionListAction] [hot:Chat/Handlers/HandlesSessionSurface.php] [hot:Tui/SessionPicker.php] | C-SES-2.a |
| C-SES-2.c | Protocol: summaries carry snippets + honest spend; web body search + live `≥` | M | [protocol] [hot:Protocol/ServerContext.php] [hot:web SessionSidebar.vue] [docs:SERVER.md (regen)] | C-SES-2.a |
| C-SES-3.a | Shares die with their session; token redacted from the serve log | M | none ([hot:Session/SessionStore.php]) | none |
| C-SES-3.b | `/share list` across sessions; revoke hardening | S | [lang:cmd.share.] [docs:COMMANDS.md] | C-SES-3.a |
| C-SES-3.c | `session.shares` read RPC (for C-WEB-3's menu) | S | [protocol] [docs:SERVER.md (regen)] | C-SES-3.a |
| C-SES-3.d | Live read-only share links | M | [lang:cmd.share.live] [docs:SERVER.md] [docs:COMMANDS.md] | C-SES-3.b |
| C-SES-9.a | Ledger: no resurrection, `ts` index, one-snapshot rollups | S | none ([hot:EnhancedSessionStore.php] [hot:SessionStore.php] [hot:UsageHostCommand.php]) | C-SES-3.a |
| C-SES-9.b | Serve `/status` prints live spend and cap | S | none ([hot:Host/SessionHost.php] [hot:Host/Commands/CommandContext.php]) | none |
| C-SES-9.c | Phar installs ship bundled presets; build-phar docblocks truthful | S | none | none |
| C-SES-9.d | Release workflows pass tags/inputs via `env:` | S | [workflows] | none |
| C-SES-4.a | Consume-time span stamps, one OTLP body per batch, PID-guarded flush | M | none | none |
| C-SES-4.b | Non-blocking export + wired `tick()` retry | M | [chat:subscriptions] [hot:Chat/Handlers/HandlesSessionSurface.php] [docs:ENVIRONMENT.md] | C-SES-4.a |
| C-SES-4.c | Headless `-p` exports spans | S | [headless] [docs:ENVIRONMENT.md] | C-SES-4.a |
| C-SES-4.d | Serve exports spans (per-session encoders) | M | [bootstrap:workspace,chat(comment)] [hot:Host/SessionHost.php] [docs:ENVIRONMENT.md] | C-SES-4.b |
| C-SES-5.a | Self-update robustness: exec bit, HTTP status, stall abort, temp cleanup, pagination | M | [lang:cli.selfupdate.] | none |
| C-SES-5.b | Signature over the sha256 sidecar (stream-safe verify) | M | [lang:cli.selfupdate.sig] [docs:README.md] | C-SES-5.a |
| C-SES-5.c | Workflows: release pipelines sign the sidecar | S | [workflows] | C-SES-9.d |
| C-SES-5.d | Workflows: GHCR image cache-bust, release filter, versioned tags | M | [workflows] [docs:DOCKER.md] [docs:SERVER.md] | none |
| C-SES-6.a | A phar renamed without `.phar` boots | M | [headless] [docs:README.md] | none |
| C-SES-6.b | Reproducible phar build + package manifest | S | none | C-SES-9.c |
| C-SES-6.c | `install.sh` + Homebrew formula generator | M | [docs:README.md] | C-SES-6.a, C-SES-5.b |
| C-SES-6.d | Workflows: release ships slim gz phar, manifest, formula, installer | S | [workflows] | C-SES-6.b, C-SES-6.c, C-SES-5.c |
| C-SES-6.e | Update-available notice | M | [bootstrap:chat] [lang:startup.update] [docs:ENVIRONMENT.md] [docs:README.md] [hot:Support/StartupSummary.php] | C-SES-5.a |
| C-SES-7.a | Session importer core (CC / Codex / OpenCode / candy-code) | M | none | C-SES-2.a |
| C-SES-7.b | `candy-code session import` verb | S | [headless] [lang:cli.session.import] [docs:README.md] | C-SES-7.a |
| C-SES-7.c | Incognito launches (`--incognito`) | M | [headless] [bootstrap:chat,openSession] [chat:ctor,submit-persist] [renderer:renderStatusBar] [hot:Chat/Handlers/HandlesTurnCompletion.php] [lang:chat.incognito] [docs:README.md] | none |
| C-SES-7.d | Resume-cost guard | M | [chat:submit,resume/switch arms] [lang:chat.resume_cost] [docs:SETTINGS.md] [hot:Config/Settings/Definitions/InterfaceSettings.php] | none |
| C-SES-7.e | Protocol: `session.import`, ephemeral `session.create`, archive/unarchive + list filter | M | [protocol] [hot:Host/SessionHost.php] [hot:Host/SessionHub.php] [hot:web SessionSidebar.vue] [docs:SERVER.md (regen)] | C-SES-7.a |
| C-SES-7.f | `attach` runs server slash commands and follows foreign turns | M | [chat:dispatchCommand,subscriptions] [hot:Chat/Handlers/HandlesSessionSurface.php] [docs:SERVER.md] | none |
| C-SES-7.g | `/handoff --remote <url>` teleport | S | [lang:cmd.handoff.remote] [docs:COMMANDS.md] [docs:SERVER.md] | C-SES-7.e |
| C-SES-8.a | `usage.rollup` RPC + web Usage panel with retention | M | [protocol] [hot:web SidePanels.vue] [docs:SERVER.md (regen)] | C-SES-9.a |
| C-SES-8.b | Ledger attribution by source + `/usage` advice | M | [chat:accountUsage + its 4 settle arms] [hot:Host/SessionHost.php] [hot:EnhancedSessionStore.php] [hot:UsageHostCommand.php] [lang:cmd.usage.] [docs:COMMANDS.md] | C-SES-9.a |
| C-SES-8.c | Insights report core | M | [lang:cmd.insights.] | C-SES-9.a |
| C-SES-8.d | `/insights` command wiring | S | [chat:handleInsightsCommand] [commands:READ_ONLY_COMMANDS] [docs:README.md] [docs:COMMANDS.md] | C-SES-8.c |
| C-SES-8.e | `candy-code usage` CLI verb | S | [headless] [lang:cli.usage,cli.completion.verb.usage] [docs:README.md] | C-SES-9.a |
| C-SES-8.f | JSONL transcript writer + `jsonl` export format | M | [lang:cmd.export.] [docs:COMMANDS.md] | none |
| C-SES-8.g | JSONL wiring: setting, store mirror, `transcript_path` for hooks | M | [bootstrap:sessionStore] [hot:Hooks/ScriptHook.php] [hot:Config/Settings/Definitions/InterfaceSettings.php] [lang:settings.transcriptJsonl] [docs:HOOKS.md] [docs:SETTINGS.md] | C-SES-8.f |

**Totals:** 40 steps, all `phase: normal`. 15 are S, 25 are M, and none is L.

---

### C-SES-1.a — Headless `-p` spend reaches the ledger; `session list` shows spend
- item: C-SES-1 (sources: SES-1-P1, p2-miss: headless `-p` spend, SES-1-P4 `session list` half)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `NonInteractive::run()` collects each step's `Usage` into `$sessionUsages` (`src/Cli/NonInteractive.php:405-409`), but only for the JSON envelope (`:609`). `appendUsageRecord` has exactly two callers, `src/Chat.php:19002` and `src/Host/SessionHost.php:2267`, so a `-p` run persists its session row (the AUT-2 block, `:653-660`) without its money. `/usage`, `/budget` after `--resume`, and `/status`'s ledger row then all under-report.
  - **Fix.** In the AUT-2 persist block, after `$mint()`, send each buffered usage through `SpendLedger::new()->account(new TokenTracker(), $usage, $sink, $backend->model())`. The sink calls `EnhancedSessionStore::appendUsageRecord($opened['id'], …)`.
    - Gate it on `$ownsSession && $opened !== null && $sessionStore instanceof EnhancedSessionStore`.
    - Put it inside the same `try`, so a store failure costs one stderr line and leaves the exit code unchanged (the "store must not cost the answer" law).
  - **`session list`.** Its text rows gain the cached spend, printed with `SpendLedger::boundUsd($row->costUnpriced, $row->costUsd)` (`≥` when unpriced, `—` when null). Its `--json` rows gain `spentUsd` and `spendUnpriced`.
  - **What the user sees:** `-p` spend in `/usage`, and every row of `candy-code session list` showing its cost.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (run(): AUT-2 persist block), `candy-code/src/Cli/Subcommands.php` (sessionList(), sessionLabel()), `candy-code/lang/en.php` (`cli.session.list.spend*`), `candy-code/README.md` (Non-interactive mode: the session-persistence paragraph; Subcommands: the `session list` sample)
- tests: create `candy-code/tests/Cli/NonInteractiveUsageLedgerTest.php`. It pins:
  - a priced echo-provider run → `usageTotals(id)` is non-null;
  - a `--resume` run appends to the same id;
  - unpriced usage sets `unpriced`;
  - a throwing store still exits 0, with exactly one stderr line.
  
  Modify `candy-code/tests/Cli/SessionSubcommandsTest.php`: the spend column is exact when priced and `≥` when unpriced; the JSON keys are present.
- docs/guards: the README.md paragraphs only. No new verb, flag or env var, so no roster guard moves.
- serialized: [headless] [lang:cli.session.list.spend] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: The lazy mint must run before the first append. Buffering the appends to the persist block guarantees that; never append inside the step observer.

### C-SES-1.b — Background announcement obeys the `≥` law
- item: C-SES-1 (sources: SES-1-P3)
- kind: fix · MINOR · effort S
- approach:
  - **Root cause.** `BackgroundSession::announcement()` prints `sprintf('$%.4f', $this->costUsd)` only when the cost is above zero (`src/Background/BackgroundSession.php:327-329`). It ignores `spendUnpriced` (`:77`, `:118-123`): an unpriced background session prints an exact figure, or hides its `≥$0`. The web BackgroundPanel already prints `≥`.
  - **Fix.** Print `SpendLedger::boundUsd($this->spendUnpriced, $this->costUsd)` when `costUsd > 0 || spendUnpriced`.
- files.create: none
- files.modify: `candy-code/src/Background/BackgroundSession.php` (announcement())
- tests: modify `candy-code/tests/Chat/BackgroundResultAnnouncedTest.php`. Pin both polarities, plus unpriced-at-zero printing `≥$0`.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: An announcement golden may pin the old `$0.0123` text. Re-pin it in-step.

### C-SES-2.a — FTS index: one-transaction rebuild + persisted build marker
- item: C-SES-2 (sources: SES-2-P2)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `SessionSearchIndex::$built` starts `false` in every process (`src/Session/SessionSearchIndex.php:68-70`), so `needsRebuild()` (`:102-105`) is true on each launch's first body search. `EnhancedSessionStore::rebuildSearchIndex()` (`src/Session/EnhancedSessionStore.php:2083-2103`) then runs `clearAll()` plus a per-session `syncMessages()` with no enclosing transaction: one WAL commit per row, run inline from `listSessionsFiltered()` (`:962`) on `update()`. The review measured 128 s at 300 sessions. Any throw inside `search()` also sets `dirty`, which forces another full rebuild.
  - **Fix, three parts:**
    1. Run the sweep inside `sessionStore->immediateTransaction()`, chunked at about 500 sessions per transaction so a concurrent writer can interleave.
    2. Persist the build state in a `session_search_meta(version, built_at, dirty)` row written in the same transaction. `needsRebuild()` becomes "no marker, a version mismatch, or a recorded dirty flag", and a failed sync records `dirty = 1` durably. The incremental `syncSearchIndex()` already keeps a built index current across processes.
    3. In `search()`, treat `SQLITE_BUSY`/`SQLITE_LOCKED` as "cannot answer now": return `null` (the LIKE degrade) and do not mark the index dirty.
  - **What the user sees:** the first `/sessions <q>` stops freezing on every launch. Only the first build per database costs anything, and that build runs in one transaction.
- files.create: none
- files.modify: `candy-code/src/Session/SessionSearchIndex.php` (createSchema, needsRebuild, markBuilt, syncMessages catch, search catch), `candy-code/src/Session/EnhancedSessionStore.php` (rebuildSearchIndex())
- tests: modify `candy-code/tests/Session/SessionSearchIndexTest.php`. It pins:
  - a second store instance over a populated database does zero rebuild (row-identity snapshot);
  - the sweep runs inside a transaction (`inTransaction()` observed from a sync seam);
  - a BUSY throw does not set dirty;
  - a version bump forces one rebuild.
- docs/guards: none
- serialized: none (hot file: `EnhancedSessionStore.php`, see Notes)
- deps: none
- phase: normal
- ruling: none
- risks: Old databases have no marker and rebuild once, after which they are trusted. The marker must also be cleared by `clearAll()` and `collectGarbage()` failure paths.

### C-SES-2.b — Picker shows body hits + snippets + per-row spend; `/` filter reaches FTS
- item: C-SES-2 (+ C-SES-1's SES-1-P4 picker half) (sources: SES-2-P1, p2-miss: picker body snippet (`SessionRow::withBodySnippet` dormant), SES-1-P4, p2-miss: per-session spend on picker)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `buildSessionPicker()` (`src/Chat.php:14668-14689`) fetches FTS-ranked rows through `sessionPickerRows()` (`:14714-14733`), then calls `withQuery()`. `SessionPicker::rankedLayout()` (`src/Tui/SessionPicker.php:349-374`) keeps only rows whose `haystack()` (`:376-385`: title, summary, agent, id, branch) passes a `requireFullQuery` Smith-Waterman match, so a body-only hit is dropped. The Ctrl+R `/` filter reads the store once, when it opens (`Chat.php:14963-14969`: "typing a query never touches the store").
  - **Fix, in the rows.** `sanitizeSessionRows()` carries two new keys:
    - `bodySnippet`, from `SessionRow::$bodySnippet` (`:53`, `:106`), sanitized like the other fields;
    - `spend`, as `SpendLedger::boundUsd(costUnpriced, costUsd)` when `costUsd !== null`. This wires the write-only cache (SES-1-P4).
  - **Fix, in the picker.**
    - `rankedLayout()` admits store-ranked rows (a non-null `bodySnippet`) after the fuzzy hits, in bm25 order.
    - `renderRow()` paints the snippet as a faint second line under body-matched rows, and the spend in the meta column.
    - In filter mode, the picker emits `SessionListAction::Filter` on every query edit.
  - **Fix, in Chat.** Chat debounces those edits: a 300 ms Cmd tick returns `SessionPickerSearchMsg(query)`. When it arrives, Chat re-reads `sessionPickerRows(SEARCH_LIMIT, archived, query)` only if the picker's query still matches.
  - **What the user sees:**
    - `/sessions anchor` lists a session that matched only in its body, with the snippet;
    - typing in Ctrl+R reaches the full-text index;
    - each row shows its spend.
- files.create: `candy-code/src/App/SessionPickerSearchMsg.php`
- files.modify: `candy-code/src/Chat.php` (buildSessionPicker, sanitizeSessionRows, runSessionListAction: Filter arm), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the SessionPickerSearchMsg arm), `candy-code/src/Tui/SessionPicker.php` (rankedLayout, haystack, renderRow, filter-key emission)
- tests: create `candy-code/tests/Chat/SessionPickerDebouncedSearchTest.php`: a stale query is ignored and a fresh one re-reads the store. Modify:
  - `candy-code/tests/Chat/SessionCommandTest.php`: the VISIBLE rows of `/sessions anchor` include the body-only session. This replaces the reflection pin on the private page read, which asserts the wrong layer.
  - `candy-code/tests/Tui/SessionPickerFilterTest.php`: store-ranked rows are admitted; the LIKE degrade keeps its polarity.
  - `candy-code/tests/Tui/SessionPickerSlashFilterTest.php`: query edits emit `Filter`.
  - `candy-code/tests/Tui/SessionPickerGoldenTest.php`: re-pin the goldens with snippet and spend.
- docs/guards: none (no new key binding, since `/` already exists)
- serialized: [chat:buildSessionPicker,sanitizeSessionRows,runSessionListAction] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [hot:candy-code/src/Tui/SessionPicker.php]
- deps: C-SES-2.a
- phase: normal
- ruling: none
- risks: P-SESSION-TABS also touches the session-switch and picker surfaces. Do not run them in the same wave.

### C-SES-2.c — Protocol: summaries carry snippets + honest spend; web body search + live `≥`
- item: C-SES-2 (+ C-SES-1's web half) (sources: GAP-SES-11, SES-1-P2, SES-1-P4, p2-miss: per-session spend on sidebar)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause (web search).** `SessionMethods::list()` filters `query` by `str_contains` over name, id and `last_preview` (`src/Protocol/Methods/SessionMethods.php:82-113`). It never reaches FTS.
  - **Root cause (spend).** The `D::USAGE` wire schema omits `unpricedModel` (`src/Protocol/Schema/Definitions.php:185-193`), although `UsageUpdated` sends it. The reducer sets `unpriced` only from the snapshot (`candy-code-web/src-web/stores/reducer.ts:271`), so a live unpriced turn reads `$0.00`. `SessionSummary.spentUsd` exists only for open hosts (`src/Protocol/ServerContext.php:337`).
  - **Fix, server.**
    - `SessionHub::list()` gains `?string $query`, which runs `EnhancedSessionStore::listSessionsFiltered(SessionQuery::new()->withBodySearch($q))`: FTS-ranked, with the LIKE degrade.
    - `SessionMethods::list()` delegates to it.
    - `ServerContext::summary()` adds three fields: `snippet`; `spentUsd`, falling back to the row's `cost_usd` for closed sessions; and `spendUnpriced`, from the row's `cost_unpriced`. The cache is updated in the ledger's own transaction.
    - `D::USAGE` gains `unpricedModel` (nullable string), and `SESSION_SUMMARY` gains `snippet` and `spendUnpriced`. Then regenerate the chain.
  - **Fix, web.**
    - The reducer's `usage.updated` arm sets `state.unpriced = true` when `turnUsage.unpricedModel` is non-empty.
    - `sessions.ts` gains a `search(query)` that calls `session.list {query}`, debounced 250 ms.
    - `SessionSidebar.vue` routes its filter to the server and paints the snippet plus `spendLabel(s.spentUsd, s.spendUnpriced)` per row.
- files.create: none
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (list()), `candy-code/src/Host/SessionHub.php` (list()), `candy-code/src/Protocol/ServerContext.php` (summary()), `candy-code/src/Protocol/Schema/Definitions.php` (SESSION_SUMMARY, USAGE), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/reducer.ts` (usage.updated arm), `candy-code-web/src-web/stores/sessions.ts` (search), `candy-code-web/src-web/components/SessionSidebar.vue` (filter → server search; row snippet + spend)
- tests: create `candy-code/tests/Server/SessionListBodySearchTest.php`: `session.list {query}` returns a body-only match with its snippet, and a closed session carries the cached `spentUsd`/`spendUnpriced`. Modify:
  - `candy-code/tests/Host/SessionHubTest.php`: the query path;
  - `candy-code-web/src-web/stores/__tests__/reducer.spec.ts`: `unpricedModel` flips `unpriced`;
  - `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`: a live turn reads `≥$0.00`;
  - `candy-code-web/src-web/components/__tests__/sidebar.spec.ts`: server search, snippet line, spend.
- docs/guards: the generation chain:
  1. `php candy-code/scripts/gen-protocol-schema.php` regenerates the schema JSON and `docs/SERVER.md`;
  2. `npm run gen:protocol` regenerates `generated.ts`.
  
  Guards moved: `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`.
- serialized: [protocol] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md (regen only)]
- deps: C-SES-2.a
- phase: normal
- ruling: none
- risks: `SessionHub::list()` pages arrays while the FTS path returns `SessionRow` objects. Convert through `SessionRow::toArray()` and keep cursor paging stable.

### C-SES-3.a — Shares die with their session; token redacted from the serve log
- item: C-SES-3 (sources: SES-3-P1, SES-3-P2)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `shares` has no FK to `sessions` (`src/Share/ShareStore.php:54-62`), and no delete or prune path touches it. `SessionStore::deleteSessionTrees()` (`src/Session/SessionStore.php:1005-1052`) is the single choke that both `deleteSession()` (`:992`) and `pruneSessions()` (`:1267ff`) use. A deleted or pruned session therefore keeps `/s/<token>` live for up to 365 days, and nothing can list the token.
  - **Root cause (log).** Separately, `Server.php:226-229` logs `getUri()->getPath()`, which includes the 32-hex capability token.
  - **Fix.**
    - Inside `deleteSessionTrees()`'s existing transaction, run a chunked `UPDATE shares SET revoked_at = ? WHERE revoked_at IS NULL AND session_id IN (…)`. Guard it with `hasTable('shares')`, since `shares` sits in the same `session.db`. The SQL lives once, as a `ShareStore` public constant or static helper.
    - Add `ShareStore::liveAll()`: every live share across sessions, LEFT JOIN `sessions` for the name, with orphans flagged.
    - The access-log closure redacts `/s/<token>` to `/s/<first 4 hex>…`, keyed on `ShareStore::URL_PREFIX`.
- files.create: none
- files.modify: `candy-code/src/Session/SessionStore.php` (deleteSessionTrees()), `candy-code/src/Share/ShareStore.php` (liveAll(), the revoke-for-sessions SQL), `candy-code/src/Server/Server.php` (the access-log middleware closure)
- tests: create `candy-code/tests/Session/SessionDeleteRevokesSharesTest.php`: delete and prune both make `lookup()` return null; a store without a `shares` table is tolerated. Modify:
  - `candy-code/tests/Share/ShareStoreTest.php`: `liveAll()` spans sessions and flags orphans;
  - `candy-code/tests/Server/SharePageTest.php`: a share GET logs no full token.
- docs/guards: none
- serialized: none (hot file: `SessionStore.php`, see Notes)
- deps: none
- phase: normal
- ruling: none
- risks: Revoke rather than delete, so the row stays listable as revoked and auditable. `sweepInertRows()` later reaps it.

### C-SES-3.b — `/share list` across sessions; revoke hardening
- item: C-SES-3 (+ C-SES-9's SES-3-P3) (sources: SES-3-P1, SES-3-P3, p2-miss: cross-session `/share list`)
- kind: fix · MAJOR · effort S
- approach:
  - **`/share list`.** New subcommand: render `ShareStore::liveAll()` as token prefix, session name (or "deleted session"), expiry and format. `/share revoke` with no token now lists every live share, not just `liveForSession(currentSessionId)` (`src/Commands/ShareCommand.php:228`).
  - **SES-3-P3.**
    - `ShareHostCommand` wraps `ob_start()…execute()` in `try/finally { ob_get_clean() }`.
    - `ShareCommand::revoke()` catches `RuntimeException`/`PDOException` from `shareStoreFor()` (`:258-269`) and `ShareStore::revoke()`, the same way `serve()` already does.
  - **Hint.** `cmd.share.hint` gains `list`. The hint is a Lang key, so `0400-share.php` is not edited.
- files.create: none
- files.modify: `candy-code/src/Commands/ShareCommand.php` (execute(), new list(), revoke()), `candy-code/src/Host/Commands/ShareHostCommand.php` (run()), `candy-code/lang/en.php` (`cmd.share.hint`, `cmd.share.list.*`), `candy-code/docs/COMMANDS.md` (regen via `candy-code/tools/gen-command-docs.php`)
- tests: create `candy-code/tests/Host/ShareHostCommandBufferTest.php`: a read-only database gives a failure result and `ob_get_level()` is unchanged. Modify `candy-code/tests/Commands/ShareCommandTest.php`: the list includes other sessions' and deleted sessions' shares; bare revoke lists everything.
- docs/guards: `docs/COMMANDS.md` regen; `CommandDocsDriftTest`
- serialized: [lang:cmd.share.] [docs:COMMANDS.md]
- deps: C-SES-3.a
- phase: normal
- ruling: none
- risks: none notable

### C-SES-3.c — `session.shares` read RPC (for C-WEB-3's menu)
- item: C-SES-3 (sources: p2-miss: `session.shares` RPC + web share UI, SES-3-P4)
- kind: fix · MAJOR · effort S
- approach: Add `session.shares {sessionId?}`, Scope Read. It returns the live shares of one session, or of every session when `sessionId` is absent. Each row carries a token prefix, the full token (the method is token-scoped, the same as `session.share`'s answer), path, expiry, format and session name. C-WEB-3's Share/Revoke menu consumes it alongside the existing `session.share` and `session.unshare`.
- files.create: none
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (register + shares()), `candy-code/src/Protocol/Schema/MethodSchemas.php` ('session.shares'), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` (scope pin)
- tests: modify `candy-code/tests/Protocol/ShareMethodsTest.php`: per-session and all-sessions listings, and a revoked share leaves the list.
- docs/guards: the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- serialized: [protocol] [docs:SERVER.md (regen only)]
- deps: C-SES-3.a
- phase: normal
- ruling: none
- risks: This adds a web-uncalled method until C-WEB-3 lands. Record it in C-WEB-3's dormancy roster.

### C-SES-3.d — Live read-only share links
- item: C-SES-3 (sources: CLAW-new#8)
- kind: feature · effort M
- approach:
  - **What it adds.** `/share serve --live [days]` publishes a link that re-renders the session's transcript at request time, so a reader sees existing and future messages. It keeps the same token, expiry and revoke semantics as snapshot links.
  - **Storage.** `shares` gains `live INTEGER NOT NULL DEFAULT 0` through an idempotent ALTER in `ShareStore`. `ShareRecord` gains `live`.
  - **Serving.**
    - `SharePageController` takes an optional `Closure(string $sessionId): ?string` renderer. `Serve.php` builds it over `Bootstrap::sessionStore()`, `TranscriptStore` and `ShareSession` HTML.
    - Live rows render through that closure. Snapshot rows keep the stored HTML.
    - A live link to a deleted session is 404, which C-SES-3.a's revoke already guarantees.
  - **What the user sees:** a "live" badge in `/share list`.
- files.create: none
- files.modify: `candy-code/src/Share/ShareStore.php` (schema migration, publish($live)), `candy-code/src/Share/ShareRecord.php` (live), `candy-code/src/Server/Http/SharePageController.php` (__construct, __invoke), `candy-code/src/Cli/Serve.php` (controller construction near `:406`), `candy-code/src/Commands/ShareCommand.php` (serve(): `--live`), `candy-code/lang/en.php` (`cmd.share.live*`, `cmd.share.hint`), `candy-code/docs/SERVER.md` (share-page section), `candy-code/docs/COMMANDS.md` (regen)
- tests: modify:
  - `candy-code/tests/Server/SharePageTest.php`: a live link shows a message appended after publish; revoke and expiry still 404; a snapshot link is unchanged;
  - `candy-code/tests/Share/ShareStoreTest.php`: the `live` column migrates on an old table;
  - `candy-code/tests/Commands/ShareCommandTest.php`: `--live` parsing.
- docs/guards: `CommandDocsDriftTest`. SERVER.md prose only; the method roster is unchanged.
- serialized: [lang:cmd.share.live] [docs:SERVER.md] [docs:COMMANDS.md]
- deps: C-SES-3.b
- phase: normal
- ruling: Ship anonymous LIVE read-only links, where anyone holding the token sees future messages until revoke or expiry?
- risks: Rendering per request costs a transcript load on the serve loop. Bound it with the existing `ShareSession` row cap and send `Cache-Control: no-store`.

### C-SES-9.a — Ledger: no resurrection, `ts` index, one-snapshot rollups
- item: C-SES-9 (sources: SES-1-P5, SES-1-P6, OBS-3-P2)
- kind: fix · MINOR/NIT · effort S
- approach:
  - **SES-1-P5.** `appendUsageRecord()` recreates a missing row as `candy-code/unknown` (`src/Session/EnhancedSessionStore.php:2436-2438`), so a late settle resurrects a deleted session.
    - The recreate exists on purpose, for the lazy mint, so do not simply skip missing rows.
    - Instead, `deleteSessionTrees()` records a tombstone in `session_tombstones(id PRIMARY KEY, deleted_at)`, reaping entries older than 30 days on insert. `appendUsageRecord()` returns false, without recreating, for a tombstoned id only.
  - **SES-1-P6.** Add `CREATE INDEX IF NOT EXISTS idx_usage_records_ts ON usage_records(ts)` and correct the "nothing scans by anything else" docblock (`:755-760`).
  - **OBS-3-P2.** Add `EnhancedSessionStore::usageRollups(?string $sessionId, ?int $days): array{days, models}`, which runs both reads in one `BEGIN DEFERRED` snapshot.
  - **Shared read.** `UsageHostCommand` uses `usageRollups()` and exposes a public static `UsageHostCommand::rollupFacts(EnhancedSessionStore, ?string $sessionId, ?int $windowDays, bool $scopeAll): array`. The RPC (C-SES-8.a), the CLI verb (C-SES-8.e) and insights (C-SES-8.c) share this one read.
- files.create: none
- files.modify: `candy-code/src/Session/SessionStore.php` (deleteSessionTrees(): tombstone insert), `candy-code/src/Session/EnhancedSessionStore.php` (initEnhancedSchema: index + tombstone table; appendUsageRecord(); new usageRollups()), `candy-code/src/Host/Commands/UsageHostCommand.php` (facts → rollupFacts())
- tests: modify `candy-code/tests/Session/UsageRecordsTest.php`:
  - delete then append → no row resurrected;
  - a never-minted id still gets its row (the lazy-mint polarity);
  - `EXPLAIN QUERY PLAN` for the `/usage all` window uses `idx_usage_records_ts`;
  - both folds see one snapshot when a writer interleaves.
- docs/guards: none
- serialized: none (hot files: `EnhancedSessionStore.php`, `SessionStore.php`, `UsageHostCommand.php`)
- deps: C-SES-3.a (the same `deleteSessionTrees()` region)
- phase: normal
- ruling: none
- risks: The tombstone table must be created by both `SessionStore` (the delete side) and `EnhancedSessionStore` (the read side), tolerant of absence.

### C-SES-9.b — Serve `/status` prints live spend and cap
- item: C-SES-9 (sources: OBS-2-P1)
- kind: fix · MINOR · effort S
- approach:
  - **Root cause.** Serve constructs `StatusHostCommand` with no arguments, through `withHostCommand(StatusHostCommand::class)` in `builtin-commands/1340-status.php`. It therefore prints `cmd.status.spend.untracked` (`src/Host/Commands/StatusHostCommand.php:73-75`), although `SessionHost` holds a reseeded tracker (`src/Host/SessionHost.php:175`, `:236`) and the session cap.
  - **Fix.**
    - `CommandContext::new()` gains optional `?TokenTracker $spendTracker` and `?float $spendCap`, both defaulting to null.
    - `SessionHost::commandContext()` (`:1621`) fills them.
    - `StatusHostCommand` prefers its constructor arguments, then the context.
- files.create: none
- files.modify: `candy-code/src/Host/Commands/CommandContext.php` (new(), properties), `candy-code/src/Host/SessionHost.php` (commandContext()), `candy-code/src/Host/Commands/StatusHostCommand.php` (facts())
- tests: modify `candy-code/tests/Host/WebTwoHostCommandsTest.php`: serve `/status` prints the spend line with `≥` when unpriced, and the cap.
- docs/guards: none
- serialized: none (hot files: `SessionHost.php`, `CommandContext.php`)
- deps: none
- phase: normal
- ruling: none
- risks: `CommandContext` is built in many places. Named, defaulted parameters keep every other call site byte-identical.

### C-SES-9.c — Phar installs ship bundled presets; build-phar docblocks truthful
- item: C-SES-9 (sources: DST-1-P2, DST-1-P3)
- kind: dormant-wire · MINOR · effort S
- approach:
  - **Root cause.** `AgentPresetRegistry::bundledPresetsDirectory()` calls `realpath()` on a `phar://` path, which returns false, so the method answers null (`src/Agents/AgentPresetRegistry.php:390-395`). `isBundledPresetPath()` compares realpaths the same way (`:403-412`). The phar packs `.candy-code/agents` (`tools/build-phar.php:172`), yet ships no presets.
  - **Fix.** When `str_starts_with(__DIR__, 'phar://')`, return the un-realpath'd `phar://…/.candy-code/agents` if `is_dir()` holds, and compare by normalized prefix.
  - **Docblocks.** In `tools/build-phar.php`, delete the "KNOWN PHAR DEGRADATION" disclosure (`:89-97`) and correct the weight docblock (`:41-44`) to the measured ~118 MiB, pointing at `--gz`.
- files.create: none
- files.modify: `candy-code/src/Agents/AgentPresetRegistry.php` (bundledPresetsDirectory(), isBundledPresetPath()), `candy-code/tools/build-phar.php` (docblocks only)
- tests: modify `candy-code/tests/Agents/AgentPresetRegistryTest.php`: an arm over a scratch `phar://` archive containing `.candy-code/agents/*.md`, built the `PharCompatTest` `writePremisePhar` way in a `-d phar.readonly=0` child, lists the bundled presets.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: The phar-building test arm needs `phar.readonly=0` in a child PHP; skip it when `PHP_BINARY` cannot be spawned.

### C-SES-9.d — Release workflows pass tags/inputs via `env:`
- item: C-SES-9 (sources: DST-1-P4)
- kind: fix · NIT · effort S
- approach:
  - **Root cause.** `${{ github.event.inputs.tag }}` and `${{ steps.*.outputs.* }}` are interpolated straight into `run:` scripts:
    - `release-phar.yml:50-51`, `:62`, `:88`, `:91`, `:206`;
    - `release-app-phar.yml:64-65`, `:97`, `:114`, `:122`, `:159`, `:182`, `:200-201`, `:221`, `:235-236`;
    - `release-candy-top-phar.yml:60-61`, `:72`, `:213`.
    
    A ref name containing `'` breaks out of the script.
  - **Fix.** Move each value into the step's `env:` (`TAG`, `APP`, `EVENT`) and reference `"$TAG"` in the script.
  - **Guard.** A root `tools/tests` guard fails on `${{ github.event.inputs.` or `${{ steps.` inside any `run:` body of the three files.
- files.create: `tools/tests/WorkflowRunInterpolationTest.php`
- files.modify: `.github/workflows/release-phar.yml` (tag, checkout, build, release steps), `.github/workflows/release-app-phar.yml` (scope, checkout, build, sign, checksum, release steps), `.github/workflows/release-candy-top-phar.yml` (tag, checkout, release steps)
- tests: create `tools/tests/WorkflowRunInterpolationTest.php`. It runs in the `tools-guards` job, not in the candy-code durations TSV.
- docs/guards: none
- serialized: [workflows]
- deps: none
- phase: normal
- ruling: none
- risks: A behaviour change shows only on a real tag. Check with `actionlint` if available, otherwise by review.

### C-SES-4.a — Consume-time span stamps, one OTLP body per batch, PID-guarded flush
- item: C-SES-4 (sources: OBS-1-P1, OBS-1-P4, OBS-1-P2 (one-body half))
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause (timestamps).** `BatchEventSink::consume()` buffers raw events (`src/Observability/BatchEventSink.php:62-71`), and `OtlpEncoder::encode()` stamps `wallClockNanos()` at ENCODE time (`src/Observability/OtlpEncoder.php:78-80`), which happens at batch fill. Every batched span is therefore about 0 ms long and stamped at the wrong instant.
  - **Fix (timestamps).** `consume()` stores `[$event, hrtime-anchored unix nanos]` pairs. `OtelSink::encodeAll()` takes those pairs and passes each nano value to `encode($event, $nanos)`.
  - **OBS-1-P2, the one-body half.** Each closed span is currently its own POST body (`OtlpEncoder.php:102-106`). Add an `OtlpEncoder::encodeMany()` that returns the closed spans, so one `exportBody()` per batch carries them all, and one transport call goes out per batch.
  - **OBS-1-P4.** `BatchEventSink` captures `getmypid()` at construction. In any other pid, `flush()` is a no-op, so a forked child that leaves through `exit()` cannot re-flush the parent's copied batch. This replaces a Bootstrap-side guard and keeps the step free of serialized files.
- files.create: none
- files.modify: `candy-code/src/Observability/BatchEventSink.php` (consume, exportBuffer, flush, ctor), `candy-code/src/Observability/OtelSink.php` (encodeAll signature), `candy-code/src/Observability/OtlpEncoder.php` (encodeMany, exportBody visibility)
- tests: modify:
  - `candy-code/tests/Observability/BatchEventSinkTest.php`: with an injected clock, `StepStarted@t0` then `UsageUpdated@t0+N` export a span of duration N whatever the batch size; K closed spans produce one transport call; `flush()` from a foreign pid sends nothing (via an owner-pid seam);
  - `candy-code/tests/Observability/OtelSinkTest.php` and `candy-code/tests/Observability/OtlpEncoderTest.php`: the signatures.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: `encodeAll()` is public. Check for test doubles before changing its parameter shape.

### C-SES-4.b — Non-blocking export + wired `tick()` retry
- item: C-SES-4 (sources: OBS-1-P2, OBS-1-P3)
- kind: fix · MINOR · effort M
- approach:
  - **Root cause.** POSTs are synchronous Guzzle calls, up to `CANDY_CODE_OTEL_TIMEOUT` each (`OtelSink.php:48-58`), made inside `consume()` on the TUI `update()` path. The `tick()` retry arm (`BatchEventSink.php:77-86`) has no production caller.
  - **Async transport.** Add `AsyncOtlpTransport`, a `React\Http\Browser` on `Loop::get()` (`react/http` is already a dependency), with connect and request timeouts. This is telemetry, not an LLM call, so E646 does not apply.
    - `OtelSink::fromConfig()` builds both transports.
    - `BatchEventSink::send()` posts asynchronously; a rejection carries the body to `$unsent`.
    - `flush()` stays synchronous. It re-posts any still in-flight body through the Guzzle transport, which covers `-p` and shutdown, where the loop may not run.
  - **Retry tick.**
    - Add a `RetryingEventSink` interface (`tick()`, `hasCarryOver()`), implemented by `BatchEventSink`.
    - `Chat::subscriptions()` declares an `OTEL_RETRY_SUBSCRIPTION` tick every 15 s, only while the sink has carry-over.
    - The `TelemetryRetryTickMsg` arm calls `tick()`.
  - **Docs.** Make the `docs/ENVIRONMENT.md` "span-retry path" text true.
- files.create: `candy-code/src/Observability/RetryingEventSink.php`, `candy-code/src/Observability/AsyncOtlpTransport.php`, `candy-code/src/App/TelemetryRetryTickMsg.php`
- files.modify: `candy-code/src/Observability/BatchEventSink.php` (send, flush, implements RetryingEventSink), `candy-code/src/Observability/OtelSink.php` (fromConfig, transports), `candy-code/src/Chat.php` (subscriptions()), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the TelemetryRetryTickMsg arm), `candy-code/docs/ENVIRONMENT.md` (the `CANDY_CODE_OTEL_BATCH_SIZE` and `OTEL_EXPORTER_OTLP_ENDPOINT` rows)
- tests: create:
  - `candy-code/tests/Observability/AsyncOtlpTransportTest.php`: a loopback `react/socket` collector; `consume()` returns before the response; a refusal carries the body; flush with no running loop delivers in-flight bodies synchronously;
  - `candy-code/tests/Observability/ChatTelemetryRetryTickTest.php`: the subscription is declared only while there is carry-over; a failing-then-healthy transport delivers on the next tick.
  
  Modify `candy-code/tests/Observability/BatchEventSinkTest.php`.
- docs/guards: the `docs/ENVIRONMENT.md` rows are prose only, with no new variable (`EnvRosterDriftTest` is unaffected)
- serialized: [chat:subscriptions] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [docs:ENVIRONMENT.md]
- deps: C-SES-4.a
- phase: normal
- ruling: none
- risks: In-flight reconciliation at flush can duplicate a span whose response was lost. That is acceptable for telemetry; document it. Confirm `LoopPin` hygiene in the new test, since it arms timers on `Loop::get()`.

### C-SES-4.c — Headless `-p` exports spans
- item: C-SES-4 (sources: GAP-OBS-02 (`-p` half), p2-miss: serve + headless OTel sink threading)
- kind: gap · effort S
- approach:
  - `NonInteractive::run()` obtains `Bootstrap::observabilitySink()`, a static call, so `Bootstrap.php` is not edited. It is a null sink while dark.
  - The `$onEvent` observer (`src/Cli/NonInteractive.php:576-579`) also calls `$sink->consume($event)`. The shutdown flush is already registered by `observabilitySink()`.
  - Flip the "`-p` exports nothing" half of the ENVIRONMENT.md `OTEL_EXPORTER_OTLP_ENDPOINT` row, and the `ObservabilityWiringTest` pin.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (run(): the `$onEvent` observer), `candy-code/docs/ENVIRONMENT.md` (the OTLP endpoint row), `candy-code/tests/Observability/ObservabilityWiringTest.php`
- tests: create `candy-code/tests/Cli/NonInteractiveOtelExportTest.php`: with a lit config and a stub transport, a `-p` run exports one `chat.turn` span with the turn's tokens; dark costs no transport call.
- docs/guards: the ENVIRONMENT.md row and `ObservabilityWiringTest` flip in-step
- serialized: [headless] [docs:ENVIRONMENT.md]
- deps: C-SES-4.a
- phase: normal
- ruling: none
- risks: The `Bootstrap.php:1250-1256` comment ("the ONLY sink wiring") stays false until C-SES-4.d corrects it.

### C-SES-4.d — Serve exports spans (per-session encoders)
- item: C-SES-4 (sources: GAP-OBS-02 (serve half), p2-miss: serve OTel sink threading)
- kind: gap · effort M
- approach:
  - **The trap.** One OTLP encoder keys open spans by `$event->step` (`OtlpEncoder.php:84-96`). Serve hosts several sessions per process, so their step numbers would collide on a shared sink.
  - **Factory.** Add an `EventSinkFactory` that builds one `BatchEventSink`, with its own `OtlpEncoder` and a `session.id` resource attribute, per session.
    - The sinks share both transports.
    - The factory registers ONE PID-guarded shutdown flush covering every live sink.
  - **Wiring.**
    - `Bootstrap::workspace()` registers the factory as a workspace service (`->withService(EventSinkFactory::class, …)`, the same locator `ScheduleRegistry` uses) only when `OtelConfig` is lit.
    - `SessionHost::fold()` (`src/Host/SessionHost.php:2002`) calls `consume()` on its sink, fetched lazily; `close()` flushes it.
    - Correct the stale `Bootstrap::chat()` comment (`:1250-1256`).
    - Flip the serve half of the ENVIRONMENT.md row and of `ObservabilityWiringTest`.
- files.create: `candy-code/src/Observability/EventSinkFactory.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (workspace(): service registration; chat(): comment), `candy-code/src/Host/SessionHost.php` (fold(), close()), `candy-code/src/Observability/OtelConfig.php` (factory builder), `candy-code/docs/ENVIRONMENT.md` (the OTLP endpoint row), `candy-code/tests/Observability/ObservabilityWiringTest.php`
- tests: create `candy-code/tests/Host/SessionHostOtelFoldTest.php`: two sessions with identical step numbers produce two distinct spans carrying different `session.id`; dark registers no service.
- docs/guards: the ENVIRONMENT.md row and `ObservabilityWiringTest` flip in-step
- serialized: [bootstrap:workspace,chat(comment only)] [hot:candy-code/src/Host/SessionHost.php] [docs:ENVIRONMENT.md]
- deps: C-SES-4.b (the serve loop must never block on a POST)
- phase: normal
- ruling: Serve telemetry: should `serve` export OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set? (Owner-gated per OBS-02 and the Bootstrap comment.)
- risks: Sub-agent and background folds run on their own hosts. Check that `SessionHost::fold()` is the single choke for served turns.

### C-SES-5.a — Self-update robustness: exec bit, HTTP status, stall abort, temp cleanup, pagination
- item: C-SES-5 (sources: DST-3-P2, DST-3-P3, DST-3-P4, DST-3-P5, DST-3-P1 (stranded-temp half))
- kind: fix · MAJOR · effort M
- approach: All in `src/Cli/SelfUpdate.php`.
  - **DST-3-P2, the exec bit.** Capture `fileperms($phar) & 0777` before the swap. `chmod` the temp file after verification and before the `rename` (`:451`). `chmod` the `.old` copy after `copy()` (`:444`). Restore the mode in `rollback()` (`:730-737`).
  - **DST-3-P4, HTTP status.** One `fetchOk()` helper checks the status code (`>= 400` emits `download_failed: HTTP <code>`) and unlinks the sink temp. Use it on the sidecar (`:372`), phar (`:393`) and signature (`:674`) legs, since `http_errors=false` (`:757`) means only the release-list call checks status today.
  - **DST-3-P3, stalls.** `httpClient()` (`:747-757`) adds `CURLOPT_LOW_SPEED_LIMIT = 1024` and `CURLOPT_LOW_SPEED_TIME = 60`, guarded by `\defined()`, and keeps no total timeout. Fix the docblock's false claim that `read_timeout` kills a stall (`:110-113`).
  - **DST-3-P1, the stranded temp.** A shutdown function unlinks `$temp`, so a fatal leaves nothing behind.
  - **DST-3-P5.**
    - Follow `Link: rel=next` for up to 5 pages (`RELEASES_URL`, `:193`).
    - `composerRefusal()` (`:725`) branches on `InstalledVersions::getRootPackage()['name']`: when candy-code is itself the root package, the honest answer is `git pull && composer install`.
- files.create: none
- files.modify: `candy-code/src/Cli/SelfUpdate.php` (run(), rollback(), httpClient(), new fetchOk(), release selection paging, composerRefusal(), class docblock), `candy-code/lang/en.php` (`cli.selfupdate.download_failed*`, the composer-refusal variants)
- tests: modify `candy-code/tests/Cli/SelfUpdateTest.php`:
  - swap and rollback both preserve `0755` and `0700` (skipped on Windows);
  - a 404 on each of the three legs gives `download_failed` naming `HTTP 404`, with no temp left and the live phar byte-identical;
  - the client config carries the low-speed pair (via reflection);
  - `testSelectionFollowsPagination`;
  - the refusal wording for each install shape.
- docs/guards: none
- serialized: [lang:cli.selfupdate.]
- deps: none
- phase: normal
- ruling: none
- risks: `CURLOPT_*` constants are absent without ext-curl. Guard with `\defined()`; the StreamHandler path keeps `read_timeout`.

### C-SES-5.b — Signature over the sha256 sidecar (stream-safe verify)
- item: C-SES-5 (sources: DST-3-P1, DST-3-P5 (minisig naming/format))
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `verifySignature()` reads the whole ~119 MiB phar with `file_get_contents($temp)` (`SelfUpdate.php:678`) to call `sodium_crypto_sign_verify_detached` (`:714`). That is fatal under the default 128M `memory_limit`.
  - **Fix.** Prefer a NEW release asset, `candy-code.phar.sha256.sig`: an Ed25519 signature over the sha256 sidecar's body.
    1. Verify that signature over the sidecar text, with the same pinned key.
    2. Keep the streaming `hash_file()` gate for the phar itself.
  - **Legacy.** Releases that carry only `.minisig` (signed over the raw bytes) fall back to the old path for one release cycle, raising `memory_limit` to the file size plus 64 MiB just for that leg, with a deprecation note on stderr.
  - **Docs.** State plainly that `.minisig` and `.sha256.sig` are raw base64 sodium signatures, not minisign format.
- files.create: none
- files.modify: `candy-code/src/Cli/SelfUpdate.php` (a new `SIDECAR_SIGNATURE_ASSET` const, releaseAssetUrls(), verifySignature()), `candy-code/lang/en.php` (`cli.selfupdate.sig*`), `candy-code/README.md` (the Install verify paragraph)
- tests: modify `candy-code/tests/Cli/SelfUpdateTest.php`:
  - the sidecar-signature happy path;
  - a tampered sidecar is refused;
  - a child `PHP_BINARY -d memory_limit=32M` swapping a 48 MB staged phar exits 0 on the new-asset path;
  - the legacy `.minisig` path still verifies.
- docs/guards: README.md only
- serialized: [lang:cli.selfupdate.sig] [docs:README.md]
- deps: C-SES-5.a
- phase: normal
- ruling: none
- risks: `candy-top/src/Cli/SelfUpdate.php` verifies raw-byte signatures too. C-SES-5.c keeps emitting `.minisig`, so candy-top is unaffected (see Notes).

### C-SES-5.c — Workflows: release pipelines sign the sidecar
- item: C-SES-5 (sources: DST-3-P1 (signing half), DST-3-P5 (release-note verify line))
- kind: fix · MAJOR · effort S
- approach:
  - **The added signature.** In all three phar pipelines, move the checksum step BEFORE signing. Signing then additionally signs the `.sha256` sidecar's body into `<app>.phar.sha256.sig`, which is uploaded as an asset.
  - **Additive.** The legacy `.minisig` stays: candy-top and older candy-code releases verify it.
  - **The verify line.** Fix `phar-dev.yml:306`'s release-notes "verify" line. Today it tells users `sha256sum -c *.minisig.sha256`, which proves nothing about authenticity.
- files.create: none
- files.modify: `.github/workflows/release-phar.yml` (sign, checksum, release-asset steps), `.github/workflows/phar-dev.yml` (sign, checksum, notes, asset steps), `.github/workflows/release-app-phar.yml` (sign, checksum, asset steps)
- tests: none (workflow-only; C-SES-9.d's interpolation guard must stay green)
- docs/guards: none
- serialized: [workflows]
- deps: C-SES-9.d (the same `run:` blocks are rewritten there first)
- phase: normal
- ruling: none
- risks: In keyless state (no `SUGARCRAFT_SIGNING_KEY`), both signature steps must skip green together.

### C-SES-5.d — Workflows: GHCR image cache-bust, release filter, versioned tags
- item: C-SES-5 (sources: DST-2-P1, DST-2-P2, p2-miss: versioned Docker tags/provenance)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** The `RUN composer create-project sugarcraft/candy-code:dev-master` instruction text never changes (`candy-code/Dockerfile:63-69`), and the workflow caches with `cache-from: type=gha`. The layer is therefore served `CACHED`, and `:latest`/`:<sha>` ship a frozen snapshot. Any monorepo release also re-tags the image (`candy-code-image.yml:27-28`).
  - **Fix, Dockerfile.** Add `ARG CANDY_CODE_REF` and consume it in the create-project `RUN` (`echo "$CANDY_CODE_REF" && composer create-project …`). Write the resolved package reference (`composer show sugarcraft/candy-code --format=json`) to `/opt/candy-code/BUILD-REF`.
  - **Fix, workflow.**
    - Pass `build-args: CANDY_CODE_REF=${{ github.sha }}-${{ github.run_id }}`.
    - Run publishing builds as `workflow_run` after `sync-sugarcraft.yml` completes. The `pull_request` proof arm stays.
    - Release arm: `if: github.event_name != 'release' || startsWith(github.event.release.tag_name, 'candy-code/v')`.
    - Metadata: `type=match,pattern=candy-code/v(.*),group=1` plus an `org.opencontainers.image.revision` label.
    - The summary prints `BUILD-REF`.
  - **Docs.** Fix `docs/DOCKER.md` (freshness claims, tags) and `docs/SERVER.md` (`:1396-1398`, publish prose).
- files.create: `tools/tests/CandyCodeImageCacheBustTest.php`
- files.modify: `candy-code/Dockerfile` (header comment, the create-project RUN, ARG), `.github/workflows/candy-code-image.yml` (on:, if:, build-args, metadata tags/labels, summary), `candy-code/docs/DOCKER.md` (Registry status, freshness, tags), `candy-code/docs/SERVER.md` (the image paragraph)
- tests: create `tools/tests/CandyCodeImageCacheBustTest.php`. It runs in the `tools-guards` job and pins: the create-project RUN consumes an ARG that the workflow passes per run; the release arm is filtered to `candy-code/v*`. Live check after merge: two consecutive pushes do NOT show the composer layer as `CACHED`.
- docs/guards: DOCKER.md and SERVER.md prose
- serialized: [workflows] [docs:DOCKER.md] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: Packagist can lag the split push even after `sync-sugarcraft.yml` completes. `BUILD-REF` in the summary makes any lag visible rather than silent.

### C-SES-6.a — A phar renamed without `.phar` boots
- item: C-SES-6 (sources: DST-1-P1)
- kind: fix · MINOR · effort M
- approach:
  - **Root cause.** The stub derives the archive identity only from `Phar::running(false)`, which is `''` in stub context, or from a `.phar` suffix on `__FILE__` (`candy-code/bin/candy-code:32-37`). The usual `~/bin/candy-code` install falls through to the filesystem candidates and exits 2.
  - **Fix.** When `defined('__COMPILER_HALT_OFFSET__')` holds and no identity was found:
    1. run `Phar::mapPhar('candy-code.phar')` inside `try` (the build already sets alias `candy-code.phar`, `tools/build-phar.php:265`);
    2. use `phar://candy-code.phar/vendor/autoload.php`.
    
    Composer checkouts have no `__COMPILER_HALT_OFFSET__`, so their three candidates stay byte-identical.
  - **README.** Document that renaming and symlinking both work.
- files.create: none
- files.modify: `candy-code/bin/candy-code` (the autoload guard), `candy-code/README.md` (the Install paragraph), `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php` (restate the guard), `candy-code/tests/Integration/PharCompatTest.php` (a suffix-less arm)
- tests: modify:
  - `candy-code/tests/Integration/PharCompatTest.php`: in the heavy `CANDY_CODE_PHAR`-gated arm, copy the archive to a suffix-less name and run `--version`; add a miniature scratch-phar arm in the default suite;
  - `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php`.
- docs/guards: README Install
- serialized: [headless] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: UNVERIFIED: whether `Phar::running(false)` answers the real path under an alias mapping. `SelfUpdate` uses it to locate the file it replaces, so add a test arm asserting it.

### C-SES-6.b — Reproducible phar build + package manifest
- item: C-SES-6 (sources: p2-miss: reproducible phar builds)
- kind: gap · effort S
- approach:
  - **The gap.** `release-phar.yml:6-15` discloses lock-less `dev-master` resolution, and phar entries carry checkout-time mtimes.
  - **Fix.**
    - Before adding files (`:224` sorts them; `:270` adds them), `touch()` every packaged file to `SOURCE_DATE_EPOCH` when it is set.
    - Record `name => reference` for every package in `vendor/composer/installed.json` into the phar metadata under `packages`.
    - A new `--manifest-out=PATH` writes the same map as JSON.
- files.create: none
- files.modify: `candy-code/tools/build-phar.php` (option parse, file loop, setMetadata)
- tests: modify `candy-code/tests/Integration/PharCompatTest.php`: a metadata `packages` arm on the scratch phar; two builds with the same `SOURCE_DATE_EPOCH` hash equal on the miniature arm.
- docs/guards: none
- serialized: none
- deps: C-SES-9.c (the same file)
- phase: normal
- ruling: none
- risks: Touching files in a checkout changes mtimes for later git operations. Build from a scratch copy, or document that the build runs in CI only.

### C-SES-6.c — `install.sh` + Homebrew formula generator
- item: C-SES-6 (sources: GAP-PLAT-03, p2-miss: packaging channels)
- kind: gap · effort M
- approach:
  - **`tools/install.sh`** (POSIX sh) does six things:
    1. checks for `php` 8.3+;
    2. resolves the latest `candy-code/v*` release, or `--channel dev`;
    3. downloads the `.phar`, `.sha256` and `.sha256.sig` (falling back to `.minisig`);
    4. verifies the sha256 with `sha256sum` or `shasum -a 256`;
    5. verifies the Ed25519 signature with `php -r` sodium and a PINNED key that must equal `SelfUpdate::PUBLIC_KEY_BASE64`;
    6. installs to `--dir` (default `~/.local/bin`) as `candy-code`, mode 0755. This relies on C-SES-6.a.
  - **No env vars.** Options are flags, never `CANDY_CODE_*` env vars. `EnvRosterDriftTest` requires every env var a doc names to be read in `src/`, which an installer variable never is.
  - **Homebrew.** `tools/gen-homebrew-formula.php` renders `packaging/homebrew/candy-code.rb.tmpl` (url, sha256, version, `depends_on "php"`, the phar installed as `bin/candy-code`).
  - **README.** The one-liner plus the brew lines.
- files.create: `candy-code/tools/install.sh`, `candy-code/tools/gen-homebrew-formula.php`, `candy-code/packaging/homebrew/candy-code.rb.tmpl`, `candy-code/tests/Cli/InstallScriptTest.php`, `candy-code/tests/Cli/HomebrewFormulaTest.php`
- files.modify: `candy-code/README.md` (Install section)
- tests: create:
  - `candy-code/tests/Cli/InstallScriptTest.php`: `sh -n`; the pinned key equals `SelfUpdate::PUBLIC_KEY_BASE64`; an end-to-end run against `file://` fixtures (via a `--base-url` flag) installs, and refuses a tampered sidecar;
  - `candy-code/tests/Cli/HomebrewFormulaTest.php`: the rendered formula carries the version and sha.
- docs/guards: README Install; `EnvRosterDriftTest` is untouched by construction (no env vars)
- serialized: [docs:README.md]
- deps: C-SES-6.a, C-SES-5.b
- phase: normal
- ruling: none
- risks: The test spawns `sh`, `curl` and `php`. Skip when `curl` is absent. Tests are not in the `src/` child-lifetime census.

### C-SES-6.d — Workflows: release ships slim gz phar, manifest, formula, installer
- item: C-SES-6 (sources: p2-miss: slim phar, p2-miss: reproducible phar builds, GAP-PLAT-03)
- kind: gap · effort S
- approach: `release-phar.yml` gains four outputs:
  - a second build with `--gz`, shipped as `candy-code-gz.phar` with its own `.sha256` and signatures; the plain asset stays the self-update target;
  - `--manifest-out` uploaded as `candy-code.phar.packages.json`;
  - the rendered `candy-code.rb` formula and `install.sh` attached as assets;
  - an optional tap push, which needs a secret and the ruling.
- files.create: none
- files.modify: `.github/workflows/release-phar.yml` (build, sign, checksum, assets steps)
- tests: none (workflow-only; C-SES-9.d's guard must stay green)
- docs/guards: none
- serialized: [workflows]
- deps: C-SES-6.b, C-SES-6.c, C-SES-5.c
- phase: normal
- ruling: Create a Homebrew tap repo plus a push secret? (Without it the step ships the formula only as a release asset, and the push leg stays off.)
- risks: The gz phar needs ext-zlib at runtime. Say so in the release notes.

### C-SES-6.e — Update-available notice
- item: C-SES-6 (sources: GAP-PLAT-05)
- kind: gap · effort M
- approach:
  - **`UpdateCheck`.** It runs on phar installs only (`Phar::running` identity), and does two things:
    - `refreshIfStale(LoopInterface)`: at most once per 24 h, an async `React\Http\Browser` GET of the release list ON the TUI loop, with no fork and no child, so no child-lifetime row is needed. It reuses `SelfUpdate::selectRelease()`/`selectDevRelease()` and the C-SES-5.a pagination, then writes `~/.candy-code/cache/update-check.json` (0600).
    - `cachedNotice()`: memoized per process.
  - **Startup line.** `StartupSummary::gather()` and `compose()` paint "update available: vX — run `candy-code self-update`" from the PREVIOUS check's cache, so `view()` never waits on the network.
  - **Kickoff.** `Bootstrap::chat()` schedules `Loop::get()->futureTick(...)`.
  - **Opt-out.** `CANDY_CODE_NO_UPDATE_CHECK=1`.
  - **Docs.** ENVIRONMENT.md gets the variable row; the README telemetry/network paragraph names the second non-LLM network call, beside the LiteLLM price refresh.
- files.create: `candy-code/src/Cli/UpdateCheck.php`, `candy-code/tests/Cli/UpdateCheckTest.php`
- files.modify: `candy-code/src/Support/StartupSummary.php` (gather(), compose()), `candy-code/src/Cli/Bootstrap.php` (chat(): kickoff), `candy-code/lang/en.php` (`startup.update.*`), `candy-code/docs/ENVIRONMENT.md` (the new row), `candy-code/README.md` (the network/telemetry paragraph)
- tests: create `candy-code/tests/Cli/UpdateCheckTest.php`:
  - fresh cache → no request;
  - stale cache → one request on a loopback server, then the cache is written;
  - the opt-out env var means no request;
  - composer mode means no request;
  - the notice is only produced when newer.
  
  Modify `candy-code/tests/Support/StartupSummaryTest.php` for the update row.
- docs/guards: `EnvRosterDriftTest`, moved by the ENVIRONMENT.md row in-step. The PLAT-07 COMPARISON cell flips at closeout.
- serialized: [bootstrap:chat] [lang:startup.update] [docs:ENVIRONMENT.md] [docs:README.md] [hot:candy-code/src/Support/StartupSummary.php]
- deps: C-SES-5.a
- phase: normal
- ruling: none (default: on for phar installs, with an env opt-out, mirroring the price-DB refresh)
- risks: The TUI loop has to be running for the async GET. The first launch after a stale cache only refreshes the cache, so the notice appears on the next launch. That is by design.

### C-SES-7.a — Session importer core (CC / Codex / OpenCode / candy-code)
- item: C-SES-7 (sources: GAP-SES-10, CLAW-new#3, p2-miss: session import)
- kind: gap · effort M
- approach:
  - **Formats.** `SessionImporter` auto-detects four formats:
    - candy-code's own export JSON (`Exporter::toJson`, read back via `Message::fromArray`);
    - Claude Code project JSONL;
    - Codex rollout JSONL;
    - the OpenCode `export` JSON.
  - **Bounds.** At most 50k items and 64 MiB (the OpenClaw bounds), with a typed refusal beyond either.
  - **Safety.**
    - Every text is redacted through `Memory/SecretRedactor`.
    - Foreign tool calls and results are flattened to text rows, never replayable tool rows.
    - A UI-only first row marks the session as imported, untrusted reference material.
  - **Persistence.** The result is persisted as a new `main` session with provider `import:<source>`, through `EnhancedSessionStore::saveTranscript()`, so FTS indexing rides the incremental sync. No `usage_records` rows are written: the session is unpriced, so it shows `≥`.
  - **Incremental re-import.** A `SessionImportLedger` side table (`session_imports(source_key PRIMARY KEY, session_id, items, digest, imported_at)`) is opened on `$store->path()`, the way `ShareStore` opens its table. A re-import appends only the new items.
  - **One type per file.** Each reader, DTO and enum gets its own file.
- files.create: `candy-code/src/Session/Import/SessionImporter.php`, `candy-code/src/Session/Import/ImportFormat.php`, `candy-code/src/Session/Import/ImportedTranscript.php`, `candy-code/src/Session/Import/CandyCodeExportReader.php`, `candy-code/src/Session/Import/ClaudeCodeTranscriptReader.php`, `candy-code/src/Session/Import/CodexTranscriptReader.php`, `candy-code/src/Session/Import/OpenCodeExportReader.php`, `candy-code/src/Session/Import/SessionImportLedger.php`, `candy-code/src/Session/Import/ImportRefusedException.php`, `candy-code/tests/Session/Import/SessionImporterTest.php`, `candy-code/tests/Session/Import/TranscriptReadersTest.php`
- files.modify: `candy-code/lang/en.php` (`session.import.*`: the notice row and the refusals)
- tests: create:
  - `candy-code/tests/Session/Import/TranscriptReadersTest.php`: fixtures per format; tool rows flattened; secrets redacted;
  - `candy-code/tests/Session/Import/SessionImporterTest.php`: an imported session becomes body-searchable; no usage rows; re-import appends only the delta; the bounds refuse.
- docs/guards: none (the CLI and RPC surfaces come in C-SES-7.b and C-SES-7.e)
- serialized: [lang:session.import.]
- deps: C-SES-2.a (imports must index into the persisted FTS index, not trigger a per-process rebuild)
- phase: normal
- ruling: none
- risks: The foreign formats drift between versions. Readers must refuse unknown shapes loudly (fixtures pinned by version), never guess.

### C-SES-7.b — `candy-code session import` verb
- item: C-SES-7 (sources: GAP-SES-10, p2-miss: session import)
- kind: gap · effort S
- approach: Add `candy-code session import <file> [--format auto|candy-code|claude-code|codex|opencode] [--name <n>] [--output-format json]`. It runs the C-SES-7.a importer and prints the new id plus a `--resume` hint.
  - `Subcommands::session()` gains the `import` arm, a `SUBCOMMAND_ACTIONS['session']` entry and the flag table (`:1089`).
  - It refuses while the target session is lock-held, the same way the other `session` verbs do.
- files.create: `candy-code/tests/Cli/SessionImportSubcommandTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (session(), sessionUsage(), the session flag table, SUBCOMMAND_ACTIONS), `candy-code/lang/en.php` (`cli.session.import.*`), `candy-code/README.md` (the Subcommands `session` block)
- tests: create `candy-code/tests/Cli/SessionImportSubcommandTest.php`: happy path text and JSON; unknown format exits 2; a bounds refusal exits 1. Modify `candy-code/tests/Cli/SessionSubcommandsTest.php` if it pins the action list.
- docs/guards: the README Subcommands block. `ReadmeRosterDriftTest` pins top-level verbs only, and `session` already exists.
- serialized: [headless] [lang:cli.session.import] [docs:README.md]
- deps: C-SES-7.a
- phase: normal
- ruling: none
- risks: none notable

### C-SES-7.c — Incognito launches (`--incognito`)
- item: C-SES-7 (sources: GAP-SES-N1)
- kind: gap · effort M
- approach:
  - **The flag.** `--incognito` is parsed in `ArgvParser`/`ParsedArgs`, set through `LaunchContext::useIncognito()` from `bin/candy-code`, and refused together with `--continue` or `--resume`.
  - **TUI launch.** `Bootstrap::openSession()`/`chat()` mint no row and pass `sessionStore: null` plus `incognito: true` to Chat.
    - Chat skips the turn-end auto-memory, dream and flush passes (`Chat/Handlers/HandlesTurnCompletion.php`).
    - `Renderer::renderStatusBar()` paints an "incognito" segment.
  - **`-p` launch.** `-p --incognito` skips the AUT-2 persist block and the C-SES-1.a ledger append.
  - **Serve.** Covered by C-SES-7.e (`session.create {ephemeral}`).
- files.create: `candy-code/tests/Chat/IncognitoLaunchTest.php`
- files.modify: `candy-code/src/Cli/ArgvParser.php` (flag), `candy-code/src/Cli/ParsedArgs.php` (field), `candy-code/bin/candy-code` (LaunchContext call), `candy-code/src/Cli/LaunchContext.php` (useIncognito()/incognito()), `candy-code/src/Cli/NonInteractive.php` (persist gate), `candy-code/src/Cli/Subcommands.php` (completion OPTIONS), `candy-code/src/Cli/Bootstrap.php` (openSession(), chat()), `candy-code/src/Chat.php` (ctor flag; the persistence/memory guards), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (memory passes), `candy-code/src/Renderer.php` (renderStatusBar()), `candy-code/lang/en.php` (`chat.incognito.*`, `cli.flag.incognito`), `candy-code/README.md` (the flags table and the Sessions section)
- tests: create `candy-code/tests/Chat/IncognitoLaunchTest.php`: no session row, no transcript, no memory write after a turn; the status segment is present. Modify:
  - `candy-code/tests/Cli/ArgvParserTest.php`: the flag, plus refusal with `--resume`;
  - `candy-code/tests/Cli/LaunchFlagsTest.php`: the completion/help roster;
  - `candy-code/tests/Cli/NonInteractiveSessionPersistenceTest.php`: incognito persists nothing.
- docs/guards: README flags table; `LaunchFlagsTest`
- serialized: [headless] [bootstrap:openSession,chat] [chat:ctor,persistence/memory guards] [renderer:renderStatusBar] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [lang:chat.incognito] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: This is the widest wiring footprint in the area. Schedule it in a quiet wave for Chat, Bootstrap and Renderer. Memory reads stay on; only writes are off.

### C-SES-7.d — Resume-cost guard
- item: C-SES-7 (sources: CC-new#10)
- kind: feature · effort M
- approach:
  - **When it fires.** After a stale resume (`--resume`, `--continue`, or a picker or tab switch onto a stored session), the FIRST submit is held once.
  - **The estimate.** `ResumeCostEstimate` is a pure function of:
    - the history token estimate, via `ContextMeter`;
    - the input price, via `ModelMetadata::costPer1kTokens($model, 'input')`;
    - the session's `last_activity` against a 5-minute prompt-cache TTL;
    - the `resumeCostWarnUsd` threshold (default 0.25; 0 disables).
  - **The hold.** When the estimate fires, Chat appends a UI-only notice ("Resuming re-sends ~84k tokens uncached (≈$0.42). Press Enter again to send, or run /compact first."). The second Enter sends, so no overlay is needed and `Renderer` stays untouched.
  - **Unpriced models.** The notice uses `≥`.
- files.create: `candy-code/src/Host/ResumeCostEstimate.php`, `candy-code/tests/Host/ResumeCostEstimateTest.php`, `candy-code/tests/Chat/ResumeCostGuardTest.php`
- files.modify: `candy-code/src/Chat.php` (submit() at `:9389`; the resume/switch arms that arm the guard), `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php` (`resumeCostWarnUsd`), `candy-code/lang/en.php` (`chat.resume_cost.*`, `settings.resumeCostWarnUsd.*`), `candy-code/docs/SETTINGS.md` (regen via `candy-code/tools/gen-settings-doc.php`)
- tests: create:
  - `candy-code/tests/Host/ResumeCostEstimateTest.php`: TTL edge, threshold 0, unpriced `≥`;
  - `candy-code/tests/Chat/ResumeCostGuardTest.php`: fresh resume → no hold; stale resume → hold once, then a second Enter sends; `/compact` clears the hold.
- docs/guards: the SETTINGS.md regen; `SettingsSchemaDocDriftTest`; `DocFigureProseDriftTest` (the settings count word-number); the README Interface settings roster if it lists keys (verify in-step)
- serialized: [chat:submit,resume/switch arms] [lang:chat.resume_cost] [docs:SETTINGS.md] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php]
- deps: none
- phase: normal
- ruling: none
- risks: P-SESSION-TABS reworks the tab-switch and resume path. Land this before it, or have the tabs lane carry the guard hook (see Notes).

### C-SES-7.e — Protocol: `session.import`, ephemeral `session.create`, archive/unarchive + list filter
- item: C-SES-7 (sources: GAP-SES-14 (server half), GAP-SES-N1 (serve half), OC-new#8, GAP-SES-10)
- kind: gap · effort M
- approach: One protocol lane carries three server surfaces, plus the web side.
  - **(1) `session.import {format, content, name?}`** (Write, idempotent). It runs the C-SES-7.a importer in-process and answers the new summary. It is bounded by the server's inbound frame cap, with a typed refusal beyond it.
  - **(2) `session.create {ephemeral: true}`.**
    - `SessionHub::create()` gains `bool $ephemeral`.
    - An ephemeral `SessionHost` skips `TranscriptStore` saves, the usage-record sink, and the title and memory passes.
    - The hub drops it on close.
  - **(3) `session.archive` and `session.unarchive {sessionId}`** wrap the existing `EnhancedSessionStore::archive()`/`unarchive()` (`:215-223`), and `session.list {archived?: bool}` filters on them. `SessionSummary` gains `archived` and `ephemeral`.
  - **Web.** The sidebar gains an Archive/Restore row action, a "Show archived" toggle, and an "Incognito" checkbox in the new-session flow.
- files.create: `candy-code/tests/Server/SessionImportMethodTest.php`, `candy-code/tests/Server/SessionArchiveMethodsTest.php`
- files.modify: `candy-code/src/Protocol/Methods/SessionMethods.php` (register; import(), archive(), unarchive(); list(), create() params), `candy-code/src/Protocol/Schema/MethodSchemas.php` (new and changed methods), `candy-code/src/Protocol/Schema/Definitions.php` (SESSION_SUMMARY archived/ephemeral), `candy-code/src/Protocol/ServerContext.php` (summary()), `candy-code/src/Host/SessionHub.php` (create(), list()), `candy-code/src/Host/SessionHost.php` (the ephemeral persistence gate), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/sessions.ts` (archive, unarchive, create ephemeral), `candy-code-web/src-web/stores/newSession.ts` (incognito option), `candy-code-web/src-web/components/SessionSidebar.vue` (row action, toggle), `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` (scope pins)
- tests: create:
  - `candy-code/tests/Server/SessionImportMethodTest.php`: an import becomes listable and searchable; oversize is refused;
  - `candy-code/tests/Server/SessionArchiveMethodsTest.php`: archive hides the row from the default list; `archived: true` shows it; unarchive restores it.
  
  Modify:
  - `candy-code/tests/Host/SessionHubTest.php`: an ephemeral session persists nothing;
  - `candy-code-web/src-web/components/__tests__/sidebarActions.spec.ts`: archive/restore;
  - `candy-code-web/src-web/stores/__tests__/sessionsRowActions.spec.ts`.
- docs/guards: the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- serialized: [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Host/SessionHub.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code-web/src-web/components/SessionSidebar.vue] [docs:SERVER.md (regen only)]
- deps: C-SES-7.a
- phase: normal
- ruling: none
- risks: The inbound WS frame cap is not verified (the outbox watermark is 16 MiB, `src/Server/Ws/Outbox.php:40`). Check the inbound limit in `src/Server/Ws/` before setting the import bound. C-WEB-7 has the matching 4 MiB export problem.

### C-SES-7.f — `attach` runs server slash commands and follows foreign turns
- item: C-SES-7 (sources: GAP-WEB-N5)
- kind: gap · effort M
- approach:
  - **Today.** An attached window runs slash commands on its own copy of the transcript and shows another client's turn only on re-attach (`docs/SERVER.md:1011-1014`).
  - **Server-side commands.** When the backend is a `RemoteBackend`, `Chat::dispatchCommand()` (`:11256`) routes a `/name` through `command.exec` whenever the cached `command.list` (`RemoteSessionHost::serverCommands()`, fetched once after `hello`) says `runsIn: "server"`. The reply rows render the same way `runHostCommand()` results do.
  - **Foreign turns.**
    - `RemoteBackend` keeps a persistent listener (`host->onEvent`) outside `runTurn()`, which buffers `turn.started`, `assistant.delta` and `turn.completed` from turns it did not start.
    - `Chat::subscriptions()` declares a `RemoteFeedPumpMsg` tick while attached.
    - The pump folds that buffer into history.
  - **Docs.** Flip the SERVER.md paragraph.
- files.create: `candy-code/src/App/RemoteFeedPumpMsg.php`, `candy-code/tests/Server/AttachRemoteCommandsTest.php`, `candy-code/tests/Server/AttachForeignTurnTest.php`
- files.modify: `candy-code/src/Backend/RemoteBackend.php` (the persistent foreign-turn inbox), `candy-code/src/Host/RemoteSessionHost.php` (serverCommands()), `candy-code/src/Chat.php` (dispatchCommand(): the remote arm; subscriptions()), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the RemoteFeedPumpMsg arm), `candy-code/docs/SERVER.md` (`:1011-1014`)
- tests: create:
  - `candy-code/tests/Server/AttachRemoteCommandsTest.php`: `/compact` typed in an attached window executes server-side; a client-only command still runs locally;
  - `candy-code/tests/Server/AttachForeignTurnTest.php`: a second client's turn appears in the attached window without re-attaching.
- docs/guards: SERVER.md prose; `ServerProtocolDocumentationDriftTest` (no roster change)
- serialized: [chat:dispatchCommand,subscriptions] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: Deltas of a foreign turn must not interleave with the window's own queued turn. Key the buffer by `turnId`.

### C-SES-7.g — `/handoff --remote <url>` teleport
- item: C-SES-7 (sources: GAP-SES-14)
- kind: gap · effort S
- approach:
  - `/handoff --remote <url>` exports the current session (`Exporter::toJson`).
  - `RemoteSessionPush` connects through `Attach::connect()`, with the token from the state dir or env, the same way `attach` resolves it.
  - It calls `session.import`.
  - It prints `candy-code attach <new-id> --url <url>`.
  - `HandoffHostCommand` parses `--remote`; the hint `cmd.handoff.hint` is a Lang key, so `3600-handoff.php` is not edited.
- files.create: `candy-code/src/Session/Import/RemoteSessionPush.php`, `candy-code/tests/Server/HandoffRemotePushTest.php`
- files.modify: `candy-code/src/Host/Commands/HandoffHostCommand.php` (`--remote` parsing → push effect), `candy-code/lang/en.php` (`cmd.handoff.hint`, `cmd.handoff.remote.*`), `candy-code/docs/COMMANDS.md` (regen), `candy-code/docs/SERVER.md` (the teleport paragraph)
- tests: create `candy-code/tests/Server/HandoffRemotePushTest.php`: against a loopback serve, the session lands remotely and the printed attach line is correct; a refused token surfaces a clear error.
- docs/guards: the COMMANDS.md regen; `CommandDocsDriftTest`
- serialized: [lang:cmd.handoff.remote] [docs:COMMANDS.md] [docs:SERVER.md]
- deps: C-SES-7.e
- phase: normal
- ruling: none
- risks: Pushing to a non-loopback URL over plain `ws://` exposes the transcript in transit. Refuse non-loopback `ws://` until C-WEB-5 (TLS, LATE) exists; there is no dependency on it.

### C-SES-8.a — `usage.rollup` RPC + web Usage panel with retention
- item: C-SES-8 (sources: GAP-SES-15w/17w, GAP-OBS-03)
- kind: gap · effort M
- approach:
  - **The method.** `usage.rollup {sessionId?, scope: 'session'|'all', days?: 7|30}` (Read) returns:
    - `{days[], models[], totals, unpriced, retentionDays, reason}`;
    - built from `UsageHostCommand::rollupFacts()` (C-SES-9.a), one snapshot;
    - with `retentionDays` from `Bootstrap::sessionRetentionDays()`.
  - **The panel.** `UsagePanel.vue` renders the totals, the day×model grid and the model table, using `spendLabel` and the `≥` law. It shows retention read-only, and links to Settings when the existing settings schema marks `sessionRetentionDays` writable remotely. No new write path.
- files.create: `candy-code/src/Protocol/Methods/UsageMethods.php`, `candy-code/tests/Protocol/UsageMethodsTest.php`, `candy-code-web/src-web/components/panels/UsagePanel.vue`, `candy-code-web/src-web/components/panels/__tests__/usagePanel.spec.ts`
- files.modify: `candy-code/src/Protocol/Dispatcher.php` (register), `candy-code/src/Protocol/Schema/MethodSchemas.php` ('usage.rollup'), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/protocol/__tests__/generated.spec.ts` (scope pin), `candy-code-web/src-web/components/panels/SidePanels.vue` (mount), `candy-code-web/src-web/stores/agents/panels.ts` (the `usage` panel id)
- tests: create:
  - `candy-code/tests/Protocol/UsageMethodsTest.php`: session and all scopes; unpriced polarity; no store gives a reason;
  - `candy-code-web/src-web/components/panels/__tests__/usagePanel.spec.ts`: `≥` rendering; the retention line.
- docs/guards: the generation chain; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`, `generated.spec.ts`
- serialized: [protocol] [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [docs:SERVER.md (regen only)]
- deps: C-SES-9.a
- phase: normal
- ruling: none
- risks: P-CTX-BREAKDOWN and C-WEB-3 also mount panels in `SidePanels.vue` and `panels.ts`. Serialize those two files.

### C-SES-8.b — Ledger attribution by source + `/usage` advice
- item: C-SES-8 (sources: CC-new#5, GAP-OBS-03 (attribution))
- kind: feature · effort M
- approach:
  - **Columns.** `usage_records` gains `source TEXT` and `delegated_tokens INTEGER`, through a guarded migration. `delegated_tokens` comes from `Usage::$delegatedTokens`, the sub-agent share.
  - **Threading the source.**
    - `SpendLedger::account()` and `appendUsageRecord()` gain an optional `?string $source`. PHP's extra-argument tolerance keeps mirrors working.
    - `Chat::accountUsage()` (`:18954`) labels its four settle sites: `turn`, `superseded`, `compact`, `title`.
    - `SessionHost`'s three `account()` sites (`:609`, `:1825`, `:1905`) label `turn`, `aux`, `goal`.
  - **`/usage sources`.** A new view, plus an Advice block built from `ContextSources`. Example: "MCP server `x` adds ~12k schema tokens per turn → `/mcp disable x`".
  - **Hint.** `cmd.usage.hint` gains `sources`, which regenerates COMMANDS.md.
- files.create: none
- files.modify: `candy-code/src/Session/EnhancedSessionStore.php` (migration, appendUsageRecord(), a rollup by source), `candy-code/src/Host/SpendLedger.php` (account()), `candy-code/src/Chat.php` (accountUsage(), usageRecordSink(), its 4 callers), `candy-code/src/Host/SessionHost.php` (usageRecordSink(), 3 account() sites), `candy-code/src/Commands/UsageCommand.php` (compose(): the sources + advice sections), `candy-code/src/Host/Commands/UsageHostCommand.php` (the `sources` arm, ContextSources facts), `candy-code/lang/en.php` (`cmd.usage.hint`, `cmd.usage.source.*`, `cmd.usage.advice.*`), `candy-code/docs/COMMANDS.md` (regen)
- tests: modify:
  - `candy-code/tests/Session/UsageRecordsTest.php`: the source and delegated columns migrate on an old db;
  - `candy-code/tests/Commands/UsageCommandTest.php`: the sources table and advice lines;
  - `candy-code/tests/Host/UsageModelThreadingTest.php`: the source rides beside the model;
  - `candy-code/tests/Host/SpendLedgerTest.php`.
- docs/guards: the COMMANDS.md regen; `CommandDocsDriftTest`
- serialized: [chat:accountUsage,usageRecordSink + its 4 settle arms] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Session/EnhancedSessionStore.php] [hot:candy-code/src/Host/Commands/UsageHostCommand.php] [lang:cmd.usage.] [docs:COMMANDS.md]
- deps: C-SES-9.a
- phase: normal
- ruling: none
- risks: Advice keys off `ContextSources`, which P-CTX-BREAKDOWN may reshape. Read through its public API only.

### C-SES-8.c — Insights report core
- item: C-SES-8 (sources: GAP-OBS-06, p2-miss: OBS-06 insights)
- kind: gap · effort M
- approach:
  - **Report contents.** `InsightsCommand::compose(facts)` builds a text summary plus a self-contained HTML report over a window (default 30 days):
    - spend by model and by day (`≥` law);
    - sessions and turns per day;
    - busiest projects (session `cwd`);
    - kinds (main, branch, background, sub-agent);
    - unpriced share;
    - imported sessions.
  - **Facts.** `InsightsHostCommand` gathers them through existing public store reads (`rollupFacts()` from C-SES-9.a, `listSessionsWithMeta`), so `EnhancedSessionStore` is not edited.
  - **Output.** It writes `~/.candy-code/exports/insights-<date>.html` (0600, beside `/export`'s files) and replies with the summary plus the path.
- files.create: `candy-code/src/Commands/InsightsCommand.php`, `candy-code/src/Host/Commands/InsightsHostCommand.php`, `candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php`
- files.modify: `candy-code/lang/en.php` (`cmd.insights.*`)
- tests: create:
  - `candy-code/tests/Commands/InsightsCommandTest.php`: the figures; `≥`; an empty ledger; HTML escaping;
  - `candy-code/tests/Host/Commands/InsightsHostCommandTest.php`: the file is 0600; the reply names the path.
- docs/guards: none (wiring is in C-SES-8.d)
- serialized: [lang:cmd.insights.]
- deps: C-SES-9.a
- phase: normal
- ruling: none
- risks: Scope stays on store data. CC's "friction / suggestions" needs a model call; leave it out (no billed side call).

### C-SES-8.d — `/insights` command wiring
- item: C-SES-8 (sources: GAP-OBS-06)
- kind: gap · effort S
- approach:
  - Add a NEW spec file, `builtin-commands/2215-insights.php` (category App, `withHandler('handleInsightsCommand')`, `withHostCommand(InsightsHostCommand::class)`). It is per-command-disjoint, not serialized.
  - Add `Chat::handleInsightsCommand()` (`runHostCommand`).
  - Add `insights` to `READ_ONLY_COMMANDS` (`src/Chat.php:10266`), the way `/export` is.
  - Update the README slash roster and regenerate COMMANDS.md.
- files.create: `candy-code/builtin-commands/2215-insights.php`
- files.modify: `candy-code/src/Chat.php` (new handleInsightsCommand(); the READ_ONLY_COMMANDS row), `candy-code/README.md` (the Slash commands roster), `candy-code/docs/COMMANDS.md` (regen), `candy-code/tests/Commands/MenuBarLabelDerivationTest.php` (the DERIVED_LABELS row)
- tests: modify:
  - `candy-code/tests/Commands/MenuBarLabelDerivationTest.php`;
  - `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` (if it enumerates slash-only rows; verify in-step);
  - `candy-code/tests/Commands/RegistryTranslationTest.php` (if it counts rows).
- docs/guards: README slash roster → `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; the COMMANDS.md regen → `CommandDocsDriftTest`
- serialized: [chat:handleInsightsCommand] [commands:READ_ONLY_COMMANDS] [docs:README.md] [docs:COMMANDS.md]
- deps: C-SES-8.c
- phase: normal
- ruling: none
- risks: none notable

### C-SES-8.e — `candy-code usage` CLI verb
- item: C-SES-8 (sources: OBS-3-P1, p2-miss: optional `candy-code usage` verb)
- kind: gap · NIT · effort S
- approach:
  - `candy-code usage [7|30|all] [--session <id>] [--output-format json]` prints `UsageCommand::compose(UsageHostCommand::rollupFacts(...))`.
  - JSON mode emits the facts map.
  - The verb moves `ParsedArgs::SUBCOMMANDS`, the completion verb table (`cli.completion.verb.usage`) and the Help text.
- files.create: `candy-code/tests/Cli/UsageSubcommandTest.php`
- files.modify: `candy-code/src/Cli/ParsedArgs.php` (SUBCOMMANDS), `candy-code/src/Cli/ArgvParser.php` (verb parse, if verbs are listed there), `candy-code/src/Cli/Subcommands.php` (dispatch, usage(), SUBCOMMAND_VERBS), `candy-code/lang/en.php` (`cli.usage.*`, `cli.completion.verb.usage`), `candy-code/README.md` (the Subcommands fence)
- tests: create `candy-code/tests/Cli/UsageSubcommandTest.php`: text and JSON; unpriced `≥`; no store gives a reason. Modify `candy-code/tests/Integration/BinCandyCodeDispatchTest.php` (the verb row).
- docs/guards: the README Subcommands fence → `ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts`
- serialized: [headless] [lang:cli.usage,cli.completion.verb.usage] [docs:README.md]
- deps: C-SES-9.a
- phase: normal
- ruling: none
- risks: none notable

### C-SES-8.f — JSONL transcript writer + `jsonl` export format
- item: C-SES-8 (sources: GAP-OBS-05)
- kind: gap · effort M
- approach:
  - **Format.** `Exporter::toJsonl()` writes one `Message::toArray()` object per line. `ShareSession` gains `FORMAT_JSONL` (in `SUPPORTED_FORMATS`, with mime and extension), and `ExportCommand` maps `jsonl`.
  - **Protocol untouched.** The `session.export` schema enum is a literal `['markdown','json','text']` (`MethodSchemas.php:185`) and stays unchanged; jsonl is TUI and CLI only.
  - **`JsonlTranscriptWriter`.**
    - An append-only per-session file at `~/.candy-code/transcripts/<project-slug>/<session-id>.jsonl` (dir 0700, file 0600).
    - A high-water mark, so a re-save appends only new messages.
    - A rewind writes a `{"type":"rewind","to":N}` marker line instead of rewriting the file.
    - `pathFor($sessionId, $root)`, so hooks can name the file.
- files.create: `candy-code/src/Share/JsonlTranscriptWriter.php`, `candy-code/tests/Share/JsonlTranscriptWriterTest.php`
- files.modify: `candy-code/src/Util/Exporter.php` (toJsonl()), `candy-code/src/Share/ShareSession.php` (FORMAT_JSONL), `candy-code/src/Commands/ExportCommand.php` (the format/extension map at `:216-219`), `candy-code/lang/en.php` (`cmd.export.hint`), `candy-code/docs/COMMANDS.md` (regen)
- tests: create `candy-code/tests/Share/JsonlTranscriptWriterTest.php`: append-only; idempotent re-save; the rewind marker; modes 0600/0700. Modify `candy-code/tests/Commands/ExportCommandTest.php` (`/export x.jsonl`).
- docs/guards: the COMMANDS.md regen; `CommandDocsDriftTest`
- serialized: [lang:cmd.export.] [docs:COMMANDS.md]
- deps: none
- phase: normal
- ruling: none
- risks: `ShareCommand::parseFormat()` reads `SUPPORTED_FORMATS`, so `/share jsonl` becomes legal. Pin or exclude it in-step.

### C-SES-8.g — JSONL wiring: setting, store mirror, `transcript_path` for hooks
- item: C-SES-8 (sources: GAP-OBS-05, CC-new (JSONL + `transcript_path`))
- kind: gap · effort M
- approach:
  - **The setting.** `transcriptJsonl` (Bool, default false, Interface category).
  - **The mirror.**
    - `Bootstrap::sessionStore()` wires `EnhancedSessionStore::withJsonlMirror(JsonlTranscriptWriter)` when the setting is on, and sets a process flag for `JsonlTranscriptWriter::enabled()`.
    - `saveTranscript()` (`:1956`) calls the mirror AFTER the transaction commits, and the call never throws.
  - **The hook payload.** `ScriptHook::stdinPayload()` (`:1067-1076`) gains a seventh fixed key, `transcript_path`: the path when the mirror is enabled, otherwise `null`. The key is always present, keeping the fixed-shape doctrine.
  - **Docs.** HOOKS.md moves from six keys to seven, and SETTINGS.md is regenerated.
- files.create: none
- files.modify: `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php` (`transcriptJsonl`), `candy-code/src/Cli/Bootstrap.php` (sessionStore()), `candy-code/src/Session/EnhancedSessionStore.php` (withJsonlMirror(), saveTranscript() post-commit), `candy-code/src/Hooks/ScriptHook.php` (stdinPayload()), `candy-code/lang/en.php` (`settings.transcriptJsonl.*`), `candy-code/docs/HOOKS.md` (the stdin payload section), `candy-code/docs/SETTINGS.md` (regen)
- tests: modify:
  - `candy-code/tests/Hooks/ScriptHookTest.php`: seven keys; `transcript_path` is null when off and the path when on;
  - `candy-code/tests/Hooks/ScriptHookJsonStdoutTest.php` (if it pins the key list);
  - `candy-code/tests/Session/UsageRecordsTest.php`: none; use the store test that covers `saveTranscript` instead (e.g. `candy-code/tests/Host/TranscriptStoreTest.php`): the mirror runs post-commit, and a throwing mirror does not fail the save.
- docs/guards: the SETTINGS.md regen → `SettingsSchemaDocDriftTest`; the `DocFigureProseDriftTest` settings count; HOOKS.md moves from "six keys" to seven (grep the HOOKS.md figure prose)
- serialized: [bootstrap:sessionStore] [hot:candy-code/src/Hooks/ScriptHook.php] [hot:candy-code/src/Session/EnhancedSessionStore.php] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [lang:settings.transcriptJsonl] [docs:HOOKS.md] [docs:SETTINGS.md]
- deps: C-SES-8.f
- phase: normal
- ruling: none
- risks: C-EXT items (hook kinds, the hook log) almost certainly touch `ScriptHook.php` and HOOKS.md. Serialize this step against them.

---

## Notes for the orchestrator

**Protocol lanes (one per wave):** C-SES-2.c, C-SES-3.c, C-SES-7.e and C-SES-8.a. They need four distinct waves.
- C-SES-3.c is S. Schedule it early, because C-WEB-3's Share/Revoke menu consumes `session.shares`.
- C-SES-2.c and C-SES-7.e both reshape `SessionSummary` (`ServerContext::summary()`, `Definitions.php`) and `SessionSidebar.vue`.

**Hot files shared across my steps** (serialize them, or put them in consecutive waves):

| File | Steps |
|---|---|
| `candy-code/src/Host/SessionHost.php` | C-SES-4.d, 7.e, 8.b, 9.b (and other areas: serve parity, P-SESSION-TABS) |
| `candy-code/src/Session/EnhancedSessionStore.php` | C-SES-2.a, 8.b, 8.g, 9.a |
| `candy-code/src/Session/SessionStore.php` | C-SES-3.a, then 9.a (encoded as a dep, same `deleteSessionTrees()` region) |
| `candy-code/src/Host/Commands/UsageHostCommand.php` | C-SES-9.a, then 8.b |
| `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` | C-SES-2.b, 4.b, 7.f (each adds one Msg arm) |
| `candy-code-web/src-web/components/SessionSidebar.vue` | C-SES-2.c, 7.e, plus C-WEB-3's Share/Revoke menu |
| `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php` and `docs/SETTINGS.md` | C-SES-7.d, 8.g |
| `candy-code/src/Support/StartupSummary.php` | C-SES-6.e (C-CTX's VIS-1-P1 personal-instruction-files fix probably touches it too) |
| `candy-code/src/Hooks/ScriptHook.php` and `docs/HOOKS.md` | C-SES-8.g, plus C-EXT hook work |
| `candy-code/src/Cli/NonInteractive.php` and `Subcommands.php` | C-SES-1.a, 4.c, 7.b, 7.c, 8.e (all `[headless]`, so one per wave) |
| `.github/workflows/release-phar.yml` | C-SES-9.d → 5.c → 6.d (chained by deps); `release-app-phar.yml` is 9.d → 5.c |

**Docs owners.** These pages are edited by several of my steps; give each page one owner per wave.

| Page | Steps |
|---|---|
| `README.md` | 1.a, 5.b, 6.a, 6.c, 6.e, 7.b, 7.c, 8.d, 8.e |
| `docs/ENVIRONMENT.md` | 4.b, 4.c, 4.d, 6.e (the same OTLP row in 4.b, 4.c and 4.d) |
| `docs/SERVER.md` (prose) | 3.d, 5.d, 7.f, 7.g; regenerated by the protocol lanes |
| `docs/COMMANDS.md` (regen) | 3.b, 3.d, 7.g, 8.b, 8.d, 8.f |

**Rulings:**

| Step | Ruling |
|---|---|
| C-SES-3.d | Ship anonymous live read-only links? |
| C-SES-4.d | Serve telemetry, the OBS-02 owner gate |
| C-SES-6.d | Homebrew tap repo plus a push secret. It is not blocking: without it, the step ships the formula only as an asset. |
| C-SES-6.e | Ships with a stated default (on for phar installs, env opt-out); the owner can flip it |

**Cross-area dependencies:**
- C-SES-3.c → C-WEB-3 (the Share/Revoke menu).
- C-SES-2.a → C-CTX-3 (model recall over sessions should read the persisted FTS index).
- C-SES-4.a, then C-SES-4.b → C-SEC-6's OTel safety events. Serve-side safety events also need C-SES-4.d, which is ruling-gated, so C-SEC-6 should emit TUI and `-p` events first.
- C-SES-7.d (the resume-cost guard) and C-SES-2.b (the picker) touch the resume and switch surfaces that P-SESSION-TABS rewrites. Land them before P-SESSION-TABS, or brief the tabs lane to carry the guard hook.
- C-SES-7.g refuses non-loopback `ws://` instead of depending on C-WEB-5 (LATE).

**Items with no step:**
- The OBS-05 doc-drift bullet of C-SES-9 is already satisfied: the working-tree `candy-code/COMPARISON.md` OBS-05 cell says "`-p` runs persist".
- The DST-3-P5 COMPARISON phrase ("from the first release tag onward") and the DST-2-P1 one ("proof-builds in CI") are gone from COMPARISON.md too.
- COMPARISON cells for these features (PLAT-03, PLAT-05, PLAT-07, OBS-02, OBS-05, OBS-06, SES-09, SES-10, SES-17) flip at closeout. No lane edits COMPARISON.md.

**candy-top:** `candy-top/src/Cli/SelfUpdate.php` also verifies raw-byte `.minisig` signatures. C-SES-5.c is additive (it keeps `.minisig`), so candy-top keeps working. Porting candy-top to the sidecar signature is out of the SES scope.

**Step-count deviation:** C-SES-7 and C-SES-8 each bundle 5–6 independent sub-features. They split into core-and-wiring pairs of S/M steps (7 steps each), deliberately beyond the 2–4 guideline, so cores can run wide while only the thin wiring steps serialize.

**Fork/child discipline:** no step adds a `proc_open` or fork in `src/`.
- The update check (C-SES-6.e), async OTLP (C-SES-4.b) and the remote push (C-SES-7.g) all run in-process on the React loop.
- `install.sh` and its test spawn only from `tests/`.
- No child-lifetime roster row is needed.

**Unverified (check in-step):**
- C-SES-6.a: whether `Phar::running(false)` answers the real path under the `mapPhar` alias.
- C-SES-4.b: whether the React loop runs during a `-p` completion. The design covers both cases by reconciling in-flight bodies at `flush()`.
- C-SES-7.e: the inbound WebSocket frame cap.
- C-SES-5.d: the Packagist lag after `sync-sugarcraft.yml`.
- C-SES-7.d and C-SES-8.g: whether the README Interface-settings section enumerates keys, which would make it a roster.

**New test files, for the weld's `scripts/parallel-tests-durations.tsv` (candy-code):**
- C-SES-1.a: `tests/Cli/NonInteractiveUsageLedgerTest.php`
- C-SES-2.b: `tests/Chat/SessionPickerDebouncedSearchTest.php`
- C-SES-2.c: `tests/Server/SessionListBodySearchTest.php`
- C-SES-3.a: `tests/Session/SessionDeleteRevokesSharesTest.php`
- C-SES-3.b: `tests/Host/ShareHostCommandBufferTest.php`
- C-SES-4.b: `tests/Observability/AsyncOtlpTransportTest.php`, `tests/Observability/ChatTelemetryRetryTickTest.php`
- C-SES-4.c: `tests/Cli/NonInteractiveOtelExportTest.php`
- C-SES-4.d: `tests/Host/SessionHostOtelFoldTest.php`
- C-SES-6.c: `tests/Cli/InstallScriptTest.php`, `tests/Cli/HomebrewFormulaTest.php`
- C-SES-6.e: `tests/Cli/UpdateCheckTest.php`
- C-SES-7.a: `tests/Session/Import/SessionImporterTest.php`, `tests/Session/Import/TranscriptReadersTest.php`
- C-SES-7.b: `tests/Cli/SessionImportSubcommandTest.php`
- C-SES-7.c: `tests/Chat/IncognitoLaunchTest.php`
- C-SES-7.d: `tests/Host/ResumeCostEstimateTest.php`, `tests/Chat/ResumeCostGuardTest.php`
- C-SES-7.e: `tests/Server/SessionImportMethodTest.php`, `tests/Server/SessionArchiveMethodsTest.php`
- C-SES-7.f: `tests/Server/AttachRemoteCommandsTest.php`, `tests/Server/AttachForeignTurnTest.php`
- C-SES-7.g: `tests/Server/HandoffRemotePushTest.php`
- C-SES-8.a: `tests/Protocol/UsageMethodsTest.php`
- C-SES-8.c: `tests/Commands/InsightsCommandTest.php`, `tests/Host/Commands/InsightsHostCommandTest.php`
- C-SES-8.e: `tests/Cli/UsageSubcommandTest.php`
- C-SES-8.f: `tests/Share/JsonlTranscriptWriterTest.php`

**Root `tools/tests`** (the `tools-guards` job, not the candy-code TSV): `tools/tests/WorkflowRunInterpolationTest.php` (C-SES-9.d) and `tools/tests/CandyCodeImageCacheBustTest.php` (C-SES-5.d).

**Web vitest:** `candy-code-web/src-web/components/panels/__tests__/usagePanel.spec.ts` (C-SES-8.a).
