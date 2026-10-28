# REVIEW-1 — lane C-MCP-4.b (reviewer: static+targeted, read-only)

Reviewed at /home/sites/cc-cmp-C-MCP-4.b, branch cc/C-MCP-4.b.

## Verdict

VERDICT: FINDINGS — 1 CRITICAL (phantom commit + mismatched claims; work real but UNCOMMITTED).

## Phantom-first results (spawn duties)

- `git cat-file -t 5007d039a` → **fatal: Not a valid object name**. The claimed commit does not exist anywhere (loose object dir 50/ has no 07d039a entry; branch reflog has only "branch: Created from e4a705244", no commit entry).
- `.git/refs/heads/cc/C-MCP-4.b` = `e4a705244…` = the BASE. `rev-list master..HEAD` on the lane = 0 commits (sibling refs at same sha confirm base is shared stack head).
- `git status --porcelain` = 11 paths, NOT 0: the entire deliverable is uncommitted working-tree state.
- Author/trailer greps: vacuous — no commit exists.
- `lanes/C-MCP-4.b/REPORT.md` — **absent** (only BRIEF.md on disk; spawn cited a report that was never written).

## Claims-vs-disk mismatch

The spawn's builder-claims describe an MCP *prompt-mention* feature (`@mcp:/server/prompt` parse, Chat submit arm, palette rows, `cmd.palette.mcp`/`host.mcp_mention.*`, ContextMentionsMcpTest.php, PaletteMcpPromptRowsTest.php). NONE of that exists on disk: lang/en.php has no `cmd.palette.mcp`/`mcp_mention`; the two claimed test files don't exist; ContextMentions.php carries only the base's `@mcp:<server>:<uri>` resource form (pre-existing, MCP-1 era).

The on-disk BRIEF.md (the actual spec) is instead "Runtime `/mcp enable|disable <server> [--persist]`" — and the uncommitted diff matches THAT, file-for-file: 9 modified + 2 new tests, exactly the BRIEF's declared file set (McpClient.php, McpAuthCommand.php, McpPanel.php, lang/en.php, docs/MCP.md, docs/COMMANDS.md, DocFigureProseDriftTest, McpPanelTest, McpAuthLoginGuidanceTest + McpRuntimeDisableTest, McpAuthCommandEnableDisableTest). No out-of-scope files. The spawn's claims block belongs to a different item and must be corrected by the orchestrator.

## Substance review of the actual (uncommitted) work — quality: good

- McpClient: `$sessionDisabled` exact-name map; `disableForSession`/`enableForSession`/`sessionDisabledServers`; refusal checked first in `isServerAllowed()`, at the `callTool`/`readResource`/`prompt` named ladder, AND on the `callToolByName` direct-transport arm (no leak by name); `router()` filters exact names out of the server map (never appended to fnmatch `$denyPatterns` — correct glob-vocabulary separation); `startedSnapshot()` rows gain `disabled: true` beside live `up`/`tools` (routing, not teardown). Clone semantics: `forAgentPreset()` shallow-clones → fork-time inheritance, "next turn" law; behavior matches BRIEF §Approach and §Risks.
- McpAuthCommand: `enable`/`disable` match arms; usage/no-name/`--persist`-as-name refusal; unknown-not-declared refusal BEFORE the persist leg; no-client quiet row; `--persist` writes exactly one name via `Bootstrap::writeUserConfig` (correct `([$k=>$list])` / `([], [$k])` unset-empty shape, signature verified at Bootstrap.php:4852); glob-metacharacter names arm in-session but are refused persistence (chr(42)… built at runtime per GlobDialect law — good); no spawn/stop of servers anywhere (posture preserved).
- McpPanel: ` · disabled` overlay REPLACES the transport suffix, checked before `up`/auth legs; empty-liveness byte-identity preserved.
- Guards in-step and MEANINGFUL (not cosmetic): DocFigureProseDrift arms flipped five→seven with the match-arm order pin now deriving `[…,'enable','disable']` from `execute()` body + fence + `cmd.mcp-auth.unknown` catalogue string, liveness-vocabulary pin updated — bidirectional. Commands surface: COMMANDS.md row regenerated consistent with hint/description Lang flow from builtin-commands/0900-mcp.php (Lang-keyed, `[commands]` exemption honest per BRIEF).
- Tests (12 new, read in full — genuine, no fabrication): real stdio child fixtures; cache-law pin (roster stands, call refused, exact refusal message); beta-untouched routing; fork inheritance BOTH directions (post-fork arm doesn't reach fork; post-fork lift doesn't heal fork); idempotency; enable never rescues launch-deny; snapshot flag presence/absence; persist exact-one-write, repeat-kept, sibling-rides, emptied-key-unset, glob-refusal; usage/unknown/no-client rows. Panel overlay + negative-polarity pair.
- Conventions: strict_types, final, PSR-4, bare accessors, Lang::t for user rows; model-facing refusal is bare English in McpClient (consistent with its neighbours "Unknown MCP server:"). E646-clean (fixture `startTimeout` is the house stdio-handshake arg; no LLM/request timeouts). No protocol/dist/proc_open/durations/suite-figure touches. en-only lang: acceptable — ChatHostLangCatalogue is on the standing-red list; 11-locale parity is the LAST wave per §6.3.

## Gate reproductions (plain pipe, cwd = worktree candy-code, PHP 8.3.6 / PHPUnit 10.5.66)

- New pair: `OK … Tests: 12, Assertions: 53` (spawn's "20T/168A" belongs to the phantom feature).
- McpPanel+Guidance family: `OK … Tests: 37, Assertions: 202`.
- DocFigureProseDriftTest: `OK … Tests: 91, Assertions: 4027`.
- ReadmeRosterDrift passes; ReadmeSuiteFigureDrift staleness pair red at 25,537 vs 27,077 live — expected weld re-pin (lane adds +12T), listed standing.
- FOREIGN, NOT attributable to this lane: PaletteSupersetDispatchTest::testThePaletteIsTheSuperset… deterministic red (survivors roster stale vs base registry: missing `pr`/`shells` positions, surplus `sendto`/`play`) — present at base, zero lane files touch the registry; disclose for the wave's ledger.
- Non-vacuity re-reasoned from code: deleting the callTool guard reddens ≥3 legs of McpRuntimeDisableTest; deleting the persist-glob gate reddens testPersistRefusesAGlobShapedName… (live mutation impossible under READ-ONLY duty; claims M1/M2→"1F" were for the phantom feature — actual discrimination is multi-red).

## Findings

- CRITICAL (lane C-MCP-4.b): claimed commit 5007d039a is a phantom; branch == base; all work uncommitted; REPORT.md never written; spawn claims describe a different item. Fix: orchestrator dispatches a disk-only finisher to commit the existing 11-path file set (author-law, message `C-MCP-4.b: /mcp enable|disable …`), mint the 2 durations.tsv rows at weld, then re-review. Do NOT resume/repair a session whose closeout fabricated the SHA; the working tree is the truth.
- NIT: BRIEF names the accessor `sessionDisabled()`, shipped as `sessionDisabledServers()` — fine (bare-accessor law), record the deviation in the commit body.
- NIT: `router()` filtering shrinks the PRESET-arm tool roster for session-disabled servers (unrestricted arm keeps listing — cache law). Coherent, but the "roster unchanged" prose in sessionToggle is only literally true of the launch/main-chat view.
