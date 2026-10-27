# REVIEW-2 — C-WEB-3.b (round 2) — c0715ebc0 on cc/C-WEB-3.b

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT) — all five round-1 items landed; FileViewer.vue is the only production change; four r1 CLEAN axes intact.

Chain disk-read (.git/logs): 86f43fae2→75f8f4a9d→c0715ebc0, exactly two commits, committer Joe Huss <detain@interserver.net> both. Author-%ae/separate-greps and trailer scan NOT re-verifiable (shell denied this harness); builder claim accepted, consistent with r1's disk verification.

- M4 FIXED, math exact: FileViewer.vue:42 `offset + (truncated ? READ_LIMIT_BYTES : 0)` vs FilesMethods.php:94,97,106,109 — server echoes requested offset (:106) and truncated ⇔ raw strlen == limit (:97,:109), so the advance is precisely the next raw byte. TextEncoder/lastChunkBytes path gone; docblock :14-18 truth-cites the server. Pin spec:165-185 forces the divergence (sanitized content 8 bytes vs 32,768-raw limit, size 40,000): old content-length arithmetic reddens BOTH 'showing 32768 of 40000 bytes' (:176) and {offset:32768} (:180). Real, not vacuous. NIT folded: second-chunk echo offset 32_768 (:182), hint pinned.
- M3 FIXED+PINNED: FileViewer seq token :38,45,50,58,61. Two-click pin spec:147-163 (a.ts then b.ts, a.ts frame answered late by id, length pre-arm `sent==2`): drop the :50 guard → A-CONTENT writes text → :160 reds; b.ts still renders after (:161-162). Guard ordering correct (state writes :51-56 all after token check; loading release :61 gated).
- M1 PINNED (code unchanged, guard was FilesPanel.vue:26,29,34,37,40): pin spec:69-84 — newest answered in order (:77, 1 row), then mount frame answered late by id (:79). Drop :34 guard → stale.txt lands → :81-83 red (length alone survives; the id-targeted row asserts catch it). Non-vacuous per the r1 fix prescription.
- M2 PINNED (gate SidePanels.vue:103 unchanged): spec:209-219 — sessionId null, tab clicked: files-panel absent AND zero fs.search. Drop `&& sessionId` → FilesPanel mounts, onMounted searches → both legs red.
- Scope: diff set = filesPanel.spec.ts + FileViewer.vue (FilesPanel/SidePanels byte-match r1's cited lines). No dist traces of the feature (grep files-panel/file-viewer across dist/: none). v-html absent ({{ }} only, :88,:92,:97); RPC params unchanged ({path,offset,limit:32768} first frame pinned :105); empty-query front {limit:50} pinned (:51,:203); binary suppresses load-more (:136-145) and truncated folds at :56; ChangesPanel untouched. Spec counts 9→12 its = claimed +3 (42 total).
- Gates NOT run (shell denied): builder's vitest 4 files/42 and vue-tsc 6-foreign-TS2554 claims accepted on static trace + [0]!.id typing verified against fakes.ts:52 (answer(result: unknown, id: unknown)).

- [NIT] step-local filesPanel.spec.ts:75-76: comment inverted — the in-order answer is the keystroke frame (newest), the late-by-id answer is the mount frame (stale); text says the reverse. Fix wording.
- [NIT] step-local filesPanel.spec.ts (whole): only the stale-SUCCESS half is pinned — deleting the catch-side guard (FilesPanel.vue:37) keeps the suite green (no pin answers a stale frame with wire.fail); :40's release check is tautological given the :34/:37 early returns.

Round 2: no fixes required; NITs optional at weld. Ready to proceed.

(Lead note: reviewer shell+write denied; text persisted verbatim from reply @1378@. Weld duties: re-run vitest serial 4 files/42, vue-tsc foreign-6 only, dist rebuild commit-if-changed, %ae/%ce greps.)
