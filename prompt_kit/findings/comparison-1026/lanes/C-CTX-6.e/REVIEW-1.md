# C-CTX-6.e REVIEW-1

VERDICT: CLEAN (0C/0M; 2 weld notes, 0 step-local MINORs)

Branch cc/C-CTX-6.e @ /home/sites/cc-cmp-C-CTX-6.e, EXACTLY 2 commits atop c9cee706c:
98c87ce96 (src 7 files) + 32123c5c0 (tests+docs+REPORT). All SHAs cat-file verified.
Both commits %ae=detain@interserver.net AND %ce=detain@interserver.net (separate greps);
zero Co-Authored trailers. Forbidden files untouched (README.md, suite-figure.json,
durations.tsv, dist/ absent from the 12-file diff).

## Duty lines (design-prv-ctx.md ### C-CTX-6.e, verbatim) vs landed

- "Add `RuntimeNoticeSink::recordStructured(kind, payload)`: a JSON datagram with a reserved prefix, bounded by `MAX_CHARS`. Only `memory.recall` with `{count, titles[]}` uses it." — LANDED. NoticeSink::recordStructured frames `"\x01notice.v1 " + json`; unknown kinds refused at the door (pinned). The 0x01 head (not NUL) is load-bearing: record() trims, and PHP's trim charlist eats \0 — pinned by testAStructuredFrameSurvivesTheRecordTrimWhole. Bound: tail-title shed keeping true count + per-title 80-clip; skeleton-refusal arm disclosed as defensive-only.
- "`Chat::pumpRuntimeNotices()` and `SessionHost`'s drain ... turn that kind into a new `Message::recallNotice()` row (uiOnly; the content falls back to the count sentence)." — LANDED at Chat.php pump + SessionHost.php:1197 drain; both DROP undecodable frames (never garbage rows). recallRowText() single prose source reuses existing chat.memory.recalled_notice.one/many (en.php:2300-2301); no new lang keys.
- "shows it collapsed ... expands it to the note titles through the transcript's existing expand affordance. No new key binding." — LANDED and PROVEN: renderRecallRow zones under TOOL_CALL_ZONE_PREFIX ('toolcall:') + recallKey; Chat mouse dispatch routes 'toolcall:*' → toggleToolOutput → the SAME $expanded registry the renderer reads; Ctrl+O chord untouched; expandedByDefault keeps it collapsed under expandToolOutput.
- "`announceMemoryRecall()` sends titles, not bodies." — LANDED: Runtime.php title = strtok(content,"\n") first line.

## Body-leak law (mutation-reasoned)

MemoryRecallBlockTest plants a two-line note whose BODY carries 'freeze'; the pin
assertStringNotContainsString('freeze', $rows[0]) reddens if the full body ever
travels. RecallNoticeRowTest pins collapsed render hides titles and
Message::agentVisible([$row]) === []. toWire() carries no recallTitles (grep-zero);
jsonSerialize only-when-set keeps pre-change checkpoint blobs byte-stable; fromArray
filters non-strings (hostile blob leg pinned).

## Reachability

Emitter is shared: EngineBackend.php:5229 primeMemoryRecall → RuntimeNoticeSink::recordStructured
→ self::current(); TUI arms via Bootstrap:1981 + Chat pump; serve via SessionHost's per-session
sink wrapped in RuntimeNoticeSink::using at :879/:2536/:2562 and drained at :1197. Both hosts live.

## Gates re-run by reviewer (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)

- RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest → 62T/270A OK (new file 9T/37A; sink test 30→35 methods, blob-counted both sides).
- ServedRuntimeNoticeDrainTest|ToolCallClickTest|MutateCarryCensusTest|MessageTest → 106T OK.
- DocFigureProseDriftTest + DuplicatedTestHelperDriftTest → green (within 125T/1F; the F is foreign, below). CLICK_VARS name unique tree-wide.
- RendererTest → 182T/17808A OK.
- "PHPUnit Deprecations: 2" appears at every gate INCLUDING untouched base methods — environment noise, disclosed by builder, confirmed shape.

## Foreign reds (NOT lane-caused; landed-blob proven)

1. ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified — ACTUAL has
   Server/ServerConfig.php|file_get_contents×2 + Session/Import/RemoteSessionPush.php|file_get_contents×1
   with zero ledger rows. Proven base-identical: lane diff touches none of the three files
   (git diff name-only = 0), base blobs carry the sinks (git show c9cee706c counts 2 and 1),
   and master ad60f7a3f's census test still has 0 rows for them → standing red for the roster,
   owed by the C-SEC-8.b-era lane that added the reads without census rows.
2. StderrEmitterCensusTest 3F at lane base — builder listed it; cure 2d44af1b5 exists on master
   (git show -s verified), moot after pick onto ad60f7a3f.

## Merge drift (builder claim verified by overlap analysis; merge-tree tool itself permission-denied)

c9cee706c..ad60f7a3f touches only ONE lane file: src/Runtime.php — master hunks all ≤:4550,
lane hunks at :6388/:6415 → disjoint by ~1800 lines. In-flight C-TUI-5.c shares Chat.php/
SessionHost.php files but its Chat hunks (2898..21125) and SessionHost hunks (881, 2571..3293)
are region-disjoint from the lane's (8699/20697; 1195). Welder: cherry-pick base..tip
(EXCL-start, base = c9cee706c, the 2 commits = 98c87ce96 32123c5c0), still run merge-tree at weld.

## Weld notes (informational, no lane action)

1. Disclosed deviation: Chat::expandedByDefault gained the `!str_starts_with($id,'recall.')`
   term outside the block's declared pump region (REPORT deviation 1). Functional necessity of
   the declared collapsed default; 1 line + docblock; disjointness proven above. Accepted.
2. DUTIES for weld (per CLEAN path): DUR MINT RecallNoticeRowTest (9 cases, junit-testcase-SUM
   median-of-3); RE-TIME RuntimeNoticeSinkTest row (35 cases now); §7 carries +9T / +5 methods;
   collect this REVIEW-1 + lane REPORT (on-branch blob 32123c5c0 — never overwrite) pre-prune;
   add ReadPathCensus ServerConfig/RemoteSessionPush roster gap to the standing-red list.
