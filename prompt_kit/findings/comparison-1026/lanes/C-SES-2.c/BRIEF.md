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

