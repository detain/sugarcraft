# P0-A3: `AgentWorkerPool::pumpProgress` stat first, then offset reads

Plan record for work package P0-A3 (candy-code). The working plan is
`reviews/UPSTREAM-PLAN.md` (Group A, line 261; behaviour-change paragraph at
line 273). That file is untracked in the shared checkout, so the declarations
it needs are recorded here, on the branch, and travel with the PR.

## Plan acceptance and the declared departure from it

Plan acceptance (line 261): "tail reads only new bytes".

**Declared departure (owner-accepted trade-off, 2026-11-16).** The acceptance
is not met as written. On any change in the progress file's length,
`pumpProgress()` reads the file from byte 0 (`readProgressRange($path, 0, $size)`)
and compares its prefix with the mirror before it appends. Only the
bytes past the mirrored prefix are appended, but the read itself covers the
whole file. The cost is therefore O(output) per poll on a growth poll. A poll
with no growth costs one `stat` and no read.

The reason is that a tail read cannot see a rewrite which grows the file: the
bytes before the offset may have changed, and detecting that requires reading
them. The owner accepted the whole-file read on growth in exchange for
mirroring growing in-place rewrites.

## Behaviour change (line 273)

Declared behaviour change (2026-11-16), as recorded at line 273 and in the
`pumpProgress()` docblock:

1. A same-size in-place rewrite of bytes that were already mirrored is not
   mirrored. The length is unchanged, so the stat sees nothing to verify.
2. A shrink to zero resets the mirror and the offset at once. On master an
   empty file was a no-op, so the stale mirror was kept.
3. **DC-3.** A file that is shrunk to zero and regrown to exactly the mirrored
   length between two polls keeps the stale mirror. No poll observes size 0,
   so the zero-size reset never runs, and the stat matches the stored offset.
   A stat cannot tell this from case 1. The next poll that sees a different
   length rebuilds the mirror from the file. Pinned by
   `WorkflowLivePaneTest::testASameLengthRegrowAfterAShrinkToZeroBetweenPollsIsNotMirrored`.
   A regrow to a different length between polls is rebuilt by the prefix
   comparison. Pinned by `testARegrownFileIsNotSplicedOntoItsStaleMirror` and
   `testAProgressFileShrunkToZeroIsNotSplicedOntoItsStaleMirror`.

## Test-count conservation (declared, not refreshed)

The branch adds 10 test methods to `WorkflowLivePaneTest` (11 on master, 21
on the branch). `candy-code/tests/Config/Support/suite-figure.json` still
records 33106 (master). The measured run gives 33116. The suite figure is
left red on purpose. The owner decided on 2026-11-16 not to refresh it now.
The refresh is a separate step, to be done after this branch lands.

## Plan sync

The gate step applies this record to `reviews/UPSTREAM-PLAN.md`: line 261 gets
a departure note pointing here, and line 273 gets DC-3 and the whole-file-read
trade-off. This branch does not edit the shared checkout.
