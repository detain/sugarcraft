# C-WEB-5.a — FIX-1 (fixer round 1)

Base tip: 28bed9aa6 (rev-parse verified individually). Tree clean at start.

## Finding fixed (the only step-local requirement)

REVIEW-1 MINOR-4: `Serve.php:1339-1342` — the `cli.serve.announce.remote`
cleartext warning is gated on `!isLoopback && !hasTls` with zero pin.
Cure as prescribed: reflection-invoke `announce()` covering BOTH polarities.

### Implementation

`candy-code/tests/Cli/ServeArgsTest.php`:
- NEW `testTheCleartextRemoteWarningIsAnnouncedOnlyForPlainRemoteBinds` —
  three announce runs: remote plain (row present, exactly once), remote +
  TLS pair (row absent), loopback plain (row absent). Each asserts the
  `candy-code serve: listening on` line first, so a dead child fails loud
  instead of vacuously satisfying the not-contains.
- NEW private helper `announceStderr(list<string> $argv): string` —
  `Serve::announce` writes through `fwrite(\STDERR, ...)`, which cannot be
  redirected in-process; the reflected call runs in a throwaway `php` child
  with `2>` capture (house stderr-routing shape, cf.
  BootstrapLaunchNoticeRoutingTest::launch; HOME pinned to the scratch dir so
  `Serve::config` defaultStateDir resolves; `timeout -s KILL 20` bounds it —
  test-side only, E646 clean). Script + capture live in the per-class scratch
  dir already swept by tearDown.
- Extracted `tlsScratchDir()` from `writeServeArgsTlsPair` (shared lazy dir;
  pair-writing behavior byte-unchanged).
- Chose ServeArgsTest (no dedicated announce test existed — grepped) so no
  new durations.tsv row is minted; the file's existing row carries the method.

### Mutation proofs (cp backup + md5/`git status` clean restore)

- M1 `!$config->isLoopback() && !$config->hasTls()` → `!$config->isLoopback()`:
  the new test reds at the TLS polarity assertion (ServeArgsTest.php:407). 1F.
- M2 → `!$config->hasTls()`: reds at the loopback assertion (:411). 1F.
  Both halves of the gate load-bearing and pinned; restore proven clean.

## NITs taken (in-lane test files only)

- N1: TlsListenerTest.php:162 curly apostrophe respelled — first attempt
  (straight ’→') broke the single-quoted string (lint red), so the message
  was respelled to "reads the TLS state of the listener itself" (no apostrophe).
  ServeArgsTest had no curly hit. N2 (Help.php erratum) + per-hop-redirect
  seam: NOT this lane's (weld-note / other-lane) — untouched.

## Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter ServeArgsTest`: 42T/213A OK (was 41T/204A at review; +1T/+9A).
- `--filter TlsListenerTest|GlobDialect|DuplicatedTestHelperDrift|LangParity|ServerProtocolDocumentationDrift|ReadmeRosterDrift`: 55T/4002A OK.
- `--filter GlobDialect|DuplicatedTestHelperDrift|ReadmeSuiteFigureDrift|ClientAddressTest|RequestIngressTest`: 36T/662A, 2F = staleness pair ONLY (25,537 pinned vs 28,457 live — standing §7 lag; live figure +1 = this test).
- "PHPUnit Deprecations: 2" rides every run (pre-existing config-level).

## Carry for the welder (updated)

- DUR row TlsListenerTest + re-pin +7T/+125A still stand (review §Forbidden
  files); this fix adds **+1T/+9A** (ServeArgsTest method, no new file → no
  new durations row).
