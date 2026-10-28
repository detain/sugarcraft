# C-MCP-4.c — lane report

## Round 1 (reconstructed — evidence lost)

No `BRIEF.md`, `REVIEW-1.md`, or round-1 `REPORT.md` exists on disk, and the
recorded build tip `183060634` is a GHOST: `git cat-file` fails, no branch or
dangling object carries it. What DID exist on disk at round-2 start was the
complete round-1 build UNCOMMITTED in `/home/sites/cc-cmp-C-MCP-4.c` (HEAD ==
base `e2e05598d`, 7 modified files, 210+/7−): the step's shipped gate —
`AuthEntry::redirectUri` (eleventh on-disk key), the `validAuthFor()`
callback-binding verdict (stored binding is authority; a live flow presenting
a different loopback binding is answered null), the `login` persistence leg
in `OAuthLoopbackFlow`, the `entryFromRefresh` carry, the MCP.md §
Authorization-code login paragraph, the eleven-key roster pins, and FOUR new
store-level tests including the mismatched-URI refusal pin. Repair per the
standing protocol: disk-only fixer completed commit + report; the round-1
findings were taken from the orchestrator's round-2 brief (three asks: (a)
mismatch red proof + mutation-reason, (b) §1.8 guard moves, (c) legacy
migration untouched).

## Round 2 (this fixer, 2026-10-27)

Commit: `a2d61eab9` on `cc/C-MCP-4.c` — `C-MCP-4.c: review fixes (round 2)`.
Because the round-1 commit never existed, this single commit carries the
whole lane (base e2e05598d → tip). Author/committer `%ae`/`%ce` each
`grep -c detain` == 1; zero Co-Authored trailers; plain `-m`; explicit paths;
no caliber (no hook). NEVER pushed.

### Findings → disposition

- **(a) mismatched-URI red proof** — PRESENT on disk in the round-1 build
  (`OAuthClientRegistrationTest::testValidAuthForRefusesALiveEntryBoundToADifferentCallback`,
  `assertNull` on binding `…:52000` against stored `…:41239`) and VERIFIED
  load-bearing by the mutation matrix below. No src or test change was needed;
  adding a duplicate pin would violate fix-only-the-findings. The build was
  already the red proof; it had simply never been committed.
- **(b) §1.8 guard moves** — the guard the eleven-key growth touches is the
  roster pin itself: `OAuthAuthorizationCodeExchangeTest`
  (`…IsTheTenKeyRoster` → `…ElevenKeyRoster`, array + message updated) and
  `McpAuthStoreLoginTest` (`…PersistsTheTenKeyEntry` → `…ElevenKeyEntry` +
  byte-identity persistence leg). Both moved in-step in the same commit. No
  method names are string-pinned anywhere (grep clean). MCP.md prose added no
  figure/count a DocFigure arm reads — full DocFigure family green (below).
  Protocol/generated surfaces untouched; no lang/env/setting/tool added, so
  Env/TrustKey/ConfigWrite/KeyBinding/Readme rosters are not in play.
- **(c) legacy-migration decision** — UNTOUCHED, as instructed. No reviewer
  security regression was provable: an absent persisted binding is not a
  secret-bearing difference (the trust story is the port-scoped loopback
  binding itself), and the decision is pinned twice
  (`testValidAuthForServesAnUnboundRowToAnyFlowBinding`,
  `testALegacyOnDiskRowWithoutTheBindingKeyStillLoadsAndPassesAnyFlow`),
  each arm-discriminating (M2 below).

### Mutation evidence (cp+md5 restores verified each round)

- **M1** — delete the whole binding-verdict `if` block (the equality arm as
  the gate unit): exactly ONE red leg, the mismatched-URI `assertNull`
  (6T filtered, Assertions 54→52, Failures: 1). Restores green.
- **M2** — delete only `&& $entry->redirectUri !== ''`: exactly the TWO
  migration pins red (unbound-row + legacy-on-disk-row), nothing else.
- **M3** — neuter `$currentFlowRedirectUri !== null` (→ `true`): exactly ONE
  red, the single-arg attach-path leg inside the mismatch test — the
  request-attach callers (`HttpMcpServer.php:968`, `SseMcpServer.php:842`,
  both one-arg by design and now by prose) stay byte-exact.
- Honest split: deleting the third conjunct ALONE (not the block) reddens TWO
  legs (the same-binding pass leg in the mismatch test and the refresh
  same-binding serve leg) — both hold a same-URI-pass shape, which is belt
  braces, not a flaw.

### Gates (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS)

- New/changed pins filter (6 methods): 6T/54A green.
- `MCP|Sse|Login|BuiltInToolCorpus|ToolDocDrift`: **2225T/28321A, 0F/0E**,
  2 PHPUnit deprecations + 1 skip — base-consistent (far larger than the
  brief's ~335T expectation; the filter just matches more than estimated).
- Five-guard: 133T, exactly the STALENESS PAIR red (figure 25,537 vs live
  26,285) — welder's re-pin duty, expected shape for every late-wave lane.
- Adjacent census (`StderrEmitterCensus|TreeWideGuardRoster|DescriptorInheritanceGuard|ReadPathCensus|ForeignAgentPresetWiring`): 163T, 2F — both
  TreeWideGuardRoster legs accuse `tests/Context/MemoryRecallBlockTest.php`
  only: FOREIGN-INHERITED (file unmodified in this lane, landed 16266186d
  C-CTX-6.c pre-base; walker-site roster drift is the known foreign red
  family). Report-only; not this lane's to cure.

### DUR / generated

- NEW `*Test.php` files: **none** (no durations.tsv row owed).
- suite-figure.json / dist: untouched (welder owns).

### Seam disclosed (not widened)

No production caller passes a non-null `$currentFlowRedirectUri` yet — the
gate ships at the store, armed for the flow callers of later/parallel legs
(the docblock and MCP.md say exactly who passes null and why). Per §6.1 and
the fixer mandate, wiring new callers is out of this step's ruling scope.
