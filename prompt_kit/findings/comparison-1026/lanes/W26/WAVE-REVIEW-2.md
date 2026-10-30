VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT (W26 re-review on master 671105299; r1 MAJOR C-CTX-6.e CLOSED)

Reviewer: cc-cmp wave-reviewer, read-only; only writes = this file. Gates cwd=candy-code -c phpunit.xml plain pipe env -u LINES -u COLUMNS; PtyRun exclusion honored.

## 0. r1 carry — C-CTX-6.e resolution CONFIRMED
- `git merge-base --is-ancestor 671105299 master` → master TIP is 671105299 (self-ancestor trivially); picks 9c59bc8d1 + a7627584b both ancestor-verified; all 10 W26 mark SHAs ancestor-verified (a7627584b c9e5cbde3 7240c63f3 2f701f308 5419a4f80 06d7a9858 9c887f03b 093cd25e1 85463f3fd a725ebc11 = 10/10 OK).
- Mark `grep -n '^#### ✅ .* C-CTX-6.e' plan_comparison.md` → 3119:1 `#### ✅ a7627584b C-CTX-6.e — Structured, expandable recall row` (weld 671105299 diff = plan heading flip + run-state fold only; honest bookkeeping correction, content arrived via WELD-1030Z picks).
- Landed-blob proof @master: RuntimeNoticeSink.php STRUCTURED_PREFIX="\x01notice.v1 " :338, KIND_MEMORY_RECALL :348, recordStructured :469, decodeStructured :490; NoticeSink interface recordStructured :150; Chat.php decode→Message::recallNotice :20771-20775; SessionHost.php :1280-1284; Message::recallNotice :372; Renderer renderRecallRow :4454 + recallKey :4794; docs/MEMORY.md expand/titles prose :496-502; tests/Chat/RecallNoticeRowTest.php live; DUR row live (durations.tsv line 872 `RecallNoticeRowTest.php 0.059 9`, arrived via ae009262d — mark text says "already committed", true).
- Step gates re-run live: 'RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest' = 62T/270A OK (matches mark).

## 1. MINOR-1 — mark annotation misquotes its own filter; as-recorded it REDS on master
- The ✅ C-CTX-6.e weld-note records: "step filter (RecallNotice|RuntimeNoticeSink|MemoryRecallBlock) 62T/270A OK". Run VERBATIM on master (candy-code src+tests clean vs master per `git status --porcelain`): `Tests: 97, Assertions: 6630, Failures: 1` — the `RuntimeNoticeSink` substring also matches RuntimeNoticeSinkDeliveryTest, whose tree-wide arm testNoSourceFileCarriesStackedDocComments fails at RuntimeNoticeSinkDeliveryTest.php:1385 flagging 4 files: tests/Chat/McpLoginForkTest.php:309, tests/Config/StatusLineCommandTest.php:922, tests/Host/AheadOfNeedSkipRulesTest.php:499, tests/MCP/SseMcpServerServerRequestTest.php:355.
- Attribution FOREIGN, proven by landed blobs: all 4 files tracked at master and none is a W26 deliverable; `git log -L306,312:candy-code/tests/Chat/McpLoginForkTest.php master` names 827f20e5c "C-MCP-4.c" as the stacked-block producer; other three last-touched by C-TUI-4.c / C-CTX-5.b / C-MCP-3.c commits. The mark's 62T/270A figures are genuine for the narrower exact-class filter (reproduced EXACT).
- Fix: welder/lead rider one-liner restating the note as `(RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest)`; route the 4-file stacked-doc red to those steps' wave reviews + master fixer (this is a REAL red on master reachable by any `--filter RuntimeNoticeSink` rerun, not a standing-red in the trio).

## 2. MINOR-2 — C-TOOL-6.a branch+worktree kept after absorption (hygiene duty open)
- `git branch --list 'cc/*'` → cc/C-TOOL-6.a live; `git worktree list` → /home/sites/cc-cmp-C-TOOL-6.a at a8e09717e. `git cherry master cc/C-TOOL-6.a` → 3/3 '-' (c79073e20, 5a42eb62, a8e09717e fully absorbed). Retention was justified in r1 era by the cross-worktree-contamination note; the step is welded and docs/MCP.md single-browser-section is LIVE (`git show master:candy-code/docs/MCP.md | grep -c 'Browser control and computer use'` = 1, :554), so the prune duty stands for the lead once the noted uncommitted dirt is dispositioned. NOT re-accused as content risk.

## 3. NITs
- NIT-1/NIT-2: plan §4 heading convention drift within W26 — C-SES-8.e keeps a plain `#### C-SES-8.e` twin at :3155 below its ✅ mark at :3166, and C-TOOL-2.f a plain twin at :3171 below the ✅ at :3168 (opposite stacking order between the two). 8/10 steps use the single flipped heading (as 671105299 did for C-CTX-6.e). Plain-twins can make a `^#### C-` audit miscount a welded step as open. Closeout: collapse to the flipped-heading convention.

## 4. Merged-tree checks re-run at 671105299
- Guard trio DocFigureProseDrift|DuplicatedTestHelperDrift|GlobDialectDifferential = 119T/4747A OK (2 deprecations = foreign standing) — reproduces markfix claim EXACTLY; C-TUI-3.a duplicated-consts prior-era red confirmed CLOSED.
- Doc/protocol drift guards: ProtocolSchemaDrift|ReadmeRosterDrift|CommandDocsDrift = 23T/392A OK; TUI-2.g regen pair coherent (schema.json checkpoint hits 8, generated.ts 7; FilesDiffCheckpointTest live).
- W26 targeted family battery (UsageSubcommand|UpdateCheck|CopySlashCommand|CopyCommand|FilesDiffCheckpoint|ResponsesToolResultMedia|McpToolBridgeImageChannel|AutoReviewScope|BootstrapPermissionGate) = 168T/595A OK on merged tree.
- php generators (`php ... --check`) DENIED by reviewer permission map — the equivalent drift is guard-covered by the two green filter runs above; disclosed.
- C-TUI-2.f web blob live: DiffView.vue static `import { highlight ... }` :5; master dist/index.html carries index-ZSMzjOfw.js/index-DQH0VwH-.css and ZERO lazy highlight-*.js chunk (later foreign rebuilds superseded the weld hashes — consistent eager bundle holds). vitest NOT re-run (web blobs unchanged by W26 since its 44/44 + 19/19 weld gates).
- SES-6.e wiring live: Bootstrap.php scheduleUpdateCheck :1277/:1303-1307; lang en.php startup.update.available :3035 + cli.usage rows :1222-1223 (no dropped rows in the shared lang merge).

## 5. Claims vs landing / laws
- All 10 step deliverables above verified against `git show master:` blobs (landed-blob law), not marks or commit prose. Cross-step Chat.php/Bootstrap.php region claims (CTX-6.e pump + TUI-3.a handleCopyCommand :12735; SEC-7.e autoReview :7440-7468 + SES-6.e chat tail) coexist live, disjoint.
- Authors: 9c59bc8d1/a7627584b/671105299 all %ae=%ce=detain@interserver.net, zero Co-Authored (17 earlier W26 commits verified at r1). E646: zero sleep/timeout/set_time_limit introductions in the CTX-6.e slices. Dormant code: none deleted; arms wired.
- suite-figure.json/README staleness pair: NOT re-pinned — §7 closeout duty, disclosed not accused (unchanged from r1).

## 6. Verdict summary
W26 is functionally complete and green on master 671105299. r1's MAJOR is closed with independent landed-blob + gate proof. Two MINORs: weld-note filter misquote hiding a foreign-attributed real red reachable via the recorded filter string, and the kept C-TOOL-6.a branch/worktree despite 3/3 cherry absorption. Two NITs: §4 heading-twin drift (2 steps). No CRITICAL/MAJOR — wave may proceed to closeout with the MINORs dispositioned.

---

## 7. Rider — W26 wave-loop fix round m2 (2026-10-30, master-direct)

Appended by the m2 fixer on top of master `b5cf8dabb`. Sections 1-6 above are the reviewer's r2
text and are NOT rewritten; this rider dispositions r2's two MINORs and closes the evidence loop.

- **RIDER-1 — C-CTX-6.e weld-note filter restated (r2 §2 MINOR-1).** The note in
  `671105299` (mirrored verbatim in `plan_comparison.md:3120`) reads
  `step filter (RecallNotice|RuntimeNoticeSink|MemoryRecallBlock) 62T/270A OK`. The FILTER STRING
  is a misquote; the FIGURE is not. Restated as the exact-class form it actually ran:
  `--filter 'RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest'` →
  **`Tests: 62, Assertions: 270, Failures: 0`** measured live at `b5cf8dabb` (2 deprecations =
  foreign standing family), reproducing `lanes/C-CTX-6.e/WELD-MARKFIX-REPORT.md:13` byte-for-byte.
  The bare-substring form additionally sweeps `RuntimeNoticeSinkDeliveryTest`, whose tree-wide
  `testNoSourceFileCarriesStackedDocComments` arm was a REAL red on master (r2 §2 named it
  correctly). That red is cured by `b5cf8dabb` (`ACCEPTED_STACKED_DOC_PAIRS` roster), so a verbatim
  re-run of the note's own string is now green too: **`Tests: 97, Assertions: 6634, Failures: 0`**
  (r2 measured 97T/6630A/**1F**; the +4 assertions are the four known-positive/negative fixture
  arms in that class, which were never reached while the roster arm failed first). The restatement
  stays on record regardless: a future bare-substring figure must never be silently compared to
  the 62T in the note.
- **RIDER-2 — r2 §2 MINOR-2 (prune claim for `cc/C-TOOL-6.a`) is OVERRIDDEN.** Fleet/owner ruling:
  the branch and its worktree (`/home/sites/cc-cmp-C-TOOL-6.a` at `a8e09717e`) are
  **FOREIGN-KEPT** — a revived owner-stopped lane, not this wave's residue — and are **never to be
  pruned** on account of this review. r2's `git cherry` proof (3/3 absorbed: `c79073e20`,
  `5a42eb62`, `a8e09717e`) stands as a CONTENT statement only; the retention is a decision, not
  outstanding debt. The m2 pass touched no branch and no worktree.
- **Boundary note (r2 §2, second half of MINOR-1's routing).** The other red that the same
  bare-substring shape exposed — `PaletteSupersetDispatchTest::SLASH_ONLY_SURVIVORS` missing
  `output-style` — was already cured on master by `1b5192f42` (`W29 wave-review: finding 2
  PaletteSupersetDispatchTest output-style survivor row`, evidence record `9ca006685`) before m2
  could land its own row. m2 contributed nothing there; re-verified green at tip:
  **`Tests: 7, Assertions: 272, Failures: 0`**.
- **NIT-1/NIT-2 (§4 heading twins): NOT touched.** Plan-§4 heading-convention collapse is §7
  closeout work on the tracked `plan_comparison.md`, which a wave-loop fixer does not stage.

Full m2 record with per-site attribution: `lanes/W26/FIX-m2.md`.
