# REVIEW-1 — lane cc/C-PRV-2.c (azure + openrouter providers)

- Lane tip: e8668b386cff6271ba7a3496e7c5cee65dfd15a1 (single commit; base cf0cfd0be, lead-verified)
- Worktree: /home/sites/cc-cmp-C-PRV-2.c · Reviewer: fresh r1, read-only
- (Lane-tree path prompt_kit/findings/comparison-1026/lanes/C-PRV-2.c/REVIEW-1.md write DENIED by reviewer permission map — fallback copy per brief.)
- Verdict: **APPROVE — 0 CRITICAL / 0 MAJOR / 2 MINOR / 1 NIT**

## Scope audit — CLEAN
15 files, +797/−21, matches REPORT.md numstat exactly (the "33 files" figure did not
correspond to any disk state of this lane). Brief names 13; the 2 extras are justified:
`src/Providers/Concerns/OpenAiResponsesWire.php` (carries the `apiKeyHeader` leg the
design requires) and `tests/Config/Support/EnvReadScanner.php` (§1.8 in-step roster,
AMBIENT_NAMES +2 alphabetical). No weld-only files (suite-figure.json, durations.tsv,
dist/) in the diff. Author/committer both detain@interserver.net (separate %ae/%ce
greps), zero Co-Authored trailers.

## Security — all confirmed on disk
- **api-key never Authorization, both wires.** Chat arm:
  `\OpenAI::factory()->withHttpHeader('api-key')->withHttpHeader('OpenAI-Beta')->withBaseUri`
  — no `withApiKey`, so the SDK never mints Authorization. Responses arm:
  `responsesHeaders()` (OpenAiResponsesWire.php:919) branches `apiKeyHeader !== null`
  FIRST (sets `api-key`), Authorization Bearer only in the `elseif modelsApiKey` leg —
  azure passes `modelsApiKey: null` and the gate order makes Authorization structurally
  unreachable. Sole header source at both post sites (:482 complete, :520 stream).
  Pinned non-vacuously: chat via reflection into HttpTransporter headers
  (assertArrayNotHasKey Authorization — adding withApiKey reddens), responses via
  MockHandler + Middleware::history (`getHeaderLine('api-key')` + hasHeader
  Authorization === false + exact resource URI).
- **Headers injection floor.** CustomProvider ctor validates every config header: name
  must be string, non-empty, ===trim, no ':' (split-smuggling), no
  [\x00-\x1F\x7F] (CR/LF injection), and not owned (authorization/content-type via
  strtolower). Values: string, non-empty, no control chars. Owned-header refusal means
  **config headers cannot override auth headers** — and even the union direction
  (verified: config wins key-for-key over preset for NON-owned names, the design
  ruling) is bounded by the ctor refusal. Pin covers 5 shapes (owned×2, empty name,
  colon name, \r value).
- **One-of refusal wording.** InvalidArgumentException names both keys and the
  template `https://{resource}.openai.azure.com/openai/v1`; pinned via message
  substrings resource/baseUrl/template.
- **Catalog door honesty.** `askableCatalogBase()` returns null when apiKeyHeader set;
  docblock states Azure catalog lives on the control-plane API; catalogKey/catalog
  dials gated; assertNull pin (M2 structure: removing the door reddens exactly it).
- E646: no new timers; no blanket request timeout; existing bounded transports untouched.

## §1.8 roster completeness — all in the SAME commit as code
availableTypes() 9→11 (ordered exact list pinned in renamed
testAvailableTypesReturnsAllElevenTypes), TYPE_SCHEMAS azure+openrouter (+ custom
'headers' optional), defaultConfig arms (AZURE_OPENAI_API_KEY/gpt-4o;
OPENROUTER_API_KEY/openai/gpt-4o), REQUIRED_KEY_ENVS +2, LOGIN_KEY_ENVS 3→5 with
honest docblock seam ("login deliberately does not invent resource") + login match arm,
README eleven-paragraph + 2 table rows, ARCHITECTURE both spellings + 2 rows + echo
'not one of the eleven', ENVIRONMENT +2 rows, ADDING_A_PROVIDER reuse-precedent law,
DocFigure word-map +'eleven'=>11 and the echo needle flipped nine→eleven same commit,
EnvReadScanner +2 alphabetical, ProviderErrorCopyTest +2 env-hint rows.

## Base-reds — PROVEN at file level
- CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers: lang/en.php
  blob 2f99636af8646c10d48b9cd6b151559022e677b2 identical base↔tip (lane never touched
  it); run at tip reproduces the single sorted-run failure (not_added/not_marketplace/
  none/renamed/removed ordering) — 8T/1F, base-inherited.
- ForeignAgentPresetWiringTest permissionMode census (tests/Integration/): file blob
  9d6b3c4d79a28a9d91cba65dfbfda25cc83c0bc2 identical base↔tip; SchedulesCommand.php
  exists at cf0cfd0be with `'permission_mode' => $mode` (line 164) yet the roster list
  lacks 'Host/Commands/SchedulesCommand.php' (grep -c 0) → roster-red by construction
  at base, not lane-introduced.
- ReadmeSuiteFigureDrift staleness pair (25,537 pinned vs 27,239 live) is welder
  re-pin duty; lane correctly did not touch suite-figure.json/durations.tsv.
  Carries disclosed: new file needs durations row, +17T (suite inventory matches).

## Claims re-derived — one discrepancy
- Window re-run with the REPORT's filter verbatim (cwd candy-code, plain pipe,
  -u LINES -u COLUMNS): **530T/43,972A, 2 pre-existing Deprecations, green** —
  the claimed 494T/43,869A does not reproduce under the stated regex (unanchored
  alternation sweeps extra classes; anchored variant gives 402T/43,615A, also green).
  The gate PASSES either way with the claimed 2-deprecation shape; the figures appear
  computed at an intermediate tree state. Report-precision issue, not correctness.
- Mutations M1/M2/M3: not executed (read-only) but re-reasoned from pin structure —
  each maps to exactly one dedicated pin (apiKeyHeader leg→responses header test;
  catalog door→assertNull(catalogKey) test; union order→X-Title per-key-override test
  which also proves HTTP-Referer survival, i.e. not a wholesale-replace collapse).
  Claims plausible-true; discriminating by construction.

## Findings
- MINOR (step-local): CustomProvider header-validation refuses 8 implemented legs;
  malformedHeaderBlocks pins 5. Unpinned-but-implemented: empty value, non-string
  value, untrimmed name, case-variant owned name ('AUTHORIZATION' — handled by
  strtolower, never pinned). Fix: extend the provider with 3-4 rows.
- MINOR (report-precision): window figures 494/43,869 vs reproduced 530/43,972 under
  the stated filter; disclose exact command or accept the re-run as gate truth.
- NIT: ENVIRONMENT.md azure row "provider will reject" — the thrower is
  validateRequiredKeys; mirrors existing GEMINI row wording, convention-consistent.

## Verdict
APPROVE. Real wire-level proofs on both arms, security floors structural + pinned,
rosters and doc numerals moved in-step in the same commit, base-reds honestly
attributed and file-identity-proven, scope clean. No fixes block welding; carry the
two MINORs (test-row widening; window-figure correction) into the weld/gate brief.
