# C-CTX-4.d — Round-1 review

VERDICT: FINDINGS (1 CRITICAL — step not landed; committed slice-1 is clean)

## Disk state vs claims
- Contract: "tip 9a521a25a, 4 commits ahead, tree CLEAN".
- Disk: tip 2571cb466 (9a521a25a does NOT exist — `cat-file` fails); 1 commit ahead of base
  d9a3dc110; tree DIRTY — `M candy-code/src/Context/InstructionFileLoader.php`,
  `?? candy-code/tests/Context/InstructionUrlSourceTest.php`.
- The lane's own REPORT.md:4 says "Status: IN PROGRESS" and the commit message names the URL
  source as "(next commit)". Only slice-1 (the shared egress guard) ever landed on the branch.

## CRITICAL-1 (step-local) — primary deliverable + docs never landed
The step is named for URL instruction sources; that half is not on the branch.
- (c) `loadForced()` URL arm + `loadRemoteDocument()` are UNCOMMITTED (working tree only).
  `candy-code/tests/Context/InstructionUrlSourceTest.php` (a BRIEF files.create deliverable)
  is UNTRACKED.
- (d) `candy-code/docs/MEMORY.md` and `candy-code/docs/SETTINGS.md` (both BRIEF files.modify)
  are NEVER touched — no URL-source disclosure, no trust-boundary row (§6). Requirement (a)
  fails: 4 of 6 declared files are absent from `git diff d9a3dc110..cc/C-CTX-4.d`.
FIX: commit the working-tree InstructionFileLoader change + git-add InstructionUrlSourceTest;
write the MEMORY.md/SETTINGS.md `instructions` prose (URL sources are user-tier only, https-only,
SSRF-guarded egress, body fenced as untrusted remote, project-tier refused, failure = launch notice);
re-run the gate. (g) durations.tsv weld carry needs BOTH new test files once committed:
`candy-code/tests/Support/PublicUrlGuardTest.php` AND
`candy-code/tests/Context/InstructionUrlSourceTest.php`.

## Verified good — committed slice-1 (2571cb466)
- (b) SSRF parity: BLOCKED_HOSTNAMES / BLOCKED_IP_RANGES / guardedDialAddress /
  resolveViaSystemDns / addressIsBlocked / canonicalAddress / ipInCidr moved VERBATIM from
  WebFetch into PublicUrlGuard; WebFetch delegates (public statics kept as one-line delegates so
  the 5 search backends + reflection pins are untouched; hop loop reads PublicUrlGuard consts).
  Author %ae AND %ce = detain@interserver.net (separate greps), zero Co-Authored-By.
- Moved tests NOT weakened: ToolSecurityTest's two pure range-list cases (23 non-public + 11
  public addresses) are byte-reproduced in PublicUrlGuardTest, and strengthened with the direct
  `guardedDialAddress` dial==null leg; the end-to-end WebFetch leak-recorder cases stay in
  ToolSecurityTest as the delegation wire's own pin. PublicUrlGuardTest adds the fetch-arm
  tightenings (https-only, no URL credentials, per-hop re-guard, relative/non-https Location
  refuse, byte cap, redirect bound, E646 structural pin asserting per-read `timeout` and no
  pcntl_alarm/set_time_limit).
- (e) E646 clean: FETCH_BUDGET_SECONDS is a stream-context per-read `timeout`, not a wall-clock
  kill; no fork/alarm.
- (f) No forbidden touches: suite-figure.json / durations.tsv / candy-code-web/dist / protocol
  trio / generated.ts all absent from the commit; ReadPathCensusTest +fopen row is legitimate
  in-step guard law; ToolSecurityTest edit is the moved-test cleanup.

## Gate (cwd=candy-code, env -u LINES -u COLUMNS, --filter
## 'PublicUrlGuard|InstructionUrlSource|WebFetch|ReadPathCensus|DescriptorInheritanceGuard')
`Tests: 192, Assertions: 6273, Failures: 1, PHPUnit Deprecations: 2.` The 1 failure is
ReadPathCensusTest `Session/Import/RemoteSessionPush.php|file_get_contents` — a PRE-EXISTING
foreign row: that src file exists at base d9a3dc110 and carries 0 READ_PATHS rows there too
(lane touched neither the file nor that roster line). Not lane-attributable; disclose. All
PublicUrlGuard + InstructionUrlSource cases in the working tree pass.

## Note (not a finding)
Staleness pair 25,537-vs-28,40x is outside this filter = expected standing reds, not reviewed.
