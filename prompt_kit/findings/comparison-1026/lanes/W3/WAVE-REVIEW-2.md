# W3 — WAVE-REVIEW-2 (round 2, resumed reviewer, tip e497506dd)

VERDICT: FINDINGS — both r1 gate-blockers CURED (31T/3544A and 19T/612A green; cures+evidence 6124edcc9/a011743d0/9e04380a7/8a2de5f6b all HEAD-ancestors), but the wider battery surfaced four chain-caught §1.8 debts + one foreign hazard:

[MAJOR·W3-internal §1.8] candy-code/tests/Cli/LoginSubcommandTest.php:366 — SwallowingCatchCensus red: try-assertions inside catch(\RuntimeException) (added by C-PRV-1.c pick 0e25ae5c7 era); fix = capture-then-assert idiom per guard message.
[MAJOR·W3-internal §1.8] src/Cli/Subcommands.php `new Tty(\STDIN)` — TtyStreamArgumentCensusTest.php:281 red: unrostered 'src/Cli/Subcommands.php: constant:\STDIN' row (same C-PRV-1.c origin); fix = verify real stream + roster row.
[MINOR·foreign-route] SwallowingCatchSrcCensus red src/Session/EnhancedSessionStore.php::usageRollups (C-SES-9.a 98b862e9f) + SwallowingCatchCensus red tests/Agents/McpDispatcherPresetScopeTest.php:269 (C-AGT-3.a 0c051ee95).
[HAZARD·foreign] tests/Tools/PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues deterministically SIGHUP-kills a plain-pipe runner at e497506dd (pre-W3 attribution, bbe6d7ab7/9314686aa era); blocks any run including it — OWNER route, do not touch in cures.

CLEAN axes: r1 battery exact-shape (Bootstrap|Launch 1073T, Rule|Frontmatter 909T, SessionPicker 146/170T, McpCall∪ 50T, Effort 128T; Schedule|SessionHost 172T w/ allowed DreamPassTest:288 only); five-guard = staleness pair only (25,537 vs 26,082); EnvRosterDrift 37T green (cure holds); 10/10 claims cat-file + ancestor + detain A=C; all 4 generators "up to date"; hygiene zero W3 residue (only cc-cmp-P-CTX.e live).

LEAD NOTES: reviewer's "welder in flight" phrase is stale (last welder returned @1193@; slot free). The two MINOR foreign-route items have no live lane loops (C-SES-9.a/C-AGT-3.a welded) → routed into ONE lead cure lane cc/cure-swallow covering items 1-4 (disjoint files); PtyRun hazard recorded as owner carry, NOT cured. Wave-close W3 gated on cure weld + r3 confirmation.
