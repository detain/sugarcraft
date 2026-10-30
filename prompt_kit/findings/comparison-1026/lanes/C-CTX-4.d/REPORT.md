# C-CTX-4.d — URL instruction sources + shared egress guard (lane report)

Branch cc/C-CTX-4.d @ cut d9a3dc110. Worktree /home/sites/cc-cmp-C-CTX-4.d. Status: LANDED — 4 commits on cc/C-CTX-4.d, tree clean at 9c6ad8b73.

## Design (settled from brief + exploration)

- **PublicUrlGuard** (new src/Support): holds BLOCKED_HOSTNAMES, BLOCKED_IP_RANGES,
  addressIsBlocked/canonicalAddress/ipInCidr/resolveViaSystemDns (moved VERBATIM out of
  WebFetch), the mixed-answer guardedDialAddress decision, and a guarded `fetch()` arm for
  instruction sources: https-only (brief limit "HTTPS only"), userinfo refused (loader channel
  only — WebFetch keeps forwarding credentials byte-unchanged, it never refused them: verified
  WebFetch.php:683), per-hop fixed dial (Host header + ssl.peer_name), per-read 15s socket
  budget (E646-clean transport bound, same shape as WebFetch::READ_TIMEOUT_SECONDS),
  max 3 redirects, absolute https Locations only (relative → refusal; no resolveReference
  duplication into Support), body abandoned past maxBytes mid-stream.
- **WebFetch**: delegates. public statics resolveViaSystemDns/addressIsBlocked stay as one-line
  thin delegates (5 src/Tools/Web/Search backends + HostedBackendShapesTest:209 +
  ToolSecurityTest reflection pin call them — untouched), private consts aliased to the guard's,
  guardedDialAddress body → guard call passing its two seam closures. Everything else
  (hop loop, transferPinned, resolveReference, truncation, SEC-3 rules) byte-untouched so its
  suite stays green — that IS the delegation pin.
- **InstructionFileLoader::loadForced()** URL arm: `^https?://` patterns intercepted at the top
  of the per-pattern loop. Project tier → refusedPaths (repo must never dial out). User tier →
  private loadRemoteDocument(): per-URL session memo (fetched once, failures memoized too),
  MAX_DOCUMENT_BYTES cap enforced by the guard's transfer bound, body scrubbed via existing
  utf8Document then fenced with the established untrusted-remote idiom (McpOutputFence::neutralise
  + provenance header via McpOutputFence::headerName — Runtime.php/PromptFence.php are not in my
  files list). Failures land in refusedPaths → existing launch-notice drain.
  ContainedPath routed-call-sites stays NINE (network read is not a contained path).
- Seam: trailing optional ctor param `?callable $remoteFetcher = null` (Bootstrap uses named
  args; append-safe).

## ReadPathCensus

New `Support/PublicUrlGuard.php|fopen` sink → roster row 'NOT_A_FILESYSTEM_PATH' lands IN-STEP
(slice-1 commit), matching the WebFetch/SearchBackends rows.

## New test files (gate mints durations rows — lane never touches the TSV)

- candy-code/tests/Support/PublicUrlGuardTest.php
- candy-code/tests/Context/InstructionUrlSourceTest.php

## Commits

- **2571cb466** `C-CTX-4.d: extract the SSRF egress rule into Support\PublicUrlGuard`
  (cat-file'd: 2571cb466a69c74ad727f80b927da36f6de0cfbc; A/C both detain@interserver.net,
  zero Co-Authored lines). Files: src/Support/PublicUrlGuard.php (new),
  tests/Support/PublicUrlGuardTest.php (new, 17T), src/Tools/BuiltIn/WebFetch.php
  (delegates: consts removed, hop loop reads PublicUrlGuard::BLOCKED_HOSTNAMES,
  guardedDialAddress→guard passing both seams, public statics resolveViaSystemDns/
  addressIsBlocked kept as one-line delegates for the 5 search backends + suite
  reflection pins; canonicalAddress/ipInCidr deleted with their callers),
  tests/Tools/ToolSecurityTest.php (two pure range-list cases moved out verbatim,
  cluster headers truth-flipped; end-to-end WebFetch cases stay — delegation pin),
  tests/Support/ReadPathCensusTest.php (row 'Support/PublicUrlGuard.php|fopen'
  NOT_A_FILESYSTEM_PATH, in-step).
- Gate @ this commit: filter
  'PublicUrlGuard|ToolSecurity|HostedBackendShapes|WebSearchTransport|ReadPathCensus|DuplicatedTestHelperDrift|DuplicatedDocBlockLine'
  → 168T/1460A, ONLY red = ReadPathCensus census arm on
  'Session/Import/RemoteSessionPush.php|file_get_contents' — PRE-EXISTING foreign
  red at the cut (that welded C-SES-7.g src file carries the sink with no roster
  row; proven failing on HEAD-untouched tree same run; attributed, not fixed).
  PublicUrlGuard/ToolSecurity/HostedBackend/WebSearch/guards all green, 2 PHPUnit
  deprecations pre-existing (config-level, unrelated).

- **fa74e3a7a** `C-CTX-4.d: fetch URL forced-instruction sources through the shared guard`
  (cat-file'd: fa74e3a7afbcb0633f52a946f377d1513034f786; A/C detain, zero Co-Authored).
  Files: src/Context/InstructionFileLoader.php (loadForced() URL arm above the
  absolute/~ split: project-tier URL refused with the tier law spelled, user-tier
  https/http entry routed to private loadRemoteDocument() — session memo incl
  refusals, ?callable remoteFetcher seam (trailing ctor param, Bootstrap named
  args unaffected), cap handed as MAX_DOCUMENT_BYTES, body utf8Document'd then
  fenced: REMOTE_DOCUMENT_HEADER_FORMAT provenance line (URL flattened via
  PromptFence::escape) + McpOutputFence::neutralise; remote @imports stay inert —
  expandImports NOT applied; ContainedPath routed-sites stays NINE: a network
  read is not a contained path), tests/Context/InstructionUrlSourceTest.php (new,
  7T: fetched-once+fenced+role-label defanged, import-inert, project-tier refused
  zero dial, cap handed to guard, failure refused once, production guard refuses
  169.254/100.100/http://localhost literals, url+file coexist).
  Deviation disclosed vs design note above: McpOutputFence::headerName() is
  PRIVATE, so the loader flattens the URL into its own REMOTE_DOCUMENT_HEADER_
  FORMAT via PromptFence::escape — same idiom shape, header text names the
  no-maintainer-authority law explicitly.

- **6c86300ea** — see next block — (fix commit, GlobDialect law).

## GlobDialect attribution (was MINE, fixed in-step)

Broad gate at slice 2 reddened GlobDialectDifferentialTest::testTheCorpusFigure-
InTheDocBlockIsTheDerivedOne (561x557=312,477 vs quoted 559x555=310,245). File-
copy base harness (cp-move my 7 files, probe corpus() at both states — no stash
touched): base derives EXACTLY the quoted 559x555 → red was caused, not
inherited. Set-diff: my '#^https?://#i' regex literal (guard + loader) and the
test's '/.git/*' fixture were glob-shaped ('?'/'*' with no sentence-exempt char)
and joined the harvested corpus. Re-spelled as wildcard-free strtolower+
str_starts_with prefix tests and chr(42) concatenation.
Commit **6c86300ea** `C-CTX-4.d: keep the harvested glob corpus at 559 x 555
(GlobDialect law)` (cat-file'd: 6c86300ea4489e91fc7923cdf227aa57d3fbf394;
A/C detain). Derived corpus re-probed to 559x555; PathGlob doc-block UNTOUCHED
(shared file, zero doc move needed — behavior byte-identical).

## Docs slice

- **9c6ad8b73** `C-CTX-4.d: document the URL forced-instruction source`
  (cat-file'd: 9c6ad8b73ff48227c9d422867466942947c38a29; A/C detain,
  zero Co-Authored). candy-code/docs/MEMORY.md forced-patterns bullet extended
  (URL form: user-tier only, once/session pre-fork, guard refusals spelled,
  size budget + fence + no-maintainer-authority, launch-notice keyed failures);
  candy-code/docs/SETTINGS.md new paragraph after the trust-list paragraph
  (same law in settings vocabulary, every bound cited by CONSTANT NAME —
  deliberately no ungenerated numerals). Doc-guard gate at this tree: filter
  'DocFigureProseDrift|GlobFigureDrift|ReadmeSettingsTierClaim|ReadmeRosterDrift|
  MemoryDocumentationDrift|Instruction' → 559T/8883A GREEN (only the 2
  pre-existing config-level PHPUnit deprecations).

## Final gate @ 9c6ad8b73 (branch tip)

filter 'PublicUrlGuard|WebFetch|Instruction|Forced|ToolSecurity|HostedBackend-
Shapes|WebSearchTransport|ContainedPath|ReadPathCensus|GlobDialect|
DuplicatedTestHelperDrift|DuplicatedDocBlockLine' → 728T/4146A, 3 failures,
ALL PROVEN INHERITED by the same base harness run (identical 3 reds with my
7 files swapped out at d9a3dc110):
  1-2) ContainedPathInventoryTest (AgentMemory.php=>1, SkillLoader.php=>3,
       PathJail 5-vs-6 — foreign welded files, zero diff in my range);
  3) ReadPathCensusTest (RemoteSessionPush.php|file_get_contents — C-SES-7.g).
Suite-figure staleness pair NOT in this filter (gate duty): my net live-enum
movement is +22T (PublicUrlGuardTest +17, InstructionUrlSourceTest +7, minus
the 2 ToolSecurityTest methods moved into the guard file). New *Test.php files
for the durations TSV (gate mints rows; lane never touches it):
  - candy-code/tests/Support/PublicUrlGuardTest.php
  - candy-code/tests/Context/InstructionUrlSourceTest.php

## Branch ledger (pick range d9a3dc110..9c6ad8b73)

1. 2571cb466  guard extraction (WebFetch delegates, byte-green pins)
2. fa74e3a7a  loader URL arm + fetch/fence/cache/refuse
3. 6c86300ea  GlobDialect-law respell (corpus 559x555 restored)
4. 9c6ad8b73  docs MEMORY.md + SETTINGS.md

E646 honored: no wall-clock kill anywhere; the only bound is the per-read
stream timeout already shipped in WebFetch's shape (guard FETCH_BUDGET_SECONDS
rides it as connect/read, structural pin asserts no pcntl_alarm/set_time_limit).
No proc_open added (DescriptorInheritanceGuard untouched). No lang keys added
(guard/loader refusals follow the existing raw-English tool-refusal precedent).
