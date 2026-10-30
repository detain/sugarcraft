# W25-m1 — ReadPathCensus roster cure: GREEN-ALREADY (negative proof, no commit)

VERDICT: **GREEN-ALREADY** at master tip `671105299`. No fix applied; no commit made.
The WAVE-REVIEW-1.md m1 red (written at the d7a2efbf1-era tip 12e70fdc9) was cured on
master by a later weld before this lane's dispatch.

## Live re-derivation (verbatim gate output)

```
$ cd /home/sites/sugarcraft/candy-code && env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter 'ReadPathCensus'
Tests: 19, Assertions: 729, PHPUnit Deprecations: 2.
OK, but there were issues!
EXIT=0
```

(The 2 deprecations are pre-existing PHPUnit-level notices, not failures; exit code 0
proven in the same shell, log at /tmp/opencode/w25m1-rpc.log.)

## Landed-blob proof that the named rows exist (not vacuous green)

```
$ git show master:candy-code/tests/Support/ReadPathCensusTest.php | grep -n -E 'RemoteSessionPush|ServerConfig'
959:        'Server/ServerConfig.php|file_get_contents' => [
972:        'Session/Import/RemoteSessionPush.php|file_get_contents' => [
```

Both expected shapes from the wave review are present with full adjudication text:
- `Server/ServerConfig.php|file_get_contents` → two CALLER_SUPPLIED rows (C-WEB-5.a
  TLS cert + key pair reads, gated is_file/is_readable + 0o077 mode refusal).
- `Session/Import/RemoteSessionPush.php|file_get_contents` → SELF_LOCATED row
  (C-SES-7.g resolveToken() owner-token second tolerant reader, lstat regular-file
  gate, 64-hex check).

## Tree hygiene

`git status --porcelain -- candy-code/` → empty (candy-code tree identical to master
blob at HEAD 671105299; all working-tree dirt is prompt_kit/.opencode evidence files,
untouched).

## Actions taken

None on tracked files. This evidence file is uncommitted by design (prompt_kit
evidence rides later welds only if briefed). No push.
