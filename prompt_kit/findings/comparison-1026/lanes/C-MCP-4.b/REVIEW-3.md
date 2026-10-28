# C-MCP-4.b — Round 3 review (tip 1fbbaddc0)

- Reviewer: independent r3 lane, worktree `/home/sites/cc-cmp-C-MCP-4.b`, branch `cc/C-MCP-4.b`.
- Tip reviewed: `1fbbaddc0664acf6c98600f0c4ec2845fe63b6d0` (base `e4a705244`, merge-base with local master `15dc9c53e`).
- Prior rounds: REVIEW-1 (r1), REVIEW-2.md at `/tmp/opencode/cc-cmp-lanes/C-MCP-4.b/` (r2, tip `2cfb83e04`).
- Verdict: **FINDINGS — 1 MINOR (step-local), 0 CRITICAL, 0 MAJOR.** All three r2 findings cured; the r2 cure for the lang wording shipped one false clause (detail below). Everything else reproduces.

## 1. Phantom-first — ALL PASS

- `git branch --show-current` = `cc/C-MCP-4.b`; `git cat-file -t 1fbbaddc0…` = commit.
- `git rev-list master..HEAD` = 1 commit. `git status --porcelain` = 0.
- `%ae` and `%ce` verified with separate greps = `detain@interserver.net` (count 1 each); zero `Co-authored`/other trailers.
- `git diff --stat master...HEAD` = 12 files, +892/−21. The brief's header figure (+784/−27) is the pre-cure 11-file r2 shape — bookkeeping drift, not a lane finding.
- `git diff 2cfb83e04 1fbbaddc0` = exactly the cure hunks: `lang/en.php` (two sentences: disable.done :1303, enable.done :1311) + `tests/Config/Settings/SettingsWriterCensusTest.php` (+8: roster row + adjudication comment). Nothing else moved.
- Cure tokens grep-verified in the `git show 1fbbaddc0` blobs: the census row `'Commands/McpAuthCommand.php::persistSessionToggle' => 3`, both new lang sentences.

## 2. r2 finding → cure verification

### r2-MAJOR (census row) — CURED, gate green NOW
- Row present in `SettingsWriterCensusTest::EXPECTED_USER_CONFIG_WRITERS` with the adjudication comment (three write shapes: kept-list rewrite / emptied-key unset `writeUserConfig([], [$key])` / append).
- Source count verified: exactly 3 `writeUserConfig` call sites in `persistSessionToggle` (`src/Commands/McpAuthCommand.php:366, 368, 386`); `:284`/`:343` are docblock mentions (token census is code-only). Bootstrap.php untouched by the lane (static calls only) — matches design.
- Gate re-run at tip: `--filter SettingsWriterCensusTest` → `Tests: 5, Assertions: 4746` OK (2 deprecations = tree-wide foreign, §5).
- Non-vacuity: the pin asserts `EXPECTED_USER_CONFIG_WRITERS === callSites('writeUserConfig','::')` as exact array equality keyed `file::method`, so removing/altering the row or a call-site count reddens by construction. The cp-harness swap-mutation is denied to my permission map (same as r2: "File-swap base repro blocked by perms"); however r2 empirically witnessed the missing-row red at `2cfb83e04`, and the structural equality proves the cure is load-bearing. Mutation-reason: row removed → 1F. Accepted.

### r2-MINOR lang wording — CURED, with one residual false clause (MY FINDING, §3)
### r2-MINOR REPORT-truth — CURED
- `lanes/C-MCP-4.b/REPORT.md` (main tree) corrected: the false "C-CTX-4.c capture-cure" claim retracted; the 1F is now correctly named as the foreign `InstructionWalkPastRootTest` (OneSidedHomeSandboxTest roster family). Honest and matching my independent observation (that 1F never appears in my filters; it is a base-tree foreign red the weld owns).

## 3. FINDING — MINOR (step-local): `candy-code/lang/en.php:1303` disable.done preset clause is false on production code, contradicting the same commit's own docs

New text: `… while a preset-attached agent stops seeing them from that same turn.`
That contrast (preset agent stops SEEING the tools immediately) is not true of any production surface:

1. **`mcp_list` never consults the session set.** `McpListTool.php:30` ("answers from THESE descriptors, never from a fresh `listTools()`"); the preset narrowing `McpToolShape::scopedTo()` (`src/MCP/McpToolShape.php:141-158`) filters `dispatchedDescriptors` ONLY by `LibraryMcpRouter::serverAllowed($name, $mcpServers)` — the preset allowlist — never `sessionDisabled`. The eager/bridge half "passes through unchanged: … the shape is a launch fact" (its own docblock, :130-134).
2. **`resolveAllowedTools` has no production reachability for this.** Its only caller is `McpClient::listTools()`'s preset branch (`src/MCP/McpClient.php:1069`), and the only production `$client->listTools()` call is the launch memo at `src/Cli/Bootstrap.php:8757` on the unrestricted client (preset branch unreachable there). Grep over `src/ bin/` shows no other callers (`McpServer.php:20-26` says "clients should call" it — no production one does).
3. **The refusal is call-time, everywhere.** The scoped client shallow-clones the disabled map, so a preset agent's CALLS are refused from the next fork (callTool ladder `:1117/:1255/:1322`, `callToolByName`, `isServerAllowed` front guard) — listing stands until restart, exactly like the unrestricted arm.
4. **Same-commit self-contradiction.** `docs/MCP.md` (this lane's own edit): "The model-facing tool roster is not re-derived (the same cache law governs reconnect): a disabled server's tools stay listed… the toggle takes effect from the NEXT turn, never one already running" — no preset exception. The commit message itself says "the command promises the next turn", and `McpClient::forAgentPreset()`'s docblock carries the same next-turn law.

Cure (one line, no test flips — no test byte-pins `cmd.mcp-auth.disable.done`; the runtime pins assert the call-refusal hint, not this sentence): drop the false preset contrast, e.g. `✓ Disabled {server} for this session — calls are refused from the next turn; the model-facing roster is not re-derived (cache law), so its tools stay listed until restart.` Aligns the string with MCP.md, the commit message, and every pinned behavior.

Lineage note (fairness to the fixer): r2's MINOR-1 itself rested on the "router strip removes from resolveAllowedTools at next-turn fork" premise — a production-dead branch. The original r1 wording ("its tools stay listed until restart") was actually TRUE; the fixer complied with a mistaken reviewer instruction and shipped a strictly-stronger false claim. The enable.done cure IS truthful ("routed again only where launch routing already allows the name" covers deny-pattern + never-started arms — verified against the `isServerAllowed` ladder).

## 4. Trust-law duties re-checked at tip (all PASS)

- **Denied/untrusted stay absent from all surfaces** — unchanged lane behavior (launch gates own this; session layer only ADDS refusals).
- **enable NEVER starts or stores anything** — `sessionToggle` (`McpAuthCommand.php:315-337`): gate on `configEntries()` for the name, then `enableForSession()` = single `unset` in the memo (`McpClient.php:294-297`); the `not-armed` arm reports honestly; no client is minted, no reconnect reached.
- **Every enable leg re-validates the routing laws** — structural: enable only removes the session layer; each subsequent call re-walks deny-patterns + preset `serverAllowed` + started/trust state at the ladder, so no bypass window. `--persist` is USER-tier `disabledMcpServers` only (trusted by definition), exact names, glob metachars refused via runtime-built `chr(42)…` set (GlobDialect law honored), emptied list unsets the key.
- **§6.1 R13 duty: N/A this lane** — design block carries `ruling: none`; the toggle adds zero network/exec/spawn surface (diff introduces no `proc_open`/fork — full hunks read), Bootstrap.php untouched. The R13 line in plan §6 covers gh/bot/TLS/QR/push surfaces, none here.
- **disable is a memo peek, no launch** — confirmed: the command arm never calls the reconnector pair.
- **McpClient cache law vs spawn-duty phrasing** — the spawn brief's "disabled server's client dies / re-enable doesn't resurrect" conflicts with the LANE BRIEF + design block, which are the authority and prescribe keep-alive refusal-only ("The tool roster is unchanged (cache law): calls are refused"). Implementation + tests (roster-unchanged pin; fork-inheritance divergence pin) match the brief. r2 §6.1 already PASSed this; noted, not a finding.
- **prompt-bearing rows still fork-only (C-SEC-3 posture)** — prompt/readResource refusals added beside callTool; elicitation/roots untouched; snapshot rows carry `disabled: true` beside real `up`/`tools` (routing fact, not zeroing).
- Conventions: `declare(strict_types=1)`, final classes, bare accessors, `Lang::t()` for all new user strings (21 keys), comments say why. No stubs/TODOs/fabricated paths.

## 5. Gates reproduced (plain pipe, cwd `candy-code`, env -u LINES -u COLUMNS; PtyRun never in filter)

| Gate | Result |
|---|---|
| new pair `McpAuthCommandEnableDisableTest\|McpRuntimeDisableTest` | **12T / 53A** OK — exact vs fixer |
| `DocFigureProseDriftTest` | **91T / 4027A** OK |
| `SettingsWriterCensusTest` | **5T / 4746A** OK |
| `McpPanelTest` | 22T / 137A OK |
| `McpAuthLoginGuidanceTest` (byte-pin flip file) | 3T / 12A OK |
| family superset `…Tests\\(MCP\|Commands)\\` | 1757T / 11490A, **1F = MenuBarLabelDerivationTest.php:90 ('pr') FOREIGN** + 1S — exact vs fixer/r2 |
| lang-catalogue combo | 22T / 1065A, 1F = ChatHostLangCatalogueTest:90 (`chat.attach.pdf_pages`, `chat.effort.launch_unshaped`) — FOREIGN: lane diff contains 0 occurrences, keys pre-existing at lang/en.php:1938 |

2 "PHPUnit Deprecations" on every run = tree-wide foreign `AccessorDeprecationTest` private data providers (pre-existing; not lane files). Foreign list confirmed for the weld (report-only): suite-figure staleness pair (weld re-pin), `InstructionWalkPastRootTest` 1F (base-tree, named in corrected REPORT), MenuBarLabel 'pr', ChatHostLangCatalogue seam, ProjectTier set, DreamPass.

## 6. Traceability

12 files = the 11 r2 reviewed as "exactly declared" + `tests/Config/Settings/SettingsWriterCensusTest.php` — an in-step guard-law move (§1.8: the census row travels in the commit that adds the write sites; correct). No file outside the brief's declaration.

## 7. Weld duties confirmed

- durations.tsv rows owed at weld: 2 new test files (`tests/MCP/McpRuntimeDisableTest.php`, `tests/Commands/McpAuthCommandEnableDisableTest.php`) — per-file junit sums; neither file appears in the lane diff of `scripts/parallel-tests-durations.tsv` or `suite-figure.json` (correctly untouched).
- trio regen NOT owed: `git diff master...HEAD --name-only | grep -c 'src/Protocol/'` = 0; gen-protocol chain unaffected.
- REVIEW-2.md currently lives at `/tmp/opencode/cc-cmp-lanes/C-MCP-4.b/` — welder must collect it (and this REVIEW-3.md) into `prompt_kit/findings/comparison-1026/lanes/C-MCP-4.b/`.

## 8. Verdict

FINDINGS — 1 MINOR step-local (lang/en.php:1303 preset clause false; one-line cure, no test flips). r2 MAJOR fully cured and gate-green at tip; r2 MINORs cured (REPORT honest; enable.done honest). Semantics, trust law, persist door (exactly 3 rostered sites), cache-law posture, conventions, and all quoted gates PASS. Ready for weld contingent on the one-string cure (or welder carries it as a weld-minor, precedent WM1-4).
