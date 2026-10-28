# C-TOOL-2.b — pluggable web-search backends — DONE (real tip eaae19bdc)

VERDICT: prior incarnation's broken salvage fully repaired, completed, tested, and committed on cc/C-TOOL-2.b (base 0ebfdf9fe + 1). Tree clean. NEVER pushed.

> Review round 1 (r1 clean, 0C/0M): narrative-only corrections below — resolve timing
> (§Resolve timing), gate figures re-run and stamped verbatim (§Gates), and two seam notes.
> Zero code/test edits rode that round.

## Salvage audit (what was broken, what I fixed)
- lang/en.php:3600 unescaped apostrophe ("that service's") → fatal parse error; escaped (house `\'` convention). php -l clean.
- SearchBackends::make() wired hosted ctors POSITIONALLY: 2nd param is $apiKey → Closure passed as key (TypeError on any hosted backend). Fixed with named args.
- LayeredSettings::LAYERED_KEYS missing 'webSearchBackend' (salvage oversight) → added beside webSearchEndpoint; docs regenerated via tools/gen-settings-doc.php --write + README never-project sentence gained its reason clause (TrustKey guard demanded).
- WebSearch::description() reworded by salvage → reverted byte-exact to base (pinned in non-owned BuiltInToolTest.php:217).
- docs/ENVIRONMENT.md "rebuilt at launch and for /websearch" claim corrected to the truthful "read when the tool is built; applies next launch" (base family law).
- WebSearchToolTest (fetch/formatResults gone) + WebSearchTransportTest (fetch guard gone) migrated to the transport-closure seam; every pinned string byte-kept.
- Test-capture bug found by mutation: recordingTransport dropped the request body (TypeError under run). Fixed.

## Resolve timing (truth; corrects the builder's return claim)
The first return described "EngineBackend ONE call-time resolve leg". That is NOT what shipped:
- `EngineBackend` is untouched — `git diff 0ebfdf9fe..eaae19bdc -- src/Backend/EngineBackend.php src/EngineBackend.php` is empty and neither path is in the commit's 21 files.
- The backend resolves at `WebSearch` CONSTRUCTION: the ctor reads `ToolLimits::WEB_SEARCH_BACKEND_KEY` through `honoured()` and hands it to `SearchBackends::make()` (src/Tools/BuiltIn/WebSearch.php:122-123); from then on the tool performs no backend choice at all.
- So a mid-session setting flip lands at the NEXT construction — exactly what the pin is named for (`SearchBackendsTest::testASettingsChangeMidSessionIsHonoredAtTheNextConstruction`, flip and flip-back both) and what `docs/ENVIRONMENT.md` says ("Read when the `WebSearch` tool is built… applies from the next launch"). Code, tests and docs were honest all along; only that return sentence was stale.

## Tests (the ones that never existed)
- tests/Tools/Web/Search/SearchBackendsTest.php — 9T/44A GREEN: default/null/'' → searxng byte-identity incl. refusal text; unknown name refused by name + roster; setting-definition asserts (enum, Egress, layered, not project-settable); MID-SESSION setting flip honored at next construction, flip back too; bad enum spelling dropped by schema → default.
- tests/Tools/Web/Search/HostedBackendShapesTest.php — 19T/92A GREEN: per-adapter exact URL/method/headers/body (X-Subscription-Token, Authorization Bearer, x-api-key); clamps/maps (count 1..20, safesearch, freshness, days); missing-key refusal names env+backend, hostile pin: key value NEVER echoed; blocklist leg (resolver →127.0.0.1 → private-address refusal, transport never called); hit mapping per shape; status/parse/size/redirect surfaces.

## Gates (actual — all re-run and reproduced at eaae19bdc, review round 1)
Shape for every run: `cd /home/sites/cc-cmp-C-TOOL-2.b/candy-code && env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter '<filter>'` (linked vendor, worktree of cc/C-TOOL-2.b).
- `--filter 'WebSearch|ToolLimits|SettingsSchema|EnvRoster|TrustKey|DocFigure|ReadmeRoster|ToolSettings'` → **323T / 46,885A / 0F**, only "PHPUnit Deprecations: 2" — proven BASE-NATIVE (probe worktree at 0ebfdf9fe: 28T/74A + same 2 deprecations). Round-1 re-run reproduced 323/46885 EXACT (a review twin could not; running that filter from another tree/cwd — e.g. the repo root rather than `candy-code` — is the difference, not the figure).
- Wider adjacents green, with their filter strings named: `--filter 'BuiltInTool|SettingsTier|ToolCatalog|WebSearch'` → 368T/1,472A; `--filter 'UiOnlyWire|SlashDispatch|EnvReadScanner|StderrEmitter'` → 121T/22,356A; the secret-scrub family is `--filter 'Secret|ProcessContainment'` → 171T/560A.
- Mutations 4/4 discriminating (cp+md5 restored): positional-args→2E; throw-removal→exactly the unknown-name test; settings-read removal→exactly the mid-session test; key-echo into error→exactly the hostile pin.

## Deviations (disclosed)
- src/Tools/Web/Search/SearchResponse.php is NOT in the brief's files.create list — needed as the digest carrier; cannot fold (one-type-per-PSR-4 law). SearchBackend.php (brief-named) exists as the interface.
- WebSearchTransportTest migration outside the brief's files list — §1.8 necessity (its fetch-overrides died with the refactor).
- Env keys are the brief's BRAVE_SEARCH_API_KEY/TAVILY_API_KEY/EXA_API_KEY (spawn prompt's "BRAVE_API_KEY" was a paraphrase).
- "ToolLimits::honouredString" never existed; enum safety comes from schema validation in honoured() — bad spellings drop to the searxng default (pinned).
- isSecretEnvName/*_API_KEY scrub landed at base already (verified, zero edits); C-TOOL-2.c's anthropic enum deliberately NOT added.
- `SearxngBackend` is deliberately the one NON-final backend class (hosted siblings are `final`); the reason is stated in its own class docblock — F-W3 guard history plus a cache-answering deployment may subclass it, while tests never needed the seam because a transport closure is injected through `SearchBackends::make()`. Flagged so a future `final`-sweep does not close it silently.

## Closeout carry (not fixed here)
- `WebSearch::description()` still says "a configurable SearXNG endpoint" — byte-identical to base BY DESIGN and pinned at non-owned `tests/Tools/BuiltInToolTest.php:217`, but the wording is now stale for the hosted paths (brave/tavily/exa never read an endpoint). Flipping it is a non-owned-test re-arm + roster numeral job, not a lane edit.
