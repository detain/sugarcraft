# W13 wave review — round 2 (fresh reviewer, HEAD moved cbe74fb5a → 0fb10ffbf mid-run)

VERDICT: CLEAN 0C/0M

All four r1 MAJORs cured or dispositioned; cure 9fbb73173 verified present, honest and
load-bearing on master; every gate run green on the merged tree. NITs + closeout must-runs below.

## 1. Step ledger (plan §3 line 149, ten steps)
All ten §4 ✅ picks cat-file OK, merge-base ancestors of HEAD, %ae=%ce=detain@interserver.net,
zero Co-Authored trailers (incl. cure 9fbb73173 — 11/11 commits):
f63217396 C-EXT-2.e · 1aab5f2f9 C-WEB-7.c · 7b730e485 C-WEB-7.d · 1f1db4f97 C-TOOL-3.c ·
e71d398aa C-MCP-3.a · 14d37bbf5 C-TOOL-5.c · 5cd68af7e C-AGT-5.a · a28124a7e C-SES-3.d ·
7e97c9e4f C-TUI-6.k · bec7fc651 C-AGT-6.b. Files/tests/docs per §4 entries landed (r1 §2-§3 detail
stands; this round re-verified the landed union, not the claims afresh line-by-line).

## 2. Cure 9fbb73173 adjudication
- (a) DocFigure thirty-seven: `$wordNumbers['thirty-seven'] = 37` added at :5429 inside
  testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory. STRUCTURALLY LOAD-BEARING, not vacuous:
  docs/AGENTS_AUTHORING.md:463 and docs/ARCHITECTURE.md:832/:838/:841 spell thirty-seven; the arm's
  assertArrayHasKey (r1's exact failure mode) + assertCount vs scandir (= 37 files on disk, recount)
  + cross-page agreement leg all pass. Single occurrence in that arm's map. Attribution to C-AGT-5.a
  5cd68af7e is correct (that pick grew the dir 36→37 and re-spelled both pages in-step; only this
  arm-local map lagged).
- (b) TreeWideGuardRoster: rows 'Cli/DoctorSandboxProbeTest.php' (:606) and 'LSP/LspInstallerTest.php'
  (:672) each exactly once, no duplicate array keys, no W14 double-entry on master. Guard's
  consultedResidue dead-row self-check (green) proves both rows are live-consulted. The brief's
  "prose 222→224" claim is FALSE-TO-CODE and the fix report is right: :2044 states "The size of the
  constant is not written here; it is count(...)" — no size-numeral prose arm exists anywhere
  (grep 222/224 across guard+README+docs: zero hits).
- (c) Gates re-run by this reviewer at current HEAD: DocFigureProseDrift OK 91T/4013A (cure claimed
  3999A @6875a1d90; +14A is P-TABS.f's palette arm hunk at :5561, not a W13 delta). TreeWideGuardRoster
  OK 17T/3503A (+8A from P-TABS.f's new scanned test file). "PHPUnit Deprecations: 2" appears on every
  filter run — global pre-existing, not W13.
- thirty-seven/forty-six coexistence: 'forty-six' => 46 exists ONLY as an unspelled surplus entry in the
  OTHER arm's contiguous map (:6381, SETTINGS LAYERED_KEYS era, pre-existing since e6567b98c).
  Surplus-map-entry is the harmless inverse of the MAJOR-1 failure mode (missing entry). No double-entry
  drift; the two thirty-seven lines (:5429 vs :6379 range) are in separate arm-local maps.
- Conflict-saga footnote: the transient UU in DocFigureProseDriftTest.php (P-TABS.f fixer had written a
  competing thirty-seven cure) resolved keeping the W13 text; at HEAD exactly one cure entry exists in
  the BuiltIn arm; working tree clean.

## 3. Union-diff coherence (10 picks, merged tree at 0fb10ffbf)
- lang/en.php: 2885 flat top-level keys, zero duplicate literals; LangParityTest OK 7T/2904A
  (all 16 locales carry the W13 keys). Prefixes disjoint per r1 (host.turn.*/cmd.share.*/cmd.review.*).
- Generators all --check rc=0 (cwd candy-code): gen-command-docs, gen-protocol-schema,
  gen-settings-doc, gen-tool-docs (ScheduleTool row included).
- docs/MCP.md: C-MCP-3.a server-request section live (:610-611 ping/roots-list/elicitation-cancel);
  "exactly eleven subcommands" prose (:997/:1008) is structurally pinned to the live roster by
  DocFigure arms :4071/:4094 — green ⇒ true.
- No conflict markers in candy-code src/tests/lang. Chat/SessionHost/TurnController multi-pick regions:
  all 11 W13 lane test files green in merged tree (--filter, 127T/565A OK; PtyRunToolTest excluded).

## 4. durations.tsv set-equality
All 11 new candy-code W13 test files have exactly one row each in ROOT scripts/parallel-tests-durations.tsv
(1515 rows total). The five named stamp commits (a7c2a81a2, 522bf2ccc, 004bc7240, f87047b29, 54167db3e)
exist with matching messages; 54167db3e is C-TOOL-3.d (adjacent wave) — correctly rowed anyway. Post
stamp-set, foreign additions also covered (P-TABS.f DeckHostedSessionRoutesTest has its row — glance).

## 5. Hygiene + laws
- Cure stat: 2 test files + its own WAVE-FIX-1.md evidence (commit is honest).
- No cc-cmp/ worktree or cc/ branch for ANY W13 step (the 7 live cc-cmp worktrees are foreign
  C-AGT-5.e/g, C-AGT-6.d, C-CTX-4.c, C-WEB-4.a, P-TABS.g + tmp base — untouched).
- Author law 11/11; E646 clean (fix is tests-only; MCP elicitation defaults cancel; no wall-clock kill
  anywhere in the wave per r1); dormant code wired (schedule/share/dictation seams production-reachable
  per r1 §2).

## 6. Findings
- NIT-1 prompt_kit/findings/comparison-1026/lanes/W13/: WAVE-FIX-1.md is committed but
  WAVE-REVIEW-1.md and WELD.md sit untracked beside it — closeout: git add all remaining W13 evidence
  files for a consistent ledger.
- NIT-2 (r1 carry, still open, plan-file doc-truth): plan §4 C-EXT-2.e row claims
  InertFrontmatterDocumentationDriftTest modified; actual companion is BootstrapLaunchNoticeRoutingTest
  — dated erratum in the untracked plan_comparison.md is closeout-owned.
- ADVISORY (not W13): W14's loop must NOT re-add a DoctorSandboxProbeTest roster row (PHP same-key array
  element would silently clobber); its b264527ff lane should find the licensed row at :606 and abstain.

## 7. §7 closeout must-run (denied by this reviewer's permission map — NOT wave failures)
1. scripts/parallel-tests.sh K=24 --against-json battery (root scripts/) — ReadmeSuiteFigureDrift
   staleness pair (~27k live vs pinned 25,537) is re-pin-owned here, as briefed.
2. candy-code-web: node scripts/gen-protocol.mjs --check + dist determinism (7e97c9e4f was
   vitest/dist-only; only dist-commit 0e55e657c evidence stands behind it).
3. tools/check-child-lifetimes.php + tools/check-path-repos.php --no-lib-path-repos at root
   (first invocation denied by map; r1-era weld runs were clean).
4. vitest serial --no-file-parallelism for the C-TUI-6.k dictation suite.
STANDING (report-only, unchanged from brief): ChatTest AsyncCmd ×5 + AutomaticCompaction 1F (CTX band),
ChatHostLangCatalogue→C-PRV-1.b seam, ProjectTierRefusalInventory, SwallowingCatch→SkillTool::execute,
PermissionsIntro 'Schedule', KeyHelpTest, DreamPassTest, MouseWiringTest ctxmeter,
ReadmeJsonErrorContractDrift 4F, ContainedPathInventory 5-entry RULED deferral.

VERDICT: CLEAN 0C/0M
