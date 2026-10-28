# W17 wave-loop fix 1 — durations.tsv re-key (review §2 MAJOR)

Master commit d3536ac91 (on 35bf072e7, never pushed; ae==ce==detain, zero trailers;
tsv staged by explicit path). Real line 1032 (review said 1030, off by 2):
-candy-code/tests/Agents/AgentInterruptSendTest.php	0.015	4
+candy-code/tests/Chat/AgentInterruptSendTest.php	0.015	4
Verified: real file is tests/Chat/ (find); the candy-code/ prefix IS the convention
(all 1563 rows + --lib header; the fix-brief's "lib-relative" hint was wrong).
Figures kept: 3rd column = per-file TEST count (make-shards parseJunit n++ per
testcase, not assertions); --filter measured Tests: 4 (Assertions: 24; wall is
bootstrap) => 0.015/4 right.
No phpunit guard polices the tsv (repo-wide grep; review §2 itself concedes it) —
the "ascending-violations == 4" is a state fingerprint, not a pin. Path-only edit
preserves desc sort (Agents < Chat < Config tie order). Post-fix: file_exists
sweep 0 phantoms (was 1); K=24 manifest: pre-fix only the phantom path entered
shard lists (4 pins ran ZERO shards), post-fix the real Chat path is listed.
True fail-closed is the CI --against-json conservation gate (ci.yml ~:825).
Follow-up owed (review §2): fail-closed tsv-path-exists guard arm.
