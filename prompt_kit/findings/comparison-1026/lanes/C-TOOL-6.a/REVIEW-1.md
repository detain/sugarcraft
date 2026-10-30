VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 3 MINOR / 2 NIT (C-TOOL-6.a, tip c79073e20, reviewed as COMMIT)

Reviewer: fresh round-1 (third spawn on this lane). Report written in the MAIN TREE
(/home/sites/sugarcraft/prompt_kit/.../lanes/C-TOOL-6.a/REVIEW-1.md) because my write map
refused the worktree path — the welder copies this file onto the branch.

## Scope/traceability (verified on disk)
- `git -C /home/sites/cc-cmp-C-TOOL-6.a diff master...HEAD --stat`: EXACTLY 2 files, +323/-0:
  `candy-code/docs/MCP.md` (+126) and NEW `candy-code/tests/Config/McpBrowserPresetDocTest.php`
  (197 lines, 4 methods). Both are the design block's files.create/files.modify — no third file,
  no SERVER.md delta, no suite-figure.json/durations.tsv/dist touch. %ae/%ce =
  detain@interserver.net; zero Co-Authored-By (git show trailer read).
- master has moved to 484f313a9 (lane base 07e49e872). Foreign uncommitted dirt in the worktree
  (` M BinCandyCodeDispatchTest.php`, `?? UsageSubcommandTest.php`) was NOT judged — it does not
  travel with a cherry-pick.

## 1. MCP.md collision — settled with BLOBS
- `git show c79073e20:.../MCP.md` = 78,570 B; `git show 07e49e872:...` = 72,946 B (+5,624);
  `git show master:...` = 74,173 B; `git show master:... | grep -c 'Browser control and computer use'`
  = **0**. The section is genuinely NOT on master (the previous reviewer's "already welded via
  C-SES-8.e, do not re-add" was FALSE; the second reviewer's advice must be discarded).
- The lane hunk anchors at `@@ -551,6` on context lines that I compared directly: master 548-556
  and base 548-556 print identically ("...completes the browser dance." / blank / `---` /
  `## Listing without starting`). Master's own MCP.md edits land at 878+ and 1196+ (C-TOOL-2
  error-fence paragraph, twelve-subcommands) — all AFTER the insertion point.
- **Welder instruction: plain `git cherry-pick c79073e20` — expected conflict-free** (no textual
  overlap; if git still conflicts, resolve additively: keep BOTH master's 878+/1196+ text and the
  new 126-line section at 551). Then regenerate nothing (MCP.md is hand-authored: no
  `tools/gen-*.php`/`scripts/*.php` names it) and re-run the doc filters.
  Landed-blob proof after the pick:
  `git show <weld-sha>:candy-code/docs/MCP.md | grep -c 'Browser control and computer use'` = 1
  and the blob size = 79,797 B (74,173 + 5,624).

## 2. Doc claims re-tested against code (lane worktree)
TRUE / load-bearing:
- Fingerprint rationale: `src/MCP/McpTrustPins.php` class docblock (:23-32: fingerprint = the
  entry after `normalizeEntry()` incl. `command`,`args`,`env`,…) + `fingerprint()` (~:156-166
  sha256 over canonicalised serialize()). So "an argv that always reads @latest never looks
  changed" is exactly right. Refusal wording quoted at `docs/MCP.md:86-89` ("the command line it
  was recorded as and the one it has now") matches the landed prose.
- Write-class consequence: `PermissionGate::isWriteTool()` at :1776 returns
  `str_starts_with($call->name,'mcp__')` unless `isReadOnlyMcpTool()` (:1751) hits; plan §cited
  :1551 has drifted to :1776 (line refs in my spec are base-era; doc names symbols, fine).
- Annotation rule: `src/Tools/McpToolBridge.php:260-263` `readOnly()` =
  `readOnlyHint() && openWorldHint() !== true`; "A name no bridge was built under is never
  read-only" = `declaredReadOnly()` + :1749 docblock ✓.
- `normalizeEntry`: `src/MCP/McpForeignTranslate.php:131` — `?array`, null ONLY for
  `enabled:false`, RuntimeException on bad shape; all three preset entries are
  `{command,args}` (no `enabled`) → pass + non-null ✓ (test 2 calls the real method).
- No-jail honesty: `sugar-mcp-client/src/StdioMcpServer.php:23-31` (argv-array proc_open, why)
  and no bwrap on that path; `Bubblewrap::fromSetting` is wired only from
  `Bash::fromCatalog()` (`docs/SETTINGS.md:243` row). "There is no jail around these children"
  and "even while your Bash calls run sandboxed" are TRUE against the code.
- Tier/precedence refs resolve: `Two tiers, one precedence` :96, `Where a stdio server runs`
  :136, `Foreign spellings that are read` :197, `Deferred schemas…` :966 (below the new section
  ✓), `Adding servers` :484 (above ✓); `permissionRules` in `~/.candy-code/config.json` is
  config.json-true: `Bootstrap::permissionConfigLayers()` (:7448/:7497) reads settings.json then
  config.json with config.json outranking; first-match-wins per `docs/PERMISSIONS.md:132`/rule
  shape :607 ✓.
- lazy caveat: user-tier + default 8000 + `0` never defers ✓
  (`HooksMcpSettings.php:115-131`, `Bootstrap::DEFAULT_LAZY_SCHEMA_TOKENS=8000`, "Zero disables
  deferral").

### MAJOR-1 (step-local) — the last caveat bullet OVERSTATES protection
`candy-code/docs/MCP.md:674-676`: "the import route … translates a Claude config in place —
`candy-code mcp import claude <file>` — and **the trust gate then applies to what lands, entry by
entry**." Contradicted by this same page (:218-238) and the importer: the verb is PRINT-ONLY
unless `--write user`, which appends into `~/.candy-code/mcp.json` — the tier the page states
starts "at every future launch … with no per-repo ceremony and no project trust pin" — and
`--write project` "exits 2 having attempted nothing" (the verb never writes a project tier).
So for BOTH the default and the only writable tier, no trust gate applies to what lands. A reader
of a browser-control section would believe pasted servers get fingerprint-gated when they do not.
CURE (prose only): replace the clause with e.g. "— but note the verb only PRINTS unless
`--write user` appends the entries, and the user tier it writes is the one the gate does NOT
pin: paste them into a project `.mcp.json` if you want the fingerprint check to cover them."
Then re-run `--filter 'McpBrowserPresetDocTest|DocFigureProseDriftTest'` (no test change; the
sentence is unpinned prose).

### MINOR-1 (step-local) — the tier blocks are only checked last-write-wins
`tests/Config/McpBrowserPresetDocTest.php:107` (`$entries[$name] = $entry;`) merges the project
and user blocks by server name, and test 1's version regexes are unanchored. A future edit that
loosens the FIRST block (e.g. `@playwright/mcp@latest` in the project `.mcp.json`) keeps all 4
tests GREEN: the merged entry comes from the exact-pinned second block and the dated-regex still
hits. CURE: inside the merge loop assert per-name entry equality across blocks, e.g.
`self::assertSame($entries[$name] ?? $entry, $entry, 'the two tier blocks disagree about "'. $name .'");`
(one line; also strengthens the "shown twice, once per tier" contract).

### MINOR-2 (step-local) — annotation condition misworded vs code and vs its own page
`docs/MCP.md:649`: "…`annotations.readOnlyHint` set and `openWorldHint` not set…" — code is
`openWorldHint() !== true`, so a server that explicitly sends `openWorldHint: false` IS treated
read-only; the page's own wording elsewhere is "(and not `openWorldHint: true`)" (:941,
`docs/PERMISSIONS.md:525`, README:2568). CURE: "…set and `openWorldHint` not `true`…" (one word;
safe direction, so MINOR).

### MINOR-3 (step-local) — design-prescribed forward pointer dropped
Design block (design-tool.md:735) prescribes the no-jail caveat "…outside the Bash jail (until
C-TOOL-4.e)". The landed bullet says only "Until a server-side jail exists". CURE: add the
`C-TOOL-4.e` name (the page already carries internal step IDs such as "(C-MCP-1)" at :136 and
"(E710)" in the importer, so the vocabulary is consistent here) — or record the deliberate drop
in closeout.

## 3. Version-pinning honesty
Doc is PINNED and DATED: "read from the package registries on 2026-10-30", with
`@playwright/mcp@0.0.83`, `chrome-devtools-mcp@1.12.0`, `windows-mcp==0.8.8` in every
copy-pasteable block; `@latest` appears ONLY as the named anti-pattern in prose; no floating tag
or bare name in any block. windows-mcp's Windows-only scope and Python 3.14+ are stated plainly,
and the section explicitly refuses to pin a Linux/macOS computer-use server (design-sanctioned
fallback). Registry NUMBERS and the `--isolated` flag semantics cannot be verified offline — I
did NOT attempt network; the doc's own claim discipline (pin + date + "needs a re-date when it is
re-read") is what a reviewer can judge, and it is honest. The rejected npm `'taste'` candidate is
not taught anywhere ✓.

## 4. Are the 4 tests load-bearing? (line-reasoned; NOT executed — my write map refused the
worktree path, so no mutation files were written; I reproduced the gates only)
- Heading rename/deletion → all 4 red (`browserSectionBody()` asserts the byte-exact heading at
  :56-61 before any extraction). Non-vacuous by construction: the tests read `docs/MCP.md` from
  disk and call the real `normalizeEntry` — no comment can satisfy them.
- `@latest` written into BOTH blocks → test 1 (pin regex) + test 3 (grammar) = 2 red, matching the
  builder's claim. Into ONLY the first block → GREEN today (that is MINOR-1).
- Dropping the pinned version (bare `chrome-devtools-mcp`) → test 3 red; dropping `==` for `@`
  on the uvx entry → test 3 red (grammar per launcher).
- Misspelling a wire rule (`mcp_playwright_x`) → test 4 only = 1 red ✓.
- Adding `"enabled": false` to a preset → test 2 red (assertIsArray on the null) ✓.
- test 2 also fails if the loader's vocabulary tightens (real coupling, not fixture coupling) ✓.
Builder's md5-restore claims: not reproducible from the commit; immaterial to the verdict.

## 5. Placement deviation — accepted, verified
Design said "after `### Adding servers`, before `## Listing without starting`". Landed: after the
chapter-closing `---` (:551-553), still before `## Listing without starting` (:680) — i.e. inside
the design's stated window. `DocFigureProseDriftTest` slices `### Adding servers` → first `\n---`
and asserts EXACTLY two recipe fences (:6385-6399, read on the lane tree); inside the rule the
section's fences would make 5 and redden a guard this lane does not own. The deviation is
guard-forced, disclosed in both REPORT and commit message, and the guard passes unmodified. Not a
finding.

## 6. Gates reproduced (targeted only, cwd=candy-code, env -u LINES -u COLUMNS plain pipe)
- `--filter McpBrowserPresetDocTest` → "Tests: 4, Assertions: 89, PHPUnit Deprecations: 2." OK
- `--filter 'DocFigureProseDriftTest|McpBrowserPresetDocTest'` → "Tests: 96, Assertions: 4227" OK
- doc-guard sweep (DocFigure|ReadmeRoster|EnvRoster|TrustKey|GlobDialect|DuplicatedTestHelper|
  McpStartupOrdering|McpBrowserPreset) → "Tests: 193, Assertions: 45778, PHPUnit Deprecations: 2." OK
- `--filter McpForeignTranslateTest` → "Tests: 16, Assertions: 35" OK
- `--filter 'McpToolBridgeWireNameTest|McpToolShapeTest|McpWriteBudgetShapeTest'` → "Tests: 28,
  Assertions: 81" OK
No PtyRunToolTest filter was used anywhere. The 2 deprecations are PROCESS-GLOBAL, not lane-introduced:
the untouched `McpStartupOrderingDocDriftTest` (4T/17A) and the untouched `McpForeignTranslateTest`
both report exactly 2. Builder's claim verified. `McpConfigToleranceTest` core-dump at base —
standing per brief; not chased, not doc-reachable.

## §4 Weld carries
1. MINT `scripts/parallel-tests-durations.tsv` row for
   `candy-code/tests/Config/McpBrowserPresetDocTest.php` (lane-forbidden file; 4 methods).
2. Figure delta: the file contributes **4T/89A** observed. The lead's quoted "~+1T/+52A" does NOT
   match the lane; the K=24 battery + `refresh-suite-figure.php` at weld is authoritative (no
   provider-driven derived rows expected — no new src/tool/env/setting, so no roster census gains
   a row; standing ReadmeSuiteFigureDrift staleness pair is the expected only-red pre-pin).
3. Fix MAJOR-1 (+ ideally MINOR-1/2/3) before/with the pick, then re-run filters 1-2 of §6.
4. Closeout ledger: the C-TOOL-6.a row flips only after the landed-blob proof in §1; disclose in
   COMPARISON/plan ledger that the import-tier sentence was cured in-weld (it is a security claim).
5. No gen-docs/gen-tool-docs regen owed (MCP.md not generated; no counted figure added — the
   section carries no rostered numeral, verified by the 193T sweep green without regen).
