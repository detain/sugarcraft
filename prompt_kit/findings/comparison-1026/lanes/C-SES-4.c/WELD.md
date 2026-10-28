# C-SES-4.c — WELD (2026-10-28)

**Pick chain (real hashes):** lane tip `ebe47fb27` (base `cf0cfd0be`) → raw cherry-pick `04fcdb2d33853e026b0489e91853e511014b1eaa` (CLEAN onto `52da3dce3`, zero conflicts, 4 files +329/-18) → **final `f5bd88ca37bed4ab43136d0a49b3f15681dc3475`** (one-line MINOR-1 cure folded via `git commit --amend`, +330/-18). §4 mark uses the final hash.

**Patch-identity proof.** `git patch-id --stable` on `ebe47fb27` and the raw pick `04fcdb2d3`: `f48567c7c6bb7195f3551e5412016d86ce7127a9` IDENTICAL on both. Post-amend the sole content delta vs the lane commit is the cure: `git diff ebe47fb27 HEAD -- candy-code` = exactly 1 file, +4/-3 (the NonInteractive.php observer comment). Hence `git cherry master cc/C-SES-4.c` shows `+` BY DESIGN (amended cure), absorption proven by the patch-id pair + this diff bound. Sibling `cc/C-SES-4.b` branch already pruned at its own weld (a3bf74a61 on master) — its Observability edits are ancestors of this pick, no shared-line collision.

**Author law.** Final commit `%ae`=detain@interserver.net (grep 1), `%ce` grep 1 (separate greps), zero Co-Authored-By trailers. Split-var MAIL + GIT_AUTHOR_*/GIT_COMMITTER_* env.

**MINOR-1 CURED in-weld (r1-sanctioned).** `candy-code/src/Cli/NonInteractive.php` observer comment said "Chat's two doors hand it"; `ObservabilityWiringTest::testChatFoldsAtExactlyTheTwoSettlingDoorsThreeConsumesInTotal` documents THREE consume points. Flipped to "Chat's three consume points (settle queue, pump head, coalesced run) hand it" (5-line comment block, `php -l` clean). No guard pins the old phrase (grep "two doors" hits elsewhere are unrelated contexts).

**MINOR-2 → C-SES-4.d carry (recorded, not fixed).** The fold-before-`$emitter?->onEvent` arm's "no event path can bypass it" phrasing deserves its serve-side twin when C-SES-4.d lands — already that step's duty per r1.

**durations.tsv** (root `scripts/parallel-tests-durations.tsv`, welder-only file; task brief's "candy-code/tools/..." path is stale — the file lives at the root scripts/ dir):
- NEW row `candy-code/tests/Cli/NonInteractiveOtelExportTest.php 0.339 2` (junit per-file sum `/tmp/opencode/cc-cmp-junit-ses4c.xml`; desc-invariant insert between 0.340/0.335 neighbors, line 481).
- MODIFIED-file correction `candy-code/tests/Observability/ObservabilityWiringTest.php 0.074 5 → 0.021 6` (standalone junit `…-ses4c-ow.xml`; re-inserted at its 0.021 tie band, line 958).
- tsv 1540 → 1541 rows; ascending-order violations 4 == 4 (pre-existing baseline held).

**Gates (targeted, REPRODUCED on merged tree, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS):**
- `--filter NonInteractiveOtelExportTest`: `Tests: 2, Assertions: 9` OK — EXACT.
- `--filter 'ObservabilityWiringTest|NonInteractiveOtelExportTest|OtelConfigTest|BatchEventSinkTest|OtelSinkTest|EnvRosterDriftTest'`: `OK — Tests: 72, Assertions: 38885` — EXACT vs REVIEW-1.md.
- guards `NonInteractiveStopTrailersTest|NonInteractiveRefusalDocumentTest|DuplicatedTestHelperDriftTest|GlobDialectDifferentialTest|TreeWideGuardRosterTest|SymbolCitationDriftTest`: `OK — Tests: 76, Assertions: 21480` — EXACT vs REVIEW-1.md.
- `PHPUnit Deprecations: 2` on every run = suite-wide config baseline, re-proven at this weld (untouched `NonInteractiveStopTrailersTest` alone also reports 2).

**Regen:** none — non-protocol lane, zero `src/Protocol/**` and zero `candy-code-web` paths in the pick.

**§7 closeout carries:**
- suite-figure re-pin: +3T total (new file 2T + the +1 structural arm in ObservabilityWiringTest, 5→6) rides the accumulated staleness pair; welder never touched `candy-code/tests/Config/Support/suite-figure.json`.
- **Non-memoised sink seam** flagged for the C-SES-4.d review (lane REPORT): each lit run builds a fresh BatchEventSink + registers its own shutdown flush; many would accumulate in a long (serve) process. Inert-once-settled per the OtelSink contract; harmless for one-shot `-p`.
- `Bootstrap.php:1250-1256` ("the ONLY sink wiring") comment stays stale by plan — C-SES-4.d (`chat(comment only)`) flips it.

**Cleanup:** `cc/C-SES-4.c` branch + worktree `/home/sites/cc-cmp-C-SES-4.c` NOT removed by the welder (lead prunes after confirming) — WELD-ELIGIBLE for cleanup per the patch-id + cure-bound proof above.
