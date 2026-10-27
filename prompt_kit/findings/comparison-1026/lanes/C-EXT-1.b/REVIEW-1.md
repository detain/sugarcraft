# REVIEW-1 — C-EXT-1.b (round 1, static-only)

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 2 MINOR / 4 NIT)

Gates NOT run: php is DENIED in this reviewer harness (php -v and phpunit both refused).
All checks below are static reads of the diff + guards. Expected pins for the lead's
battery run: --filter 'ClaudeSettings|HookFile|PolicyFreshness|PolicySnapshot|BootstrapLaunch|HookManager|LegacyMigration' -> OK 227T/1462A; five-guard -> ONLY the suite-figure staleness pair (25,537 vs ~25,623).

## Verified against brief/plan (all PASS)
- Diff == exactly 12 files +571/-179; single commit 09a76e8c6; %ae and %ce separately = detain@interserver.net; zero trailers (commit law OK, one step = one commit).
- skip-and-record: prompt/agent/http/mcp_tool + unknown keys recorded per (name,group), launch proceeds; missing/non-string type still refuses loudly (pin survives); skippedEntries()/reset pair wired into both reset paths.
- fromFile(string $path, string $scope) with guardScope(); sole production caller Bootstrap.php:6056 via claudeSettingsScope(); hookFiles() loads at most one file per tier (realpath-dedup, user settings.local.json never loaded) so tier-qualified names are collision-free by construction; row 'name' AND $advisoryByName both flipped (no half-migration).
- Aggregate notice: one sprintf row, launch-cap census entry reportSkippedClaudeSettingsEvents => 1 unchanged; format still 3 conversions matching BootstrapLaunchFormatConstantsTest NAMED_FORMATS; per-method literal allowlist widened to exactly the new body literals.
- PolicySnapshot VERSION 1->2 + required userClaudeSettings key; checkPolicyFreshness fingerprints userClaudeSettingsFile() and ORs $userChanged; intended one-time reset documented.
- Regression tests discriminate (tiered-names side-by-side fails on old code; collision law re-pinned for hooks.yaml claiming a generated name; fresh policy-freshness pin).
- In-step guards: DocFigureProseDrift 'local' carrier-roster addition is the real required flip (SCOPE_LOCAL is a new T_CONSTANT_ENCAPSED carrier); confirmed no cc:/INT-4 doc pin exists there. No DocFigure/HookEnv arm quotes any rewritten HOOKS.md sentence (grep for 'five keys','cc:','Notification','refuses the launch','separate decision' in the guard: none). The 'An entry may carry five keys' byte-preservation keeps ClaudeSettingsHooksTest's doc arm green. New regex literal contains '$' -> GlobDialect-exempt. No lang keys, no emitters added, no weld-only files, no proc_open/fork, no E646 issue.

## Findings
- [MINOR step-local] src/Hooks/ClaudeSettingsHooks.php ~:441 / src/Cli/Bootstrap.php ~:9800 — the notice's '%d hook group(s)' double-counts one physical group carrying two distinct skips (SkipRecordTest pins '3 hook group(s)' where 2 groups contributed). Fix: key a set on source+event+group identity and print its size (or reword to name skips); move the enshrining assertion with it.
- [MINOR out-of-scope] docs/DOCKER.md:144-148 still narrates the notice as events-only and quotes removed wording — DOC-ROW for weld/closeout (outside brief's declared docs set).
- [NIT scope] tests/Cli/LegacyMigrationTest not in brief's file list but forced by the v2 shape gate (fixture needed userClaudeSettings + continuity assert); substance correct — wave-collision glance only.
- [NIT] tests/Hooks/ScriptHookClaudeDialectTest.php:82/233/343 hand-built fixtures still use old-shape 'cc:PreToolUse:0' names — green but teach a shape production can no longer mint.
- [NIT] brief's serialized list omits the Bootstrap class-constant regions edited (notice format ~:859, launch-cap map ~:316) though it mandates the constant change — no concurrent lane touches them, note for lead.
- [NIT] skippedEntries['events'] is recorded but never read by the drain (brief spec'd the carrier; harmless).

## Rulings/deviations adjudicated
- Deviation 1 (DocFigure edit is the MEMORY.md carrier roster, cc/INT-4 pin does not exist): CONFIRMED TRUE, legitimate.
- Deviation 2 (LegacyMigrationTest fixture): forced-by-guard, accepted with NIT above.
- Deviation 3 (same-tier collision reword): matches code — same-tier cannot occur in one launch.
- Deviation 4 (DOCKER.md): out of scope, tracked as MINOR DOC-ROW.
- Plan §6: nothing bears on this step; §1.8/§1.9 held. No fixer wave required beyond the two minors if the lead takes them.
