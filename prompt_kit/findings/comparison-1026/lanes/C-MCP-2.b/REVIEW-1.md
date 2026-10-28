# REVIEW-1 — lane C-MCP-2.b (ec48dc189, base 4b771634d, reviewed in /home/sites/cc-cmp-C-MCP-2.b)

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 0 MINOR / 1 NIT

## Scope / mechanics (all PASS)
- Branch `cc/C-MCP-2.b`, ONE commit, 12 files +984/−52. Diff == the declared 12-file set:
  design's files.create (McpMethods.php, CommandMethodsMcpPromptsTest, McpMethodsTest),
  files.modify (CommandMethods incl. the declared `files()` seam retirement, Dispatcher,
  MethodSchemas, SessionHost::customCommands(), McpClient, docs/MCP.md) plus the three
  in-step test flips (ConformanceTest, ProtocolSchemaDriftTest, ServedProjectCommandTrustTest)
  named in the lane header. No undeclared files.
- Author law: `%ae` and `%ce` greps separate, both 1 (detain@interserver.net); 0 Co-Authored.
- Regen trio (schema.json, SERVER.md, generated.ts) NOT in the commit — confirmed by the
  12-file name list and clean `git status` on the lane tree. §1.8 restore-then-weld honored.
- Tree clean after gates (only the untracked lane evidence dir).
- MethodSchemas placement matches Dispatcher order; ProtocolSchema documents the roster in
  REGISTRY order (`foreach ($this->methods->all())`, ProtocolSchema.php:88), and
  `McpMethods::register` sits right after `CommandMethods::register` (Dispatcher.php:134-135),
  so the welder's regen order-matches the claim.

## Focus 1 — trust/security walk (PASS)
- Denied/untrusted servers cannot leak: `mcp.resources` answers `resourcesRoster()` only for
  servers that pass `isServerAllowed()` inside `primeSurfaceRosters()` (McpClient.php:1210-1212
  `continue`), and untrusted launches have no client memo at all (`Bootstrap::mcpClient`
  answers null per the trust gate; memo peek → `not-started`). Pinned:
  `testADeniedServerContributesNoCommandRows` (CommandMethodsMcpPromptsTest.php:124-131) and
  `testResourcesAnswer...` asserting roster keys exactly `['srv','bulk']` (McpMethodsTest.php:86-87).
- `command.exec` admits MCP rows through the unchanged `TurnMethods::admit` path
  (CommandMethods.php:94) — the same admission as `session.send` and file rows; the prompt
  body materialises on the forked route in the child (MCP.md, pre-existing pin), inline
  `!cmd` shell forms in fetched bodies are refused outright (pre-existing C-MCP-2.a/SEC-3
  discipline, unchanged here). Trust flag rides the host map
  (`loadAll($root, $workspace->projectCommandsTrusted)`, SessionHost.php:343-345), and the
  ServedProjectCommandTrustTest legs remain meaningful: its command.list leg now proves the
  SAME answer through the host-map seam (flipped call passes sessionId; docblock truth-updated).
- exec-first-map order (customCommands lookup precedes CommandRegistry walk) matches the base
  files()-first shape; built-in shadowing posture unchanged; MCP rows live behind the
  `mcp__<server>__` namespace so they cannot shadow built-ins.

## Focus 2 — no-launch law (PASS)
- `McpMethods::memoisedClient` (McpMethods.php:86-95) is the `mcpPinRefusals` peek shape:
  `McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($root))` then
  `LaunchContext::hasMcpClient/mcpClientFor`. Verified pure: `mcpConfigDecision`
  (Bootstrap.php:7974) reads config + trust and records only a refusal side-map;
  `mcpConfigMemoKey` (McpDecision.php:506) is string derivation; the LaunchContext trio
  (:534/:544/:556) are bucket reads. Zero spawn/reconnect on the wire path.
- `resourcesRoster()` calls `primeSurfaceRosters()` — same shape as the accepted
  `promptsRoster()` (C-MCP-2.a): prime iterates `$this->servers` (started only), is
  idempotent, per-server refusal costs only that entry. A memoised client exists only where
  the launch already started servers. `testAColdPeekAnswersNotStartedWithoutBuildingAClient`
  proves the cold root answers from the memo miss alone.
- Frame bounds: per-field caps 512/200/128/400 with control-flatten + house ellipsis
  (McpMethods.php:39-42,140-148), 200-rows/server slice at the store
  (SURFACE_RESOURCE_ROWS_KEPT, McpClient.php:1219). Worst answer ≈ servers × ~280KB —
  bounded per server and strictly tighter than the base promptsRoster precedent (which slices
  nothing). Not an explosion. `preg_replace` returning false on invalid UTF-8 casts to `''`
  (McpMethods.php:142) — fail-closed.

## Focus 3 — wire discipline (PASS)
- See scope: trio absent from commit, order-match argument holds. dormantRoster N/A —
  `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` is owned by C-WEB-3.g and does not
  exist at base (disclosed; welder must carry the `mcp.resources` dormant-set row when the
  file lands).

## Focus 4 — shadowing (PASS)
- `McpPromptCommands::mergeUnderDisk` puts MCP rows under disk; list() keys one map
  (`$items[$name]`), exec looks the same map up — list/exec identity is structural since
  `files()` retired. Pinned: `testADiskFileKeepsTheSourceOfANameItShadows` (source `file` for
  the shadowed `mcp__srv__review`) + `testTheServedMcpPromptRowIsListedWithMcpSource`.

## Focus 5 — gates reproduced (lane tree, plain pipe, cwd candy-code)
- New files: 6T/70A + 4T/19A = **10T/89A** — matches exactly.
- MCP family (McpPanelTest|McpPromptCommandsTest|McpClient): **179T/671A/1S** — exact.
- Guards (DocFigureProseDrift|GlobDialectDifferential|ReadmeRosterDrift): **114T/4414A**
  (91/3970 + 13/366 + 10/78) — exact; builder's stated filter also counted the Stdin family,
  aggregate ran green regardless.
- Protocol superset filter (199T incl. the builder's 38T set): sole failure is
  ProtocolSchemaDriftTest.php:39 — the file-vs-derived staleness leg, expected while the trio
  sits at base (my permission map refused `php scripts/gen-protocol-schema.php`; builder gated
  green against the regenerated trio per REPORT; §1.8 says reviewers do not file this red).
- Extra tree guards (DuplicatedTestHelperDrift|SwallowingCatchCensus|OneSidedHomeSandbox|
  DuplicatedDocBlockLine|SymbolCitationDrift): 34T/16972A green.
- Foreign base-reds confirmed as disclosed and lane-pure: exactly 6F
  (StderrEmitterCensus 3, TreeWideGuardRoster 2, ChatHostLangCatalogue 1); the census output
  names src/Cli/ArgvParser.php 35→36 and src/Cli/NonInteractive.php 10→11 — no lane file.

## Non-vacuity (re-reasoned; builder's mutations honest)
- M1 force source 'file': only `testTheServedMcpPromptRowIsListedWithMcpSource` reddens
  (disk rows say file either way; denied/unknown pins unaffected). Discriminating.
- M2 drop `bound()` clip: the 400-char/ellipsis/flatten/optional-null legs of
  McpMethodsTest test1 die. Discriminating.
- M3 drop the 200 slice: exactly `testThePerServerRosterIsSlicedToTheKeptRows` reddens —
  startedSnapshot derives `count(rows)` so the panel count pin stays green by design,
  which is itself the proof the int contract is now derivation-side. Honest as reported.
- The exec test is real, not fabricated: a forked child re-derives the client from the planted
  trusted `.mcp.json` stdio fixture and the fetched body (SERVED_FETCH_MARK + mapped argument)
  is asserted in host history (CommandMethodsMcpPromptsTest.php:141-157).

## FINDINGS

### MAJOR-1 — command.list became runtime-required on sessionId; the schema still says optional
`candy-code/src/Protocol/Schema/MethodSchemas.php:276` — `'command.list' => [Schema::object($sid), ...]`
with `$sid = ['sessionId' => Schema::ref(D::SESSION_ID)]` and NO required list (MethodSchemas.php:48).
The lane made `list()` call `$call->host(SessionMethods::sessionId($params))`
(CommandMethods.php:55) and `Params::string` THROWS `invalidParams` when the param is absent
(Params.php:29-34) — so the method is now session-required, while the generated published
contract (`candy-code-web/src-web/protocol/generated.ts:549-552` shows `sessionId?`, and
SERVER.md:441 lists no required params — the welder's regen will carry exactly that from the
unchanged schema) advertises it as optional. A third-party wire client built from the docs and
omitting sessionId now gets -32602. The lane demonstrably knows the house pattern:
`mcp.resources` correctly uses `$sessionOnly` (required). No in-repo caller breaks (all three
test legs + web session.ts:151 pass it) — the defect is contract truth.
Fix: `'command.list' => [Schema::object($sid, ['sessionId']), ...]` at MethodSchemas.php:276;
the trio regen at weld carries it. The test legs already pass the param — no other flip needed.

### NIT-1 — McpMethodsTest omits McpLaunchEnabledTrait used by its sibling
`candy-code/tests/Protocol/McpMethodsTest.php:41,53-68` arms only `HomeSandboxTrait` while
`CommandMethodsMcpPromptsTest.php:49,64` also arms `McpLaunchEnabledTrait` before any decision
is taken. McpMethodsTest is green today because every root it touches has no config to trust
(memo seeded directly); the asymmetry is a latent env-sensitivity, not a step defect.
out-of-scope — record.

## Gate duties to carry (not findings)
- durations.tsv rows for `CommandMethodsMcpPromptsTest.php` + `McpMethodsTest.php`.
- suite-figure re-pin +10T.
- welder regenerates the trio and (when C-WEB-3.g's file exists) adds `mcp.resources` to
  dormantRoster with reason "web caller lands in C-MCP-2.c".
- StderrEmitterCensus/TreeWideGuardRoster/ChatHostLangCatalogue 6F are foreign lineage reds —
  their owning program owes the cures.
