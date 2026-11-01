# C-TOOL-6.h — FIX-1 (round 1)

Base authority: REVIEW-1.md (7,167 B) — `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT. WELD-ELIGIBLE: YES.`
Lane: /home/sites/cc-cmp-C-TOOL-6.h, branch cc/C-TOOL-6.h. Eight reviewed picks (3086cda45..5fae61054) UNTOUCHED; one follow-up commit.

## §1 — MINOR cure (raw evidence)

Review MINOR verbatim (REVIEW-1.md :16): "PendingClientCall class docblock claims \"the parent recomputes it [callId] from the frame to prove the payload was not altered in transit\" — FALSE: fromFrame only format-validates /[0-9a-f]{16}/ + bounds; nothing recomputes. PendingAsk twin carries no such claim. Fix: drop or reword to \"format-validates\". src/Backend/PendingClientCall.php class docblock."

Proof the claim was false (tree-wide grep, lane tip pre-fix):
- `PendingClientCall::callId(` has exactly ONE call site outside the class: `src/Backend/ChildChannel.php:356` — CHILD side, before writing. Parent never calls it.
- Parent-side handling is only `fromFrame` (`PendingClientCall.php:123`): `preg_match('/\A[0-9a-f]{16}\z/', $callId)` + type/bounds checks. No hash comparison exists anywhere.
- `PendingAsk.php` carries no "recomputes" claim (grep `recomput` over PendingAsk/ChildChannel/EngineBackend: single hit = the false docblock itself).

Cure applied — reworded (per review's "drop or reword") to truth, src/Backend/PendingClientCall.php class docblock:

```
- * the child computes it before writing, the parent recomputes it from the
- * frame to prove the payload was not altered in transit, and a client can
- * only answer a call whose exact arguments it was shown.
+ * the child computes it before writing, the parent format-validates it
+ * (the `/[0-9a-f]{16}/` shape and the deadline bounds — nothing is
+ * recomputed), and a client can only answer a call whose exact arguments
+ * it was shown.
```

(1 file, +4/−3, pure comment. The tail clause "exact arguments it was shown" kept — the parent keys pendings by the frame's callId, so a client answers only what it was shown.)

Commit: `12aa62289140c0af79acdf067c1e941075a556ca` — `git cat-file -t` = commit; subject `C-TOOL-6.h: review fixes (round 1) — PendingClientCall docblock truth: the parent format-validates the callId, it does not recompute it (r1 MINOR)`; %ae detain grep=1, %ce detain grep=1 (separate), Co-Authored-By=0; carried ONLY candy-code/src/Backend/PendingClientCall.php (`git commit -o`, explicit path); no -x.

## §2 — Zero-collateral statement

- `php -l src/Backend/PendingClientCall.php` → "No syntax errors detected".
- Anchored exact-class filters, post-fix (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS plain pipe):
  - `(ClientToolRegistryTest|ClientToolProxyTest|ClientToolPermissionTest)::` → OK — Tests: 53, Assertions: 136 (= r1 live 39/91 + 8/23 + 6/22 EXACT).
  - `ForkChannelClientCallRoundTripTest::` → OK — Tests: 7, Assertions: 40 (UNCHANGED).
  - BARE `TreeWideGuardRosterTest` → OK — Tests: 17, Assertions: 3947 (UNCHANGED).
  - BARE `DuplicatedTestHelperDriftTest` → OK — Tests: 14, Assertions: 204 (UNCHANGED).
  - (Each run also surfaces 2 ambient "PHPUnit Deprecations" = AccessorDeprecationTest:168/:202 private data-provider metadata — a listed accepted-foreign shape, not introduced here; the client-tool filters additionally data-provider-name-match those two era tests.)
- Docblock grep: no test pins the removed wording — `recomputes` hits elsewhere in tests/ are unrelated prose (Palette/WebhookSigner/InstructionFileLoader/DocFigure/Agent/bootstrap).
- lang: `git diff HEAD --name-only | grep -c 'lang/en.php'` = 0 (ZERO lines, name-only proof).
- GlobDialect: no NEW glob-shaped literal in test corpus — the only added token-bearing line is inside a src docblock (T_DOC_COMMENT; the differential harvests test literals; PathGlob corpus unaffected — docblock figures untouched).
- Residue grep on diff (`if (false`/`&& false`/`|| true`) = 0.
- Reviewed picks never amended; porcelain 0 at end (untracked lanes dir = working-dirt law); suite-figure.json / durations.tsv / REPORT.md / REVIEW-1.md / NOTES.md BYTE-UNTOUCHED (lanes dir stayed untracked).

## §3 — NITs: record-only, NO code churn (welder carries as ledger notes)

1. NIT verbatim (REVIEW-1.md :17): "ChildChannel::callClient builds the frame via describe() before the grandchild-pid refusal check — pure function, harmless; ordering note only." Disposition: recorded, no change (review itself: "NITs leave", :39).
2. NIT (builder scope-vs-r1 anchor, REVIEW-1.md :26): "Channel-filter 157T/5688A (builder 76T/1562A narrower scope — no mismatch)". Disposition: cosmetic ledger note only — the r1 reviewer's 157T/5688A is the wider anchor; the builder's 76T/1562A was a narrower filter scope, adjudicated non-mismatching at review. No change.

FOREIGN-RED NOTE FOR THE WELDER (report-only, cure-NONE, NEW family adjudicate-at-weld):
`ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode` 1F — base-carried offenders `Config/StatusLineSession.php` + `Host/Commands/SchedulesCommand.php`, both OUTSIDE the 22-file lane diff; lane src adds zero permissionMode reads (r1 grep proof, REVIEW-1.md :36). Per r1: "welder adjudicates (never-edit here)." Other accepted-foreign shapes standing at this tip unchanged: StderrEmitterCensus 2F PtyRelay-6j-debt, GlobDialect 1F base-carried assert-NEITHER-number corpus figure, staleness pair (suite-figure/durations NEVER-touch).

## §4 — §7 restatement (durations rows at r1 LIVE counts)

Four durations rows owed at weld, r1 REVIEWER live counts (REVIEW-1.md :37):
- tests/Host/ClientTools/ClientToolRegistryTest.php — 39T/91A
- tests/Host/ClientTools/ClientToolProxyTest.php — 8T/23A
- tests/Permissions/ClientToolPermissionTest.php — 6T/22A
- tests/Backend/ForkChannelClientCallRoundTripTest.php — 7T/40A
(60T net-new across 4 new files; + carry on RuntimeTest/SessionHostTest/Wither/AskRoundTrip/BuiltInToolCorpus edits; suite-figure +~54–70T-class carry disclose at battery re-pin.)

Post-fix re-run RULE: this fix touched a src docblock only; every count re-verified IDENTICAL above (53T/136A combined = the three registry/proxy/permission rows summed exact; 7T/40A fork row unchanged). r1 LIVE counts STAND — no row moved.

Welder WARN carried verbatim from r1 (:38): read the EIGHT explicit picks by SHA onto wherever master stands — NEVER a b45117223..tip range onto moved master; the fix pick (12aa62289) rides as the NINTH on the same lane base; en.php N/A; TWGR/census unions BY TEXT; trio/dist UNOWED (r1 0-byte proofs stand).
