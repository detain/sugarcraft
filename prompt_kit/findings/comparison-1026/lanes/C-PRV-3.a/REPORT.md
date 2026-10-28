# C-PRV-3.a — DONE — commit f59fff349 (cc/C-PRV-3.a, base e68270a09, never pushed)

`serviceTier` setting (default|fast, RiskClass::Spend, layered, env CANDY_CODE_SERVICE_TIER) + `Providers\ShapesServiceTier` (WORD_DEFAULT/WORD_FAST; serviceTierFor/withServiceTier) + provider overlay. 13 files, +1163/−32.

- **OpenAIProvider** rostered: `service_tier:"priority"` on chat AND responses arms (complete/completeStream legs + class-side `responsesBody` override over aliased trait wire); answers for every model (API-side 400 prose beats local guess).
- **AnthropicProvider rostered — API-doc gate CONFIRMED** (platform.claude.com): Messages `speed: standard|fast`; FAST_MODE_FAMILIES = claude-opus-5-5 (GA, no header), claude-opus-5 + claude-opus-4-8 (research preview, auto `anthropic-beta: fast-mode-2026-02-01` via per-request `fastModeRequestOptions`); other families send nothing. withModel/withServiceTier rebuild default transport per clone (stale-$this-closure cure).
- **Risk note honored**: while a tier is armed the pair's price door answers null → existing unpriced-notice ledger path, never silent 0.0 (pair-precise on Anthropic). Tier-keyed pricing = C-PRV-3.b seam.
- **EngineBackend**: word carried ctor→mutate; `withServiceTier()` stores word + re-mints provider leg; `withModel()` re-resolves the overlay for the new model.
- **LaunchContext**: `resolvedServiceTier()` (env first, config second, vocab-checked) + `withResolvedServiceTier()` at the backendFor tail; 'default' never refuses; non-shaper or non-fast pair → engine UNTOUCHED + honest refusal sentence (`chat.serviceTier.launch_unshaped`). Gate-answers-false pinned.
- **Tests**: ServiceTierTest 28 pins (roster walk, both wire arms, speed family matrix, beta-header matrix, overlay/rebind, launch door matrix, price doors). Mutations: strip chat legs→3F; empty FAST_MODE_FAMILIES→5F; neuter price gate→1F; all cp+md5 restored.
  [ERRATUM 2026-10-28 r2, review-1 MAJOR-3: "28 pins" was never live — ServiceTierTest measured
  25 methods/77A at f59fff349, 26T/83A after the r1 azure pin (restamped in FIX ROUND 1 below),
  29T/96A after FIX ROUND 2. The mutation-F counts stand as recorded.]
- Gates: ServiceTier OK 28T/87A; named filter ServiceTier|EngineBackend|RebindsModel|LaunchContext|SettingsSchema|EnvRoster|TrustKey|ModelPrices|Spend|DocFigure → 824T, only red = ChatTest:4325 **proven red at pristine e68270a09** (tmp-copy harness); neighborhood Provider|… → only MediaSettingsTest:254, **also red at base**. EnvRoster 38T green. gen-settings-doc --check rc0.
  [ERRATUM 2026-10-28 r2, review-1 MAJOR-3: the single-file "28T/87A" was the ServiceTier filter
  family miscounted — live 25T/77A at f59fff349; and "EnvRoster 38T" is 37T/39055A (measured again
  at the r2 tip). The 824T window figure and the two base-proven reds were not re-litigated here.]
- Disclosures: gen-settings-doc --write ran in-step (SETTINGS/README/LayeredSettings region; C-TOOL-5.a precedent); ENVIRONMENT.md row hand-minted (generator skips that page); TrustKey README enumeration + wither-roster guards flipped in-step (§1.8); new lang keys en-only (sole locale); Bootstrap untouched — refusal reason discarded at tail (Echo-fallback path never arms by construction).
- Welder: durations.tsv row for ServiceTierTest; suite-figure re-pin (staleness pair expected); C-PRV-3.b seams: launch-notice delegate+roster, tier-keyed pricing tables, live applySettings wiring/UI, status-line word.

## FIX ROUND 1 (fixer, 2026-10-28)

Fix commit: bff8226b8 ("C-PRV-3.a: review fixes (round 1): azure service_tier honesty") on f59fff349.

MINOR-1 (azure rides OpenAIProvider — service_tier:'priority' reached deployments undocumented):
CURE = (b) providerName gate, NOT (a) docs clause. WHY: cure (a) required Azure
accepting service_tier verified from wire-doc cites already in the lane — zero exist.
Evidence: f59fff349 commit body empty; the only http/doc cites in-lane are
OpenAIProvider::SDK_DEFAULT_BASE (api.openai.com root) + the curl.se error-parsing
docblock + OpenAiResponsesWire's api-key-auth-header note + createAzure's auth/base
comment. None records service_tier on the Azure endpoint; a guessed doc claim is worse
than the gate. Shape: new `OpenAIProvider::AZURE_NAME = 'azure'` const + serviceTierFor()
answers null when providerName === AZURE_NAME (public leg byte-unchanged: still answers
'priority'); docblock truth-flipped (old "every model it speaks for" -> documented public
leg + gated Azure leg with the why: wrong premium guess on a third-party provisioning
could 400 or bill a lane absent from the caller's agreement; launch door turns null into
the loud chat.serviceTier.launch_unshaped row naming `azure`).

Test pin: ONE new method ServiceTierTest::testTheAzureLegOfTheOpenAiWireShapesNoTierAndRefusesWithARow
(null-answer + openai control leg + untouched-engine refusal with reason naming azure).
Mutation: neutered the gate early-return -> exactly 1 failure = this new pin; cp-restored,
md5-verified, re-green.

Gates (cwd candy-code, env -u LINES -u COLUMNS ... -c phpunit.xml --filter, plain pipe):
- 'ServiceTier|EngineBackend|LaunchContext|SettingsSchemaDocDrift|TrustKey|EnvRoster':
  486T/42224A/0F/0E, 2 pre-existing PHPUnit deprecations (suite shape at f59fff349).
  No excluded families (ReadmeSuiteFigureDrift/PermissionsIntroDrift/ChatHostLangCatalogue)
  fall inside this window.
- 'ServiceTierTest' single file: 26T/83A (was 25T/77A at f59fff349 — the draft review's
  live figures were right; my earlier REPORT claim of 28T/87A was the ServiceTier filter
  family miscounted, restamped here per NIT).

NITs untouched as instructed: launch_unshaped tail-discard stays the declared C-PRV-3.b seam.

WELD NOTE (batch-7): take f59fff349 + bff8226b8. durations.tsv needs ONE row for
ServiceTierTest.php (new file from f59fff349; the fix adds a method, not a file).
plan §4 cites the fix hash bff8226b8 for MINOR-1. Out-of-scope observations relayed
(not fixed here): the /tmp draft review's MAJOR-1 LayeredSettingsTest:69 roster-red is
CONFIRMED live at tip (--filter LayeredSettings 109T/1F; welder carry per that review's
own stamp), and MAJOR-2 FallbackProvider set-but-ignored is real by inspection
(LaunchContext unwraps primary, which never carries ShapesServiceTier on that path).

## FIX ROUND 2 (fixer-2, 2026-10-28) — on bff8226b8, tip recorded in git log -1

MAJOR-1 (TREE RED) — LayeredSettingsTest::userTierOnlyKeys() roster literal lacked the
key, so `--filter LayeredSettingsTest` was red at :67 (1F) at this branch's tip.
- CURE: inserted `'serviceTier'` alphabetically between `'reasoningEffort'` and
  `'promptCache'` in tests/Config/LayeredSettingsTest.php (~:68) AND added
  `` `serviceTier` `` to the user-tier enumeration sentence in the src/Config/LayeredSettings.php
  class docblock (~:929) — §1.8 in-step.
- DISCLOSURE (second, FOREIGN cause of the same red): the same literal also lacked
  `'requireReadBeforeWrite'`, added to LAYERED_KEYS by ca69cb333 (C-TOOL-5.a) with the roster
  never re-armed. Proven red at pristine base: `git archive e68270a09` → /tmp/opencode/prv3-base,
  `--filter testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet` → 1F there too.
  The named tree-red could not clear without it, so this lane inserted the key as a data
  element ONLY (no behavior touched); owning-lane/closeout attribution owed.
- Result: 99T/393A/0F (was 99T/234A/1F). Note: the review's "109T" figure is the
  'LayeredSettings' filter family; the named test file alone is 99T.

MAJOR-2 (money setting set-but-ignored under fallback) — withResolvedServiceTier()
unwrapped FallbackProvider to its primary before the shapes gate, the engine-side overlay
gates on `$provider instanceof ShapesServiceTier` (EngineBackend::withServiceTier :933,
:909) and FallbackProvider (:52) does NOT carry the marker. With fallbackModels configured
(ProviderFactory:244 production path) the door answered "shapes", the tier rode nothing,
and no refusal fired.
- CURE (LEAN, per review's option (b)): dropped the unwrap at src/Cli/LaunchContext.php
  (~:2198). Gate is now `$provider instanceof ShapesServiceTier && $provider->serviceTierFor(...)`;
  a FallbackProvider therefore falls to the existing launch_unshaped refusal, with the
  {provider} parameter naming the wrapper `fallback(<primary-name>)` — the bare primary name
  would accuse a wire that does carry the field, because FallbackProvider::name() answers FOR
  the primary. Docblock truth-flipped (tier is a provider leg, unlike effort which rides the
  request; the old comment copied the effort door).
- Why NOT the delegate cure (option (a)): a block spans several provider+model PAIRS whose
  fast-eligibility differs per family (OpenAI answers for every model, Anthropic for three);
  one serviceTierFor() answer chosen at the primary could arm a premium on a model that never
  asked. Per-pair arming through withModel/withServiceTier re-wraps is a real change with its
  own pins = C-PRV-3.b seam (recorded here, NOT taken).
- Pins (both legs, ServiceTierTest): testAFastTierOnAFallbackBlockRefusesWithARowNamingTheWrapper
  (shaping primary gpt-4o + fallback leg → engine UNTOUCHED, serviceTier() null, reason names
  'fallback(openai)'/'gpt-4o'/'left off this launch'); testAFallbackBlockOverANonShapingPrimaryRefusesTheSameRow
  ('fallback(echo)'); testTheDefaultWordLandsOnAFallbackBlockWithNoRefusal ('default' never
  refuses). FallbackProvider::class added to testTheRefusalSetCarriesTheMarkerNotAtAll.
- Family sweep: every remaining ->primary() unwrap is effort-side or detection-side and SOUND
  by construction (effort rides the CompleteRequest, not a provider leg; primaryProvider() answers
  "which server"), so no in-scope follow-up: EngineBackend::withResolvedReasoningEffort :2114,
  EffortGate:40/:68, Chat:7969/:12702 all left byte-unchanged.

MINOR-3 (report truth): the DONE-block "28 pins"/"28T/87A"/"EnvRoster 38T" claims carry dated
errata above (measured: 25T/77A at f59fff349; 26T/83A at bff8226b8; EnvRosterDriftTest 37T/39055A).

GATES (cwd=candy-code, `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter X`,
plain pipe; 2 pre-existing PHPUnit deprecations everywhere):
- ServiceTierTest: 29T/96A/0F (+3T/+13A vs bff8226b8's 26T/83A).
- LayeredSettingsTest: 99T/393A/0F (was 1F).
- 'ServiceTier|LayeredSettings|EngineBackend|LaunchContext|SettingsSchemaDocDrift|TrustKey|EnvRoster':
  595T/42668A/0F.
- Guard family 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|
  DuplicatedTestHelperDrift|SymbolCitationDrift|DuplicatedDocBlockLine|TreeWideGuardRoster':
  164T/37718A/2F = ONLY the suite-figure staleness pair (pinned 25537 vs live 27493 — foreign-era
  drift plus this round's +3T; re-pin is welder duty, suite-figure.json untouched here).
- Neighborhood 'FallbackProvider|Effort|Launch': 773T/5184A/1F = ChildStderrCaptureTest —
  PROVEN pre-existing: isolated it gives 6T/406A/2F identically at the pristine e68270a09 copy.

MUTATION PROOFS (cp+md5 restore, /tmp/opencode/cc-cmp-C-PRV-3.a-fix2/):
- M1 re-adding the unwrap → exactly the 2 fallback-refusal pins red (29T/90A/2F).
- M2 neutering the fallback-naming branch (`$named = $provider->name()`) → exactly the same
  2 pins red (29T/93A/2F); restore md5-verified.
- M3 dropping 'serviceTier' from the roster literal → exactly
  testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet red (99T/234A/1F); restore
  md5-verified.

WELDER CARRIES (round 2): durations.tsv still needs its ONE ServiceTierTest.php row (new file
from f59fff349; method-count moves never mint rows); suite-figure.json + README re-pin now +4T
total over the 25537-vs-27493 foreign staleness (25→29 methods across f59fff349..tip);
the requireReadBeforeWrite roster insertion is a DATA-ONLY cure of C-TOOL-5.a's un-armed
roster — attribute accordingly; ChildStderrCaptureTest 2F base-proven, not a regression.
