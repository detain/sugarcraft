# W36 — wave review round 1 (RE-CUT)

This file SUPERSEDES the abandoned first-incarnation fragment (4,019 B, mtime 01:54, no verdict — void for authority). Every claim below is independently re-derived in THIS shell.

Anchor: master = (re-derived below via git rev-parse). Members per §4 '#### ✅' heading lines in the landed blob (### W36 — LATE): C-TOOL-4.e · C-TOOL-4.g · C-TOOL-6.j · C-TUI-6.g · C-WEB-5.d.
Duties: (0) eligibility · (1) cross-lane coherence incl en.php dup-scan + shared-file unions · (2) per-member file-set vs declared block · (3) commit law on landed picks · (4) production reachability · (5) merged-tree targeted gates + generators · (6) residue/weld hygiene · (7) §3-flip readiness.
Verdict line lands at EOF. Findings appended as verified.

## (0) Eligibility — PASS (re-derived THIS shell)
Anchor: master = 16d1a94e85764ea408c5eedb5dad33777238e749 (git rev-parse HEAD; unmoved since dispatch).
Landed-blob read (git show HEAD:plan_comparison.md): '#### ✅' count 326; the five W36 member headings present at blob lines 3819 (4.e), 3830 (4.g), 3841 (6.j), 3852 (6.g), 3863 (5.d). plan_comparison.md working-tree clean (git status --porcelain on it: empty).
Heading-named SHAs — 23 total — each `git cat-file -t` = commit AND `git merge-base --is-ancestor <s> HEAD` true:
- 4.e: 1c138d298 4664ec680 2d7110462 0b7805b44 (4/4)
- 4.g: df106a84c 8efbebce1 b07df4007 (3/3)
- 6.j: 7dbd67ea6 044356ab7 75174ea40 a599885bd f412d3ace 094d5ebe2 (6/6)
- 6.g: cb630ea46 77a328148 5b66a65af 774b7866d 34524cf58 9b1e240f0 08f53f2eb (7/7)
- 5.d: d3811a3d9 4b02c6984 9af627be9 (3/3)

## (1) Commit law + weld records — PASS
- 23 picks + 5 weld-record commits (ce8548945 4.e · d8678780a 4.g · c371d97c8 6.j · 15c7d4210 6.g · b62161a1f 5.d): %ae = detain@interserver.net 28/28 (SEPARATE grep), %ce 28/28 (SEPARATE grep), Co-Authored/Co-developed/Signed-off/Reviewed/Acked trailers = 0 on all 28.
- Weld-record contents match heading claims: ce8548945/d8678780a/c371d97c8 = plan mark + lanes/<ID>/ evidence only; 15c7d4210 additionally carries the sanctioned dist rebuild (index-BEwIkY_l.css→C0-nwVJU.css, index-C6T8duAb.js→Dwd-HFYq.js, index.html, manifest.json); b62161a1f carries the trio regen (schema.json +121, SERVER.md +33, generated.ts +49) + dist Dwd-HFYq→BGaQf8A- + index.html/manifest — exactly as the 6.g/5.d headings declare (generated files regenerated, not hand-edited).

## (2) File-set vs declared — PASS (disclosed deviations only)
- 4.e (18 files): all 12 declared-modify present + ServerJailTest created; extras ToolSettings.php + DocFigureProseDriftTest disclosed in heading (pick-2 'docs+regen+ToolSettings+DocFigure'); docs/HOOKS.md 3-line touch (LSP servers-map `sandbox` key, :1448 region) NOT itemized → see F2.
- 4.g (18): all declared present; EgressBridge.php + SandboxBackend.php extras = deviations (a)/(c) ACCEPTED in heading; StderrEmitterCensus + BinCandyCodeAutoloadGuard + LayeredSettingsTest same-commit census moves disclosed; EgressAllowlistModeTest created. Nothing dropped.
- 6.j (8): exactly declared + TreeWideGuardRosterTest.php (ONE new row, disclosed+verified — heading claim 'gains ONE row Sessions/BackgroundShellInteractiveTest.php at :771' is TRUE: row at :771, pick a599885bd by git log -S). F1 RETRACTED: earlier 'zero-new-rows' worry was a misread of 6.g's different claim; 6.j heading truthful.
- 6.g (48): EXACTLY 45 .vue + lang/en.php + web-strings.en.json + strings.en.generated.ts — heading's name-diff claim byte-matches. i18nCoverage.spec.ts pre-existed from 6.f (f9fb4e8a8) — no missing deliverable. 6.g touches ZERO roster files in its own span (git log on TWGR over 828aab3e..HEAD lists only a599885bd/3e0a2f662/4da8acee4 = 6.j/6.k/6.l other waves): consistent with its 'TWGR identity unchanged' heading claim.
- 5.d (11): declared + StateDir.php (PUSH_STORE_FILE hub-const, SANCTIONED by 5.c §7 note) + ReadPathCensusTest (SELF_LOCATED pure-addition, rows at :1127/:1133 verified by text) + dormantRoster.spec.ts (rule: list push.* dormant until C-WEB-5.e — see §(3): caller now exists, roster correctly carries NO push rows).

## (3) Cross-lane coherence — PASS
- lang/en.php (five-way append union incl. later waves): flattened key scan sort|uniq -d = ZERO duplicates; serve.push.notify_question/notify_completed contiguous pair present (:3656-3657 at anchor). CliLangCatalogue|LangParity gates green (my run §5).
- Bootstrap.php merged: 'function egressProxy' ×1, 'function tools' ×1 — no duplicated methods from 4.e∪4.g. tools() body FIRST statement = self::egressProxy(); (:9694, 4.g door). mcpJail :9074→:9078 withSandbox; lspJail :9560→:9564 (4.e scopes live).
- ToolSettings.php: sandboxScope (:132 def) and sandboxAllowedDomains (:142-147 def) both present side-by-side; LayeredSettingsTest.php:68 writable-key list carries BOTH. No key collision, no shadowing.
- Router/Responses: NO W36 member ever touched them (git log); the canvas rows seen at HEAD are purely additive foreign C-TOOL-6.g (later tip) — no dup-route/dup-key across the five.
- push.* 4-surface byte-consistency: MethodSchemas.php 3 entries (push.subscribe/unsubscribe/vapidKey) · Dispatcher.php:155 PushMethods::register · generated.ts METHOD_SCOPES push trio :1913-1915 (re-verified at HEAD after foreign regen 16fe158c8 rewrote the file — lines survive) · lang pair contiguous. dormantRoster.spec.ts correctly has NO push rows because C-WEB-5.e landed (298903b96 push.ts client; worktree cc/C-WEB-5.e legitimately gone).

## (4) Production reachability — PASS
- 4.e scopes: SandboxFactory::forScope(SCOPE_LSP/SCOPE_MCP) called in Bootstrap mcpClient/lspClient construction paths (:9074/:9560) — production tool/client factories.
- 4.g allowlist: Bootstrap::tools() first-statement egress door; SandboxFactory::fromSetting allowlist arm reads LaunchContext shelf; consumers SandboxScope/Bubblewrap/Seatbelt/EgressProxy*/PtyRun/ScriptHook all live files (grep census); jail env quartet + NO_PROXY per heading.
- 6.j relay: Bash.php:689 backgroundAnswer(… registry->startInteractive(…)) — live production entry; refusal path :685.
- 5.d push: Dispatcher registration (above) + ServerContext.php triggers pushNotify at :583 (QUESTION) / :587 (FINISHED), private pushNotify :646 → Lang serve.push.notify_* (:658); browser callers present (src-web/stores/push.ts 'push.vapidKey' :124, SettingsView.vue, sw.ts).
- 6.g i18n: 45 .vue files are the compiled web UI surface; generated.ts/web-strings.en.json regenerated (weld record §1) — reachable via dist shipped at 15c7d4210/b62161a1f; Lang-parity + CliLangCatalogue guards green (my run).

## (5) Targeted gates — ALL GREEN (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored stems; gates executed at tip 0cc426cc1, §(7) note on later movement)
- (ServerJailTest|EgressAllowlistModeTest):: OK 21T/162A — == 9T/62A + 12T/100A EXACT vs marks.
- (PtyRelayTest|BackgroundShellInteractiveTest):: OK 20T/155A EXACT.
- (PushMethodsTest|PushSubscriptionStoreTest):: OK 19T/82A EXACT (11T/48A + 8T/34A).
- (BashSandboxTest|BubblewrapHardeningTest):: pairing co-run OK 39T/192A (db1441a61 holds).
- (LayeredSettingsTest):: OK 99T/423A — 423 vs marks 411-413 explained: foreign rider 417b5fe13 (C-TUI-6.l voice settings) landed post-marks on same file; 99T identical; disclose, not chase (F3).
- BARE (TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest):: OK 31T/4211A (17+14 tests; ~3,9xx+204 assertion-consistent).
- (CliLangCatalogueTest::|LangParityTest::):: OK 15T/4,271A — en.php union coherence guard green.
- DocFigureProseDriftTest:: OK 92T/4,278A (4.e widen arm green; +42A merged-tree growth).
- GlobDialectDifferentialTest: 13T/364A/1F — docblock figure arm 310,245-vs-312,477: ACCEPTED FOREIGN roster item (same arm, corpus moved by intervening foreign waves; per roster law carry, assert neither number). Not accused.
- Every green run carries 'PHPUnit Deprecations: 2' base shape (AccessorDeprecationTest family — accepted).
- PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues never entered any filter (standing brief).

## (6) Generators — PASS on my executed legs (web-strings leg not permitted by my harness)
- php tools/gen-tool-docs.php --check → 'tool docs: up to date' rc=0.
- php tools/gen-command-docs.php --check → 'command docs: up to date' rc=0.
- php tools/gen-settings-doc.php --check → 'settings docs: up to date' rc=0.
- php scripts/gen-protocol-schema.php --check → rc=0 (executed twice: once before, once AFTER foreign regen commit 16fe158c8 rewrote schema.json/SERVER.md/generated.ts — 'up to date' at both tips).
- scripts/gen-web-strings.php --check + node gen-web-strings.mjs --check: REFUSED by this reviewer's shell allowlist (3 attempts, distinct forms) and npm/node-side is dispatch-forbidden; NOT my verification — 6.g/5.d weld records claim rc0 at weld-time (record cite only). Web-strings content coherence independently covered by my green CliLangCatalogue|LangParity + generated.ts push-trio text check at HEAD.
- Residue: git worktree list = main + /home/sites/cc-cmp-C-TOOL-6.g (its regen weld 16fe158c8 just landed; cleanup belongs to that lane) + /home/sites/cc-cmp-C-TUI-6.h. branch --list 'cc/*' = same two. NONE of the five W36 members leaves a worktree or branch. PASS.
- §3 flip readiness: table row '| W36 (LATE) | 5 | C-TOOL-4.e · C-TOOL-4.g · C-TOOL-6.j · C-TUI-6.g · C-WEB-5.d |' present at :172, NOT yet ✅ — wave-close duty, correctly absent at review time.

## (7) Findings ledger
- F1 RETRACTED (no finding): 6.j TWGR heading claim verified true.
- F2 NIT — docs/HOOKS.md:4.e touched HOOKS.md (LSP `sandbox` key doc, :1448) without itemizing it in the §4 files.modify design list or the heading (only generic 'docs'). Content correct, precedented (4.d row names HOOKS.md as the sandbox-scope doc home), all doc guards green. Fix at closeout: one ledger word itemizing the touch. No code change warranted.
- F3 DISCLOSE-not-finding: LayeredSettingsTest 423A vs member marks 411-413 — foreign C-TUI-6.l rider on the same file post-mark; test count identical, roster self-derived.
- F4 DISCLOSE-not-finding: GlobDialect docblock arm red at HEAD with a DIFFERENT number pair than the roster's (312,477 corpus now) — same accepted arm, drift from foreign waves; carry to battery re-pin owner.
- No CRITICAL. No MAJOR. No MINOR. All §1.6 duties pass on the merged tree.
- Tip movement disclosure: eligibility + law + file-set + coherence + §5 gates executed at 16d1a94e8/0cc426cc1; movement 0cc426cc1→16fe158c8 is ONE foreign weld tail (C-TOOL-6.g regen; touches only schema.json/SERVER.md/generated.ts/dist, detain-clean, zero W36-member files); push-trio + protocol-schema re-verified at 16fe158c8 by me. 16d1a94e8 is an ancestor of HEAD (merge-base verified).

WAVE VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (F2 HOOKS.md un-itemized 4.e doc touch). WELD-ELIGIBLE: YES — W36 all five members closed on master.
Eligibility roll (landed → ancestor-verified at 16fe158c8; 23/23 cat-file commit + merge-base --is-ancestor HEAD): C-TOOL-4.e → 1c138d298 4664ec680 2d7110462 0b7805b44 · C-TOOL-4.g → df106a84c 8efbebce1 b07df4007 · C-TOOL-6.j → 7dbd67ea6 044356ab7 75174ea40 a599885bd f412d3ace 094d5ebe2 · C-TUI-6.g → cb630ea46 77a328148 5b66a65af 774b7866d 34524cf58 9b1e240f0 08f53f2eb · C-WEB-5.d → d3811a3d9 4b02c6984 9af627be9.
