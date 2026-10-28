# W8 wave review (round 2, fresh reviewer) — master @ 30b7d4f08

VERDICT: CLEAN

## 1. Members vs marks — PASS
Live §3 W8 row (10): P-MENU.d · P-AGENT.d · C-CTX-2.a · C-EXT-2.b · C-SES-4.b · C-SES-9.c · C-SES-9.d · C-WEB-3.a · C-WEB-3.b · C-WEB-3.c.
All 10 now carry ✅ marks in §4 and all 10 hashes are ancestors of HEAD (git merge-base --is-ancestor):
65335c6c4 · ceed81ef0 · 6ce6df1f0 · 4c1e8bb82 · a3bf74a61 · 5badf6d2f · fc5523777 · e651724ca · bb8878195 · 5a9b2f2c6.
Subjects match the §4 entries. C-EXT-2.b landed via pick 4c1e8bb82 (cherry of 8ef5643d8), evidence weld ca71c405b.

## 2. Battery @ current master — PASS (only allowed reds)
cwd candy-code, env -u LINES -u COLUMNS, plain pipe, targeted only, PtyRunToolTest never matched:
Menu|Pane|Window|… 1589T OK · TabScope|… 40T OK · ContextSources|ContextC 148T OK · Skill|Frontmatter|Inert 1140T OK ·
Observability|Otel|… 82T OK · AgentPresetRegistry|Phar 114T OK 1S · SessionSearchIndex 15T OK · Share… 64T OK ·
SessionHub|SessionMethods|Gateway 17T OK · AskDiff… 21T OK · gen-command/tool/settings-docs --check rc0 ·
gen-protocol-schema --check rc0 · five-guard 133T 2F = EXACTLY the allowed staleness pair (pinned 25,537 vs live
26,379; live moved since r1's 26,287 — closeout re-pin owns it). No GlobDialect/DocFigure/HelperDrift/ChildWallClock
reds. Bonus: tools/tests WorkflowRunInterpolationTest 3T/134A green (C-SES-9.d guard).

## 3. Hygiene + claims-vs-landing — PASS
- worktree list = main + cc-cmp-P-TABS.g (W15, non-W8) ; branches = cc/P-TABS.g only. ZERO W8-member residue;
  cc/C-EXT-2.b pruned at weld. NOTE only: C-SES-1.b/C-AGT-7.b evidence dirs live, no worktrees/branches.
- Generated files: web dist rebuilt by weld-only commits (74b298fae/df322c2c7/a88fa904a/91c311db0/268844b75);
  no lane touched dist. Chat.php region claims disjoint and both live (gateToolCall :4755 / subscriptions :18592).
- C-EXT-2.b vs R-EXT-1 §6.1: honoured as written, ALL tiers, turn-scoped slice; grants cannot outrank configured
  deny/Plan/rm-rf breaker; disallowed-tools Deny refuses BEFORE mode; model/effort ride the NEXT request (latest
  declarer wins); Skill::$effort made nullable (the 'medium default' trap); context:fork stays INERT. Production
  readers real: Runtime.php:1467 + Chat.php:4755. Deviation noted (not finding): only 4 of the 8 listed SKILL.md
  files needed trims (security-audit lost bare Bash + effort:high); BuiltInSkillsTest pins the full 8-row roster;
  InertFrontmatterDocumentationDriftTest moved structurally (derives both sides; green).
- C-SES-4.b: carry-over queue capped at one batch; tick armed only via hasCarryOver() in subscriptions; flush does
  one bounded last attempt; zero in-class timers (E646-clean). RetryingEventSink + AsyncOtlpTransport +
  TelemetryRetryTickMsg all present per §4.
- Authors 10/10 %ae=%ce=detain@interserver.net (separate greps), zero Co-Authored/Committed-by trailers.

## Carries (not findings)
- docs/MCP.md :629-634 wording — C-AGT-3 welder/closeout duty (unchanged from r1).
- TrustTarget::promptKey + server.trust.ask.* unwired — C-SEC-5.b (W11) duty (unchanged from r1).

W8 may be marked ✅ in §3 by the lead.
