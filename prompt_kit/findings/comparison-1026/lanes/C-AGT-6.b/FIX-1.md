# C-AGT-6.b — FIX-1 (round 2)

Branch cc/C-AGT-6.b: single amended commit 3abfb69ef -> dbb4a129d (base bb8d741c2,
master..HEAD = 1). %ae/%ce separate greps 1/1 detain, zero trailers, porcelain 0,
file set unchanged (6 declared).

MINOR fixed — src/Attachments/ContextMentions.php (diffRange, now :415/:438/:441):
all three range captures end in '--' (git's "everything before me is a revision"
line). Measured on a fixture repo: `git diff HEAD f.txt` rc=0 (defect: working-tree
path-diff attached under the committed-changes header); `git diff HEAD f.txt --` and
`git diff --merge-base ... HEAD f.txt --` rc=128 "fatal: bad revision" (refusal);
`git diff base HEAD --` / `--merge-base ... base HEAD --` rc=0 (rev pairs unaffected).
docblock records the WHY. /review was already safe (side fails rev-parse ^{tree}).

Pin — tests/Attachments/ContextMentionsTest.php::testAPathSideOfARangeRefusesInsteadOf
AttachingAWorkingTreeDiff: dirty tracked app.php; both '@diff:HEAD..app.php' and
'@diff:HEAD...app.php' assert no attachments + 'git could not diff against <ref>'
notice; plus structural substr_count legs (2 x [$from,$to,'--'] + 1 x [$ancestor,
$to,'--']) so the pre-2.31 fallback tail — behaviorally unreachable with a path side
(its merge-base step refuses first) — is still watched.

Discrimination (/tmp cp harness, no git stash; src restored md5-verified each round):
- full pre-cure revert -> pin RED, failure dump shows the mislabeled working-tree
  diff ('+echo working-tree only') attached (red-before-cure proof);
- merge-base tail neutered -> exactly the three-dot leg RED (attachment dumped);
- fallback tail neutered -> structural leg RED ('0 is identical to 1');
- two-dot tail covered by the full-revert red (first foreach leg).

Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml, PtyRun never):
- ContextMentionsTest 20T/188A OK (was 19T/172A, +1 new);
- ReviewCommandTest 17T/122A OK;
- --filter 'CommandDocsDrift|CommandRegistryTest|ReadmeRosterDrift' 33T/245A OK
  (2 PHPUnit deprecations — reproduced IDENTICAL at HEAD with both files reverted,
  pre-existing);
- combined 37T/310A OK; php -l both files clean.

NITs applied (main-tree REPORT.md wording only, uncommitted): CM "+6 tests" -> +5
(file census 14->19); "live 26,519" -> 26,518. Lead-brief's "36T/189A" combined is
36T/294A (38T/306A with the filter's 2 extra matches) — brief prose, not the lane's,
left as history. Blob-verify: committed src carries the three '--' arms, committed
test carries the refusal pin + structural legs (git show HEAD:<path> greps 3 / 4).
Nothing new under suite-figure.json / durations.tsv / dist — untouched. No seam
refused.
