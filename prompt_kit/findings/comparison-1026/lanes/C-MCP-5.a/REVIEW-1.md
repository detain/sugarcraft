# C-MCP-5.a — REVIEW-1 (round-1 reviewer, fresh, disk-derived)

VERDICT: FINDINGS
C=0 M=1 MINOR=2 NIT=2
Lane tip `1c8166eab` on `cc/C-MCP-5.a`, base `288b81f11`, porcelain 0. Every claim below was re-derived from disk; nothing carried from prior reviews or lead briefings.
Header stat'd at 275 bytes before sections were appended.

## Q1 — read-only guarantee: ENFORCED, structurally (not just advertised)
- `tools/list` is fed ONLY by the roster map: `McpServeHost.php:81-84` (ctor keys the passed list), `:217` (`toolsList()` iterates `$this->tools` — no other source), and the roster itself is a constant pick: `McpServeRoster.php:51` (`const NAMES = ['Read','Glob','Grep','Ls','RepoMap']`), `:103` (`foreach (self::NAMES ...)`) over the single `ToolCatalog::build()` output at `:98`. Nothing merges catalog results, presets, or an enabled-filter anywhere on this path — `ToolCatalog::build` (`ToolCatalog.php:200-213`) is consulted once and then name-selected.
- `tools/call` name-resolves ONLY inside that map: `McpServeHost.php:244` (`$this->tools[$name] ?? null`), unknown → isError result `:251`. A `Bash` call therefore dies at the roster wall before any hook runs — pinned by `testBashIsUnknownOnTheServeSurface`.
- Double lock: `McpServeRoster.php:104-110` throws if any rostered name stops classifying `ToolPermissionClass::Read` (`ToolCatalog::permissionOf`, `ToolCatalog.php:184-193`; null → throw). `Bash` is `ToolPermissionClass::Write` (`src/Tools/BuiltIn/Bash.php:51`), so even adding it to NAMES would fail loud. The fixer's disk reading is CONFIRMED.
- No mutating tool is reachable via name-resolved `tools/call` on the shipped path. Not CRITICAL. (Observation for 5.b: `run()` accepts any `list<Tool>` — the Subcommands wiring MUST pass `McpServeRoster::build()` output; see NIT-2.)

## Q2 — reachability: DORMANT-BY-DESIGN, plan-sanctioned
- Grep across `src/` and `bin/`: zero references to `McpServeHost|McpServeRoster` outside `src/MCP/Serve/` — confirmed on disk (the wiring grep returned empty).
- The design block is explicit: `design-mcp.md:349` `files.modify: none` for 5.a, and `design-mcp.md:368` (C-MCP-5.b) owns the verb gate — "`candy-code/src/Cli/Subcommands.php:1952-1970` routes `serve` to `McpServeHost::run()`" with deps line `design-mcp.md:378` `deps: C-MCP-5.a`. Dormant here is legitimate; NOT this step's MAJOR.
- Docs coherence: `docs/MCP.md` §Serving MCP is untouched by this lane and still says serve speaks no MCP — truthful for the current (unwired) state. No half-moved promise.

## Q3 — permission chain: DENY pinned behaviourally; ASK→refuse UNPINNED → MAJOR-1
- DENY: `testReadOfAnEnvFileRefusesThroughTheProtectFilesHook` is behavioural — real `registerBuiltIns()` chain, real `ProtectFilesHook`, asserts `isError:true`, `Hook denied: ` prefix (`DenialKind.php:125`, `reason()` `:205-208`), names `.env`, and negatively pins that the refused secret's content is absent. Removing the `permitsExecution()` arm (`McpServeHost.php:266-277`) reddens it. PASS.
- MODIFY/ASK arm logic on disk is correct: `permitsExecution()` (`HookResult.php:500-503`) is an ALLOW/MODIFY whitelist, so ASK fails closed and renders `DenialKind::Unanswered` = `Permission required:` (`McpServeHost.php:271`).
- **MAJOR-1**: NO test in `McpServeHostTest.php` (14 methods enumerated) ever produces an ASK verdict. Mutation reason: change `McpServeHost.php:266` to `if ($verdict->isDenied())` → an unanswered ASK proceeds to `$tool->execute()` (silent widening the §6 ruling explicitly forbids) and the ENTIRE suite stays green — no test registers an ask-returning hook (ScriptHook exit-2 or a gate Ask rule). That is the "pin survives removing its own fix" class. EXACT FIX: add a test registering a tiny fake hook (HookRegistry-acceptable) whose PreToolUse result is ASK, drive one `tools/call`, assert `isError:true` + text starts `Permission required: ` + the tool demonstrably did not execute (e.g. counter or absent side evidence); prove red with the `isDenied()` mutation.
- NIT-1: the `-32603` keep-serving arm (`:189-194`) and the `isModified()` re-write arm (`:278-283`) are also unexercised (design DoD's seven tests don't name them; class docblock advertises them). One added pin each (a throwing hook → -32603 answer + next line still answered; a MODIFY hook → rewritten args reach execute) would close both. step-local.

## Q4 — path jail: refusal comes from the tool's own jail
- `../`-style escape: `testParentDirectoryEscapeIsRefusedByTheToolJail` reads a REAL file placed outside the jail and asserts both `isError` and content-absence — not a fixture assumption.
- Enforcement site: `Read.php:26,157` uses `SugarCraft\Code\Tools<PathJail`; `PathJail.php:100-113` jails via `realpath($root)` vs `realpath($fullPath)` — a symlink pointing outside resolves to a non-contained real path and is refused by construction. `Glob`/`Grep`/`Ls` ride the same jail through the catalog build. No lane-local symlink pin exists, but that jail is shared production code covered by candy-code's own PathJail suite; not a 5.a finding.

## Q5 — NDJSON wire integrity: VERIFIED (one unexercised defensive arm, NIT-1 above)
- `parsePreservingId` (`sugar-mcp-client/src/McpMessage.php:156-176`): null on undecodable/non-object/list id-type/nesting over MAX_DEPTH; `parse()` returns null for non-`2.0` (`:88`) → host maps valid-JSON-but-not-a-message to -32600 and non-JSON to -32700 via `json_validate` (`McpServeHost.php:150-153`), null id — pinned (`testMalformedAndNonMessageLinesAnswerProtocolErrorsWithNullId`).
- Integer id echo: `emit()` (`:305`) re-stamps wire type; pinned `assertSame(7, ...)`; notifications and response lines stay silent — both pinned. `-32601`: pinned via `session/prompt`. EOF → exit 0 with exactly one frame: pinned (`testRunDrainsStdinAndExitsZeroAtEof`).
- Stderr: both new src files contain ZERO emitters (only `fwrite($stdout)` at `:122`; the single "echo" hit is prose in a docblock `:160`); `StderrEmitterCensusTest` green in my run — no roster row needed, correctly (census counts real emitters, not files).

## Q6 — §1.8 guard law: doc surface moved; intermediate-commit nuance → MINOR-2
- The HOOKS.md pick is guard-FOUNDED, verified: `DocFigureProseDriftTest::testHookEventsTableSurvivesTheLiveEnumAndDispatchSites` (`tests/Config/DocFigureProseDriftTest.php:2315`) re-derives the PreToolUse "Dispatched-from" cell from every `->preToolUse(` site in src/ (excluding `src/Hooks/`) and asserts exact set equality (`:2378`). The new host adds the third site, so the row is MANDATORY; the design's `files.modify: none` could not be honored literally (design note for the lead).
- **MINOR-2 (step-local)**: the flip rides pick 2 (`1c8166eab`), so intermediate `be7c88bd0` is DocFigure-RED in isolation (cited 2 vs derived 3). Tip is green — I ran the arm. Bisect hygiene, not a gate break.
- Roster checks: `ReadPathCensusTest` walks `file_get_contents|file|fopen|readfile|scandir|glob|opendir` (`tests/Support/ReadPathCensusTest.php:77`) — neither new src file calls any (host `fgets` is untracked; resources are injected) → no row needed. `TreeWideGuardRosterTest` reds belong to the FOREIGN offender only (see Q7). `SettingsWriterCensus`/`ConfigWriteProducer`: no config door added. `check-one-type-per-file`: one class per new file (full reads; direct `php tools/...` invocation is shell-denied here — verified statically). Cascade: this step needs NONE of 5.b's 14-entry ParsedArgs/Help/lang/MCP.md/README cascade — it emits zero user-facing strings and touches zero verbs; correctly left whole for C-MCP-5.b.

## Q7 — scope discipline + §7 list
- `git show --stat be7c88bd0`: exactly the 3 `files.create` paths, +690, no modifications. `git show --stat 1c8166eab`: `candy-code/docs/HOOKS.md` 7+/5− only (guard-mandated, region = dispatch table :256 + notes :594; disjoint from C-MCP-1.b's §matcher-delimiters claim in the same file — no serialized-claim collision since 1.b is already welded). porcelain 0.
- UNTOUCHED (verified via the union of both picks — no other path appears): `tests/Config/Support/suite-figure.json`, `scripts/parallel-tests-durations.tsv`, `candy-code-web/dist`, protocol trio + `docs/SERVER.md` + `generated.ts`, `plan_comparison.md`. Author `%ae`/`%ce` detain, zero Co-Authored trailers.
- New `*Test.php` for the weld: `candy-code/tests/MCP/Serve/McpServeHostTest.php` — my own measurement **14T / 77A** (matches the fixer's claim and the builder's REPORT). Needs a durations.tsv row at weld.

## Gates (targeted only, cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'McpServeHostTest|DocFigureProseDriftTest|ReadPathCensusTest|StderrEmitterCensusTest|DenialPrefixRosterTest|GlobDialectDifferentialTest'` → **250 tests, 28,413 assertions, OK** (2 pre-existing-style PHPUnit deprecations).
- `--filter 'McpServeHostTest'` → **14T/77A OK**.
- `--filter 'TreeWideGuardRosterTest'` → 17T/3846A **2F, both accusing ONLY `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')`** — the accepted foreign offender (welded C-TUI-5.b `117c948bb`); no Serve-path file named. Not this lane's.
- `--filter 'DuplicatedTestHelperDriftTest'` → 14T/202A **2F = exactly the accepted foreign pair** (`titleBackend` Chat trio; `ResumeCostEstimateTest 1_700_000_000` vs `AwayTrackerTest 1_800_000_000.0` T0 const). Not this lane's.
- No full suite run; web untouched → no vitest. Base worktree unnecessary (both red sets name foreign files in the diff output itself); none created, nothing pruned.

## Disposition
MAJOR-1 (ASK pin) needs one fixer pick on this branch; MINOR-2 + NIT-1/2 are fix-now-optional. Wire/roster/jail/deny/scope all genuinely shipped — the step's core claims hold against disk.
