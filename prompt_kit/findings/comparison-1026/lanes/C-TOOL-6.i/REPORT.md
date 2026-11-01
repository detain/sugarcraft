# C-TOOL-6.i — Client-hosted tools: `tools.client.*` protocol, `server.clientTools` gate, web store

STEP=C-TOOL-6.i LANE=/home/sites/cc-cmp-C-TOOL-6.i branch cc/C-TOOL-6.i base 77beb227d.

## Picks (all cat-file'd, all %ae=%ce=detain@interserver.net, zero Co-Authored-By, porcelain 0 at tip)

- `f4587a47e89551a2d1f19a71ca5efba6674f7111` — protocol surface + gate + event relay (13 files, +305/−3): ServerSettings.php definition (`server.clientTools`, Bool, default false, Server/Security, user-config-only), ServerConfig.php (property + resolve key read with plain-type message + `withClientTools`), lang/en.php (label+help, PYTHON dupscan 0 across 3994 keys), MethodSpec.php name-grammar widening, ClientToolMethods.php (new: register/answer, both Scope::Write sideEffects:true, gate kind `client_tools_disabled`), Dispatcher.php (import + register line), MethodSchemas.php (both RPC rows), SessionEvent/EventType/EventSchemas (`tool.client.call`, ephemeral session-scope), ServerContext.php (feedFor onClientCall→announce relay; removeClient forgetClientTools sweep; dropFeed unbind), docs/SETTINGS.md via `tools/gen-settings-doc.php --write`, tests/Cli/ServeArgsTest.php (provider row + bool-key test).
- `6f9142db1faf15554bdee325993381f4b891e971` — candy-code/tests/Protocol/ClientToolMethodsTest.php (+334): 9 tests / 68 assertions, all EXEC'd green.
- `8dfac2340c5dd8166a18fcbec3278c3f35729bf0` — web slice (+400): src-web/stores/clientTools.ts, src-web/lib/clientTools/browserContext.ts, src-web/stores/__tests__/clientTools.spec.ts (5 tests green), session.ts attach, README bullet.

## 6.h substrate reuse — zero-diff proof

`git diff --stat 77beb227d..HEAD -- src/Host/ClientTools/ src/Host/SessionHost.php src/Backend/PendingClientCall.php src/Host/ChildChannel.php` = EMPTY. All nine 6.h picks (97b2787d4…85bfba37f) verified ancestors of base. The step rides ClientToolRegistry::register/forget/currentRegistrationId, ClientToolSpec::refusal/fromDecoded, SessionHost::registerClientTools(:779)/resolveClientCall(:810)/onClientCall(:841)/clientCallSink (test seam via the 6.h-pinned reflection), PendingClientCall frame fields, and the welded client_call/client_result channel frames — nothing duplicated, nothing in the welded engine/host core re-semantically edited.

## Gate trace (RPC → proxy → channel)

1. `server.clientTools` off ⇒ ClientToolMethods::requireDoor → forbidden kind `client_tools_disabled` both RPCs (pinned EXEC).
2. register: ClientToolMethods::announce → SessionMethods::host → ClientToolSpec::refusal parse-gate → SessionHost::registerClientTools($call->client->id(), specs) → registrationId; roster→proxy append is the 6.h-welded EngineBackend path (pinned there; `effective:"next_turn"` byte-consistent).
3. call: welded sink → ServerContext::feedFor(:385) onClientCall bind → `$host->announce(TOOL_CLIENT_CALL {callId,registrationId,name,arguments,deadlineAt})` → ephemeral → feed fan-out to session subscribers only (pinned: 2 subscribers get it, other session does not; event conforms to EventSchemas).
4. answer: tools.client.result → SessionHost::resolveClientCall(:810) — first-wire-answer wins; stale/foreign/unknown accepted:false, never an error (pinned EXEC).
5. disconnect: ServerContext::removeClient sweep forgetClientTools → pending fails at once 'the client that hosts this tool is gone' (pinned EXEC; dropFeed also unbinds relay).
6. web: session.subscribe success → clientTools.attach → connection.request('tools.client.register') → onEvent tool.client.call (own registrationId) → handler under deadline → request('tools.client.result'); `client_tools_disabled` = store off, no retry (pinned vitest EXEC).

## Protocol trio — regen-verify-RESTORE

Fresh-trio gates went green (ProtocolSchemaDriftTest 7T/303A incl. the table-current arm previously red ONLY on trio staleness; DocFigureProseDriftTest 92T/4253A; web build ✓ 1.40s). All four RESTORED: `git diff --stat 77beb227d..HEAD -- schema.json SERVER.md generated.ts dist` = EMPTY (TRIO-DIST-ZERO-DIFF-OK). Welder must carry: schema +167 lines; SERVER.md +3 generated table rows (2 methods + 1 event) AND the hand paragraph in `SERVER-MANUAL-SECTION.md` (1824 B, same dir, verbatim placement note); generated.ts +53 lines.

## §1.8 in-step cascade state

Docs (SETTINGS.md rows, README web bullet, ServeArgsTest both-sides, lang pair, schema rows, EventSchemas/EventType/SessionEvent trio) all rode the same commits as the code. New Lang keys: one SORT_STRING position each (adjacent dirBrowse), dupscan 0. RPC names byte-consistent across ClientToolMethods/MethodSchemas/dispatch and web store call sites. dormantRoster: store names the methods literally (`request(\`tools.client…\`` shape via typed alias — name `request` load-bearing) so the welded roster sees live browser callers; 8/8 green. check-one-type-per-file: 0 problems (62 libs/3373 files). ReadPathCensus: no new server-side path opens (registry stores in-memory only) — no row needed.

## Gates (all NON-ZERO, cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS unless noted; ambient 'Deprecations: 2' accepted)

- ClientToolMethodsTest:: 9T/68A OK; ServeArgsTest:: 46T/234A OK; SettingsSchemaDocDriftTest:: 19T/198A; TrustKeyDocumentationDriftTest:: 12T/595A; SettingsSchemaTest:: 46T/2676A; ReadmeSettingsTierClaimTest:: 6T/41A; LangParityTest:: 7T/4013A; CliLangCatalogueTest:: 8T/220A OK.
- EventTypeTest:: 4T/145A; SessionHostTest:: 18T/83A; DirBrowseTest:: 5T/33A; DocFigureProseDriftTest:: 92T/4253A (fresh-trio leg).
- BARE TreeWideGuardRosterTest:: 17T/3957A OK; BARE DuplicatedTestHelperDriftTest:: 14T/204A OK; WebStringsTest::|WebStringsDriftTest:: 9T/586A OK.
- vitest serial --no-file-parallelism: clientTools.spec.ts 5/5; session.spec+approvals.spec 20/20; clientTools+dormantRoster 8/8 (twice, incl. post-restore vs stale trio).

## Foreign/accepted reds (cure-NONE, adjudicated)

GlobDialectDifferentialTest 1F = doc-block 310,245 vs derived 311,360 — the spawn-recorded neither-figure (asserted NEITHER value; my files add zero glob-shaped literals); ambient PHPUnit Deprecations:2 family; foreign typecheck gap: `npm run typecheck` carries 25 pre-existing errors across 11 files never touched by this lane (contextSources.ts:176 proven identical with committed generated.ts via stash probe) — my five files contribute zero; reported for the gate.

## Deviations + refused seams

1. **MethodSpec name grammar widened** (`/^[a-z]+(\.[a-z]+)*\.[a-zA-Z]+$/`): design mandates two-segment `tools.client.*`; the welded single-dot rule rejected the names. Additive; existing names unaffected; no legacy test pinned the old regex (tree census: no MethodSpecTest). Disclosed as the one forced src deviation.
2. ScriptedTurnBackend is a permission-channel backend, not a tool-call emitter → relay pinned via the 6.h reflection-sink seam; EngineBackend roster-append stays 6.h-pinned (no duplicate pin).
3. No `hello.features` clientTools arm — design does not require one; gate answers per-call.
4. LayeredSettingsTest both-sides instruction NOT-APPLICABLE (measured: no server.*/dirBrowse roster pin exists); ServeArgsTest is the design-named in-step pair — done.
5. candy-code/README.md untouched: gen-settings-doc derives rows only into SETTINGS.md (measured).
6. Web store reads the raw method-name alias despite the generated-union absence (trio-restoration law): cast `as unknown as ClientToolMethod`; name `request` deliberately chosen so the dormantRoster regex sees live callers. Welder with the trio carried may narrow the cast if desired — behavior identical.

## §7 new-file live counts (for durations TSV, closeout-owned)

- candy-code/tests/Protocol/ClientToolMethodsTest.php — 9T/68A.
- candy-code-web/src-web/stores/__tests__/clientTools.spec.ts — 5 tests (vitest; no TSV row genre).

## Stop-point

Step COMPLETE on branch cc/C-TOOL-6.i (3 picks ahead of 77beb227d; origin at 7e042da7b — welder merges, lane never rebases with picks). Trio/dist restored; SERVER-MANUAL-SECTION.md (1824 B) awaits the welder. background=true confirmed.
