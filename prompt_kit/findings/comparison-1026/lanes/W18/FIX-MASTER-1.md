# W18 wave-loop master fixer — round 1 (2026-10-29)

Master base: d83e4eed4 (weld-18c close; weld-18/weld-18b refs 0-ahead → no welder live, proceeded as sole master writer).

## MAJOR-1 — ReadPathCensus Chat.php|file_get_contents — CURED as 5406bce93
DISCLOSURE: live state had INVERTED vs WAVE-REVIEW-1. At review tip 86f80fef8: 3 sites vs 2 rows (missing preset verdict).
Sequence since: batch-16 weld 44ff31314 added a `takeIpcPayload()` SELF_LOCATED row as "merged-tree census catch" — but the
pre-existing row "a forked child's result file, named by Support\ToolIpcFiles" already explained that same physical sink
(Chat.php has exactly ONE ToolIpcFiles payload read, inside takeIpcPayload(); the fan-out and turn-hook fork call the one
helper). Then f51949469 (C-AGT-4.c) added the correct `CONTAINED_UPSTREAM` presentsAsPrimary() row for C-AGT-4.b's read.
Result at my base: 4 rows vs 3 sites — red in the OPPOSITE direction (over-rostered), so NOT the CLOSED-no-op case the
brief anticipated (preset row present but the guard still red). Root-cause cure: collapsed the two ToolIpcFiles rows into
one that names the function (takeIpcPayload) AND keeps both claims (ToolIpcFiles-named, read-once-discarded, both callers);
kept the USER_TYPED pastedImagePath row and the presentsAsPrimary CONTAINED_UPSTREAM row untouched. Roster-only, zero src.
RED-PRE (d83e4eed4): `--filter ReadPathCensus` → 19T/1F, diff `Chat.php|file_get_contents expected 4, actual 3` at :1351.
GREEN-POST: 50T/937A OK (combined filter below, committed tip).
Site inventory verified: Chat.php :6140 takeIpcPayload / :13901 presentsAsPrimary / :17671 pastedImagePath.

## MAJOR-2 — NOT mine (routed to W21): confirmed still red at tip
SettingsWriterCensusTest:145 red — carries W21's `Cli/Bootstrap.php::stampBundleRecommendations->write` PLUS a NEW
unclassified row `Chat.php::persistFast->write` introduced by FOREIGN 0da9b5071 (C-PRV-3.b /fast, 02:23:35, AFTER the
W18 review tip — not in any W18 finding). Both rows are roster-verdict debt of the respective lanes; lead should route
persistFast alongside the W21 cure. No action taken (out of listed scope).

## MAJOR-3 — ToolCapSettingsTest x2 (foreign W19 5cdc6f440) — CURED as 003cb1e4e
1. $expected roster: added `ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_SEARXNG,` immediately after
   the endpoint line (matches src ToolLimits::KEYS order :174-176; default mirrors ToolSettings.php:297 definition 3rd arg).
2. Risk-loop: added WEB_SEARCH_BACKEND_KEY to the array_diff exclusion beside WEB_SEARCH_ENDPOINT_KEY — exact mirror of
   the endpoint's delegated-Egress treatment; src classification is RiskClass::Egress (ToolSettings.php:298-299) and
   SearchBackendsTest:140-142 already pins Egress + projectSettable-false (W19's own coverage). Comment truth-updated.
RED-PRE (d83e4eed4): `--filter ToolCapSettings` → 31T/2F: KEYS 22-vs-21 at :108 + Tuning-vs-Egress at :146.
GREEN-POST: OK (combined run below).
Import added: `use SugarCraft\Code\Tools\Web\Search\SearchBackends;`.

## Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, committed tip 003cb1e4e)
- `--filter 'ReadPathCensus|ToolCapSettings'` → **OK 50T/937A** (0F).
- Guard batch `DescriptorInheritance|LangParity|SettingsWriterCensus|ReadPathCensus|StderrEmitterCensus` → 141T/**1F** —
  sole failure is the MAJOR-2 file (W21 + foreign persistFast); ReadPathCensus leg now green inside the batch.
- ReadmeSuiteFigureDrift staleness pair → 4T/2F EXPECTED-STANDING (pinned 25,537 vs live era; closeout duty;
  suite-figure.json / durations.tsv / dist / protocol trio untouched per law).
- php -l both files clean. Never ran full suites; PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues never touched.

## Commits (cat-file-verified 'commit'; both master ancestors; author-law %ae and %ce separate greps = 1/1 detain; zero Co-Authored)
- 5406bce93 W18 wave cure: ReadPathCensus Chat.php file_get_contents verdict (collapse takeIpcPayload double-label)
- 003cb1e4e W18 wave cure: ToolCapSettingsTest webSearchBackend roster line + Egress mirror (foreign 5cdc6f440 inherited)
Tests-only; no src edits; no caliber; nothing pushed; backups in /tmp/opencode/w18-cure/ only.
