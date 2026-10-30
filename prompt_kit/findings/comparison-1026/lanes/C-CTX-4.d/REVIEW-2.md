# REVIEW-2 — C-CTX-4.d (round-1 re-run on the completed chain)

VERDICT: CLEAN

Tip re-anchored from disk (individual rev-parse): branch cc/C-CTX-4.d @ 9c6ad8b73, master = 574e69f1d,
chain master..HEAD = 4 commits: 2571cb466 (guard extraction) / fa74e3a7a (loader URL arm) /
6c86300ea (GlobDialect respell, corpus 559x555 held) / 9c6ad8b73 (docs). Tree clean.
REVIEW-1.md (old CRITICAL "step not landed") cited tip 2571cb466/dirty state and a phantom 9a521a25a;
current tip differs, so this fresh round replaces it. REVIEW-1.md kept, not deleted.

## Scope/traceability — PASS
Diff = exactly 9 files, all named by the brief files-touched list plus two legitimate in-step edits:
- src/Support/PublicUrlGuard.php (NEW, +518, one type, final, private ctor)
- src/Tools/BuiltIn/WebFetch.php (-240, delegation only)
- src/Context/InstructionFileLoader.php (+116, URL arm)
- tests/Support/PublicUrlGuardTest.php (NEW 17T), tests/Context/InstructionUrlSourceTest.php (NEW 7T)
- tests/Support/ReadPathCensusTest.php (+5, in-step roster row `Support/PublicUrlGuard.php|fopen`)
- tests/Tools/ToolSecurityTest.php (-79, two pure range-list cases moved verbatim)
- docs/MEMORY.md (14), docs/SETTINGS.md (17)
No forbidden files touched (suite-figure.json / durations.tsv / candy-code-web dist / protocol trio absent).

## Contract vs design — PASS
- SSRF guard extracted VERBATIM from WebFetch (BLOCKED_HOSTNAMES x3, BLOCKED_IP_RANGES 24 CIDRs,
  canonicalAddress/ipInCidr/resolveViaSystemDns/addressIsBlocked/guardedDialAddress). Mixed-answer
  refusal preserved. WebFetch behaves identically: hop loop reads guard constants; its public statics
  remain one-line delegates — the 5 src/Tools/Web/Search backends + HostedBackendShapesTest callers
  still resolve. Rest of WebFetch byte-untouched (SEC-3, truncation, transferPinned).
- Loader arm: user-tier `https://` fetched once/session pre-fork (forcedCache + per-URL memo,
  refusals memoized too), https-only enforced by guard, private-IP/metadata refused, body fenced
  (PromptFence::escape header + McpOutputFence::neutralise, no-maintainer-authority wording),
  MAX_DOCUMENT_BYTES=61440 bound handed to guard, imports NOT expanded (@CLAUDE.md inert — pinned),
  project-tier URLs refused keyed-as-spelled zero-dial, failures -> refusedPaths -> launch notice,
  never an exception. Traced normal + edge (redirect-into-private, 3xx-no-Location, 404, cap-oversize,
  incomplete-at-bound) by hand — all refused with correct wording; refused hop never dialed (seam count).
- E646: per-read stream-context 'timeout' = FETCH_BUDGET_SECONDS 15, max_redirects=0 fixed dial;
  NOT a blanket request timeout on any LLM path; structural pin asserts no pcntl_alarm/set_time_limit.
- GlobDialect law honored: chr(42) fixtures, respell commit; corpus figure held (gate green).

## Gates reproduced (targeted, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'PublicUrlGuardTest|InstructionUrlSourceTest'`: OK — Tests: 23, Assertions: 205 (17+7 = both new files green).
- `--filter 'PublicUrlGuard|WebFetch|ToolSecurity|HostedBackendShapes|ReadPathCensus'`:
  Tests: 220, Assertions: 1386, Failures: 1 — sole red ReadPathCensusTest RemoteSessionPush row,
  foreign-inherited: proven at master 574e69f1d (src sink at RemoteSessionPush.php:120, no census row);
  zero-touch in this lane's range. Lane's own PublicUrlGuard fopen row present and green.
- `--filter 'Instruction|ContainedPath|GlobDialect|DuplicatedTestHelperDrift|DuplicatedDocBlockLine|DocFigureProseDrift|GlobFigureDrift|ReadmeSettingsTierClaim|ReadmeRosterDrift|MemoryDocumentationDrift'`:
  Tests: 653, Assertions: 9602, Failures: 2 — both ContainedPathInventoryTest roster staleness
  (LaunchContext/EnvironmentBlock/ContextHostCommand/MemoryCommand/TranscriptProjector/PathJail 5v6);
  all six files untouched by master..HEAD (grep -c over lane diff = 0) — foreign welded-file carry,
  not lane-caused. Loader adds no new ContainedPath routed call-sites (inventory unchanged for it).
- Suite-figure/README staleness pair expected-red per plan §7 standing duty (closeout re-pin); no full
  suite run here per §1.5.

## Authorship — PASS
4/4 commits %ae AND %ce = detain@interserver.net (separate greps), zero Co-Authored-By trailers.

## Findings (non-blocking)
- NIT (step-local) src/Support/PublicUrlGuard.php transferPinned: a fread returning ''/false on the
  per-read timeout mid-body while strlen < max breaks the loop with complete=true — a silently
  truncated document is accepted (still fenced as untrusted, so security bound holds; quality edge).
  Cure candidate: treat `$chunk === false` as incomplete. Advisory only.
- NIT (step-local) locationHeader matches a `location:` prefix loosely (e.g. a hypothetical
  `Locationless:` header); real HTTP header names make this unreachable. Advisory only.
- Carry (out-of-scope): durations.tsv rows for the two new test files + suite-figure/README re-pin —
  weld/gate duty per §7; the 3 foreign-inherited census reds (RemoteSessionPush + ContainedPath pair)
  belong to their welding lanes' closeout.

## Mutation-reasoning
Range-list moves, dial-count seams (resolved==[] / dialed==[] before guard refusals), cap-bound
hand-off (seenBound == MAX_DOCUMENT_BYTES), refusal-once memo, and the E646 structural source-slice
pin are all load-bearing: removing the URL arm, the before-resolve guards, or the cap would redden
named tests; neutering per-read timeout/peer_name reddens the structural pin. No fabricated paths:
zero-socket by design (fetcher seam + literal-IP production-guard cases exercise the real guard).
