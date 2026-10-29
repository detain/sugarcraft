# C-SEC-6.b — FIX-1 (round 1 review findings)

Branch `cc/C-SEC-6.b`, commit `403742811136b16bd0fc57d0197f1cf71003fbb8` (atop cbe7d05df, base e91eb1d47). 3 files +51/-2. %ae/%ce each grep 1 detain@interserver.net, zero Co-Authored, no caliber, no suite-figure/durations/dist touches, never pushed.

## MINOR-1 — CODE route taken
`candy-code/src/Cli/Bootstrap.php` agentManager feed (was :2618): the eager read is now
`$permissionRules = [...self::projectNarrowingRules(), ...$subAgentUserRules]` — the sub-agent
gate factory receives the same merged list as `permissionGate()` (:7216), so the README ~:1299 /
PERMISSIONS.md:611 beat-a-user-allow claim is now true unqualified for Task children too.
Docblock above the feed explains the why (child-wider-than-session is the forbidden direction;
notice dedupe is signature-keyed so a launch that builds both gates still says it once).

Pin: NEW method `BootstrapProjectNarrowingRulesTest::testTheSubAgentGateCarriesTheProjectNarrowingAheadOfTheUserRules`
(drives real `Bootstrap::agentManager()` + `createSubAgent('tester')`, asserts child gate rule ORDER
and the `Deny` answer on a Bash call). Class 8T→9T (method added to an existing file — the file's durations.tsv row is still the weld's
duty per the original REPORT; this fix adds no further file).

Mutation proof (cp+restore from /tmp/opencode/cc-sec6b-fix/): replacing the prepend with
`$permissionRules = $subAgentUserRules;` reddens EXACTLY that named test (class run: 9T/1F; isolated
run names it). Restored file diff vs HEAD-before-fix shows only the intended hunk.

### WebFetch.php:635 — separate surface, NOT edited (weld-carry)
It is `configuredRedirectRefusal()` (SEC-3 per-hop redirect veto), not the sub-agent rule feed: it
re-reads `Bootstrap::readUserConfig()['permissionRules']` live per hop BY DESIGN (a mid-session
`/permissions` edit must bind the next hop) and builds a rules-only decision gate. WebFetch.php is
outside the step's declared file set, and the narrowing fix for it is not a one-line prepend (the
memoized per-process project rules would desync from the file's deliberate live re-read). Disclosed
as weld-carry: a follow-up should decide whether redirect vetoes consult project deny/ask entries
and how to keep the live-re-read law.

## MINOR-2 — SETTINGS.md exception clause
`candy-code/docs/SETTINGS.md` "Which keys are layered at all" boundary paragraph gains one clause:
rules-axis exception only, narrowing only — trusted project's SHARED settings.json may contribute
deny/ask permissionRules via Bootstrap::projectNarrowingRules(), allow entries dropped and reported,
never through the LayeredSettings stack, trust remains user-tier-only. Links the existing
PERMISSIONS.md#the-four-trustedproject-keys anchor. "What no lower layer can reach is the project
tier" stays true as written (it is about THIS stack).

## Gates (cwd=candy-code, plain pipe, -c phpunit.xml)
- php -l touched files: clean.
- BootstrapProjectNarrowingRulesTest: 9T/26A OK.
- Combined security+docs family (ProjectNarrowing/BootstrapPermissionGate/AgentManagerPermissionGate/
  BootstrapProjectTrustGrant/AgentManagerProjectRoot/SubagentModelSetting/AgentManagerWiring/
  TrustKey/ConfigWriteProducer/EnvRoster drift): 198T/40708A OK.
- DocFigure+PermissionsIntro band: 97T, sole red = PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog :59
  — the foreign base-red r1 proved reproduces on master, byte-untouched by this fix.
- 'PHPUnit Deprecations: 2' config-level, pre-existing per lane REPORT.

## NITs — untouched, weld-carry doc material (per fixer brief)
- NIT-3 PermissionsCommand.php:426-431 cosmetic tier mislabel (signature match).
- NIT-4 lang/en.php:3607 settings.permissionRules.help + generated "U C" row under-inform the
  trusted-project read; weld regeneration/doc-row material.
