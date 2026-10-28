# C-TOOL-2.b r1 review (salvage tip eaae19bdc)

VERDICT: CLEAN (0C/0M, 3 MINOR — all narrative/scope, none functional)

## Verified true
- 21 files +1996/−534 vs master; single commit, detain A/C, clean tree.
- SECURITY: all 3 hosted adapters use CONST fixed https hosts (api.search.brave.com/api.tavily.com/api.exa.ai); key ONLY in headers (X-Subscription-Token/Bearer/x-api-key), never URL (byte-exact pins). Blocklist+resolver reused: searxng endpoint AND hosted dial both route guardedDialAddress; unresolvable/non-IP/private-IP answers refused before connect (pinned). follow_location=0, 5MiB cap enforced pre-buffer.
- No-echo REAL: key constants planted; assertStringNotContainsString across refusals + 401/403/302/503 surfaces; 3xx Location echo clipped 256B + control-stripped.
- Byte-identity at default: refusal strings + description() byte-identical vs master (diffed); WebSearchToolTest legacy needles survived migration (only seam swaps: reflection→digestOf, fetches closure); BuiltInToolTest:217 pin green.
- Settings: webSearchBackend user-tier enum default searxng, projectSettable=false, LAYERED row, userTierOnly 78→79; live counts 136/136 match README+SETTINGS regen; EnvReadScanner +3 keys = legit §1.8 flip. ProcessContainment '*_API_KEY' scrub covers all 3 by pattern.
- Mid-session behavior REAL + pinned honestly (testASettingsChangeMidSessionIsHonoredAtTheNextConstruction; ENVIRONMENT prose says "applies from the next launch").
- Claim(5) en.php:3600 NOT an invented base bug — base php -l clean; run-state.json confirms apostrophe break was the PRIOR phantom's dirty tree. Honest salvage framing.
- 2 deprecations proven base-native (AccessorDeprecationTest private providers, file untouched). Test counts 9T/19T exact.
- Gates: 'WebSearch|SearchBackends|HostedBackend' 128T/424A/0F; 'Web' 414T/1420A/0F; guards 2142T: 11F = 2 staleness-pair (welder re-pin) + 9 base-native pointing exclusively at foreign code (chat keys, bundle-marketplace sort, SkillTool catch, PermissionsIntro, JsonErrorContract×4, mediaKinds) — branch base 0ebfdf9fe predates master welds; expected to heal on pick.

## MINORs
1. step-local (claims-truth): builder return claim (3) "EngineBackend ONE call-time resolve leg" FALSE-as-described — EngineBackend untouched; resolve is at WebSearch construction. Disk+tests+docs all honest; narrative stale. No code change.
2. step-local (claims-hygiene): "323T/46,885A" target filter not reproducible/undisclosed; my closest sets all green.
3. step-local (scope, acceptable): SearchResponse.php (digest carrier) + LayeredSettings.php 1-line userTierOnly append exceed brief file-list; both functionally necessary, disclosed.

## Welder carries
DUR x2 (SearchBackendsTest, HostedBackendShapesTest); suite-figure+README re-pin ~+28T (staleness pair red by design); §4 cite eaae19bdc; REBASE-COLLISION vs cc/C-EXT-4.c (in build): README/SETTINGS "one hundred and thirty-six" numerals, LayeredSettings list, ENVIRONMENT table — gen-settings-doc --check post-pick; re-run the 9 guard failures at master (expect clean).
