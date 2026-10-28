# C-PRV-2.c — LANDED — commit e8668b386 (branch cc/C-PRV-2.c, base cf0cfd0be)

First-class `azure` + `openrouter` provider types. One commit, 15 files, +797/−21.

## What shipped
- **azure** (reuses OpenAIProvider): base from `resource` template
  `https://{resource}.openai.azure.com/openai/v1` or explicit `baseUrl`
  (one-of enforced in createAzure — schema cannot express it; refusal names
  the template). `api-key` header, never `Authorization`, on BOTH wires:
  chat arm = SDK factory without withApiKey + withHttpHeader('api-key');
  Responses arm = new `apiKeyHeader` ctor flag gating responsesHeaders()
  (OpenAiResponsesWire.php:204 replay-gate neighbour, :923). The flag also
  shuts the catalog door (askableCatalogBase returns null; Azure's model
  list is control-plane, a /models ask would 404 — honest closed catalog).
  `providerName` ctor tail param makes name() return 'azure'. DEFAULT_NAME
  const added; every existing construction byte-identical (tail defaults).
- **openrouter** (CustomProvider preset): OPENROUTER_DEFAULT_BASE const
  (homepage from composer.json:18), attribution pair HTTP-Referer/X-Title;
  model `openai/gpt-4o`; implements HasModelCatalog already (only headers
  were missing per brief). /models untouched.
- **`headers` block on custom+openrouter**: openAiCompatible tail param
  merged Content-Type-first, Authorization built from apiKey still wins
  wire-side; stored via new ctor tail param `defaultHeaders` (assertable)
  with validation: owned names (authorization/content-type) refused,
  empty/colon/control-char names refused, non-string/empty/control-char
  values refused (header-injection floor, plain English per factory copy
  precedent). Config headers win key-for-key over the openrouter preset
  (union left-first).
- Rosters in-step (§1.8): availableTypes 9→11, TYPE_SCHEMAS azure+openrouter
  rows, custom optional +headers, REQUIRED_KEY_ENVS (AZURE_OPENAI_API_KEY /
  OPENROUTER_API_KEY), defaultConfig arms, instantiate match, Subcommands
  LOGIN_KEY_ENVS three→five + store arms, README L196+table rows,
  ARCHITECTURE nine→eleven + rows (doc AND guard literal flipped SAME
  commit), ENVIRONMENT 2 rows, ADDING_A_PROVIDER reuse-precedent prose,
  DocFigure word-map +eleven, EnvReadScanner AMBIENT_NAMES +2.

## Tests (targeted only, plain pipe, -c phpunit.xml)
- NEW candy-code/tests/Providers/AzureOpenRouterTypesTest.php — 17T/45A OK
  (needs a durations.tsv row at gate). Chat-arm pins via SDK reflection
  chain client→transporter→headers/toArray+baseUri; Responses arm via
  MockHandler history seam (api-key present, Authorization absent, URI
  .../openai/v1/responses); refusals, presets, overrides, defaultConfig
  env reads, custom header validation table.
- Gate window (AzureOpenRouter|ProviderFactory|ProviderErrorCopy|
  LoginSubcommand|OpenAIProvider|CustomProvider|OpenAiResponsesWire|
  DocFigureProseDrift|EnvRosterDrift): OK 494T/43,869A (2 deprecations
  pre-existing, AccessorDeprecationTest).
- Guard families (StderrEmitter|BootstrapLaunchFormat|SettingsWriter|
  TreeWideGuardRoster|GlobDialect|DuplicatedTestHelper|ReadmeRoster|
  DuplicatedDocBlock|SymbolCitation|ReadPathCensus|CliLangCatalogue):
  212T, 1F = CliLangCatalogue (BASE-INHERITED, see seams).
- ReadmeSuiteFigureDrift: staleness pair only (25,537 pinned vs 27,239
  live) — welder re-pin duty; forbidden files never touched (verified
  absent from status; committed tree clean).

## Mutations (DoD) — 3/3 one-red, cp+md5 clean, committed blobs md5-verified
M1 neuter responsesHeaders apiKeyHeader leg → wire test 1F; M2 remove
askableCatalogBase door → catalog-shut test 1F; M3 flip header precedence →
override test 1F.

## Seams / base-inherited reds (NOT caused by this step — proof: failing
methods read only lang/en.php (byte-identical to base, git diff empty) or
scan src comment-stripped for permission_mode (this diff contains no such
line; SchedulesCommand.php exists at base))
1. CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers —
   en.php cli block unsorted at base: marketplace.not_marketplace > none
   (@108) and renamed > removed (@110). Belongs to the bundles/marketplace
   lanes' welder.
2. ForeignAgentPresetWiringTest permissionMode reader census —
   Host/Commands/SchedulesCommand.php is an unrostered reader at base.
   Same cure shape as the 243010297-era ScheduleRegistry one (roster with
   adjudication comment).
3. Suite figure + durations row + README headline: welder re-pin (+17T live).
4. azure login stores only the key — resource/baseUrl still need the config
   block; LOGIN_KEY_ENVS docblock says so; a `login azure --resource` flag
   surface is a later ruling, not minted here.

Author/committer detain@interserver.net verified via separate %ae/%ce
greps; no Co-Authored-By. Never pushed; worktree kept for weld.

## FIX-1 (round 2, review r1 MINOR — step-local)
malformedHeaderBlocks widened 5→9 rows, pinning all implemented refusal
legs: + 'case-variant owned name' ('AUTHORIZATION', proves the strtolower
leg — uppercase cannot smuggle the auth header past the owned floor),
+ 'untrimmed name' ('X-Probe '), + 'empty value', + 'non-string value'
(42). AzureOpenRouterTypesTest 17T/45A → 21T/53A green; refusal-adjacent
window (AzureOpenRouterTypes|CustomProvider|ProviderFactory|
ProviderErrorCopy) 265T/744A green, 2 pre-existing AccessorDeprecation
deprecations only. Mutations 4/4 discriminating, cp+md5 clean (src blob
a4f2a5df...): M1 drop strtolower → 3F (all owned rows incl new);
M2 drop trim leg → exactly untrimmed row F; M3 drop empty-value leg →
exactly empty-value row F; M4 drop is_string(value) leg → exactly
non-string row F (TypeError, strict_types). Out-of-scope figure MINOR +
ENVIRONMENT.md NIT: NOT touched — closeout carries.
