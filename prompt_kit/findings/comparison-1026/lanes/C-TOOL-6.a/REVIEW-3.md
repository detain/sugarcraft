VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (C-TOOL-6.a, round 3, tip 5a42eb62207d0cacbe99b1ea684883d90f46b07d, reviewed as COMMITS; own 4T/101A, sweep 194T/45792A all OK)

Report written in the MAIN TREE (worktree write path refused, same as r1) — welder copies this file onto the branch.

## 1. All four r1 findings CURED on the landed blobs (re-derived, not taken on faith)
Against `git show 5a42eb622:candy-code/docs/MCP.md` (78,833 B on disk at /tmp/opencode/mcp-lane-blob.md):
- MAJOR-1: `grep -c 'trust gate then applies'` = 0. Replacement (:674-679) says the verb PRINTS
  unless `--write user` appends, names that user tier "the one the trust gate does NOT pin", and
  points to the project `.mcp.json` as "the tier `mcp trust` records". Truthful on all three legs:
  the page's own importer section (:224-237: print-only E710, `--write user` the one exception,
  any other tier "exits 2 having attempted nothing", "no per-repo ceremony and no project trust
  pin") + `src/Cli/McpDecision.php` `trustProjectMcp()` :363/:395-396 fingerprinting the PROJECT
  mcpServers via `McpTrustPins::pinsFor` into mcp-trust.json + `Bootstrap.php` :8235-8238 (user
  tier: "the HomeDirectory::owned() check IS the gate; there is no second ceremony").
- MINOR-2: docs :649 now "`openWorldHint` not `true`" == `src/Tools/McpToolBridge.php:261`
  `openWorldHint() !== true` (read on the lane tree). ✓
- MINOR-3: `grep -c 'C-TOOL-4.e'` = 1 (:644, design's forward pointer restored). ✓
- MINOR-1: `tests/Config/McpBrowserPresetDocTest.php:111` adds per-name
  `assertSame($entries[$name] ?? $entry, $entry, …)` inside the merge loop. Mutation-reasoned:
  first sighting self-compares (trivially true); second block compares against block one's entry,
  so a loosening in EITHER tier reddens (block-1 `@latest` + block-2 pinned = mismatch → tests 2
  and 3, the only callers of browserPresetEntries(), fail naming 'playwright'). This closes the
  exact last-write-wins masking r1 flagged; +12A decomposition (3 names × 2 blocks × 2 tests)
  matches 89A→101A exactly. The assert is a runtime JSON comparison on disk-read docs — comment-
  unsatisfiable, non-vacuous by construction (heading byte-pin + assertNotFalse extraction gate).

## 2. Whole +130 section doc-truth sweep — clean
Preset blocks carry only {command,args}; section grep for `env` = 0 (builder's old REPORT env
claim correctly erratumed in-place, FIX-2 NIT-1). `@latest` appears ONLY as the named
anti-pattern in prose (blob :557/:560 — both inside the section); every copy-pasteable entry is
exact-pinned and the section is dated "read from the package registries on 2026-10-30" with its
own re-date duty stated (registry NUMBERS not judgeable offline — test docblock disclaims them
too; npm crawl showing 0.0.82 is crawl-lag vs a today-dated 0.0.83 claim: observation, no action).
`--isolated` semantics match playwright.dev's own wording ("profile in memory, do not save to
disk"). Cross-refs resolve in-page: Two tiers :96, Where-stdio-runs :136, Foreign spellings :197,
Adding servers :484, Deferred "below" :670→:966. Lazy bullet matches code boundary
(`McpToolShape::derive` :100 `$floorTokens <= 0 || $perServer < $floor` ⇒ "reaches" = >= defers;
"0 never defers"; DEFAULT_LAZY_SCHEMA_TOKENS=8000 Bootstrap :8259; user-tier key in
LayeredSettings LAYERED_KEYS :644). No-jail bullet: `StdioMcpServer.php` :21-28 plain argv-array
proc_open, bashSandbox wired only from `Bash::fromCatalog()` (docs/SETTINGS.md:243) — honest.
permissionRules/config.json first-match-wins per r1's permissionConfigLayers check. Fingerprint
"recorded command line vs current" matches McpTrustPins docblock :10-19/:149-159.

## 3. Gates MEASURED (cwd=candy-code, plain pipe, PtyRunToolTest never in any filter)
- own file: `Tests: 4, Assertions: 101, PHPUnit Deprecations: 2.` OK
- `DocFigureProseDriftTest|McpBrowserPresetDocTest`: `Tests: 96, Assertions: 4239` OK
- doc-guard sweep (r1's 8-family filter): `Tests: 194, Assertions: 45792` OK — I see the same 194
  r2 proved sibling-driven: the worktree carries sibling uncommitted UsageSubcommandTest feeding a
  provider row (193 r1 → +1T/+2A sibling → +12A own = 194/45792, arithmetic exact); DuplicatedTest-
  HelperDriftTest alone = 14T OK (its C-TUI-3.a roster red is master-era files absent at this base).
- McpForeignTranslateTest: `16T/35A` OK · bridge/shape trio: `28T/81A` OK.
The 2 Deprecations are process-global (both untouched classes report 2; matches r1).
PERMISSION DISCLOSURE: `env -u LINES -u COLUMNS …` and `php -d memory_limit=…` forms were
permission-rejected in this session; I ran bare `vendor/bin/phpunit -c phpunit.xml --filter …`.
All five gates ran; nothing silently skipped.

## 4. Scope + weld carries
`git diff master...HEAD --stat` = EXACTLY docs/MCP.md (+130/-0) + the new test (+204/-0); zero
src/protocol/dist/suite-figure/durations touches. Both commits A=C=detain@interserver.net, 0
Co-Authored. Master (aa5ddf638, re-read) `git show master:candy-code/docs/MCP.md | grep -c 'Browser
control and computer use'` = 0 → the section is NOT on master: weld = CHERRY-PICK BOTH COMMITS
additive (c79073e20 then 5a42eb622), NEVER adopt/tree-copy (worktree carries sibling dirt). Insertion
context at master :548-556 matches the base byte-identically → both picks expected conflict-free.
Expected landed blob = 74,173 (master) + 5,887 (lane delta 78,833−72,946) = 80,060 B, grep -c = 1.
Proof: `git show <weld>:candy-code/docs/MCP.md | grep -c 'Browser control and computer use'` = 1 &&
`git show <weld>:candy-code/docs/MCP.md | wc -c` = blob(master-at-weld)+5887.
MINT durations row `candy-code/tests/Config/McpBrowserPresetDocTest.php` from MY measured 4T/101A
(durations is lane-forbidden). MCP.md is hand-authored → NO gen-docs regen. Collect the worktree's
UNTRACKED FIX-2.md + REPORT.md (89A figures there are pre-tip; tip carry is 4T/101A) plus this
REVIEW-3.md BEFORE pruning; ledger note: MAJOR-1 (a security-trust sentence) was cured pre-weld
on-branch, not in-weld.
