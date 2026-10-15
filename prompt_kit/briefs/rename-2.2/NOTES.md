# Step 2.2 — sync-sugarcraft.yml reads tools/renames.json (NOTES / evidence)

Main commit: `.github/workflows/sync-sugarcraft.yml` + `scripts/affected-libs.php` + this file.
Branch master @ 219c4dd01 base; nothing pushed, no remote touched (the plan's remote-mutating
actions — R-series pushes, legacy ref creation on GitHub — are implemented as workflow CODE only
and deliberately not executed).

## What the workflow now does

The per-lib loop still splits with the exact single-prefix invocation
`sha=$(/tmp/splitsh-lite --prefix="$lib")` (unchanged), but before the first force-push it
consults `tools/renames.json` through a new `old_slug_for()` helper: if the lib dir is the `new`
side of a `kind:"rename"` entry (i.e. the dir has been `git mv`'d), it runs
`git ls-remote … refs/heads/master` → `git fetch <url> <tip>` → `git merge-base --is-ancestor <tip> $sha`,
and on divergence preserves the remote chain with
`git push <url> <tip>:refs/heads/legacy/<old>` + `git push <url> <tip>:refs/tags/<old>-final`
(both through `retry_git_push`) before force-pushing master. `scripts/affected-libs.php`
`FORCE_ALL_FILES` gains `'tools/renames.json'` (plan step 2.2 #5) so a map edit re-syncs every lib.

## How old→new split continuity is handled

Per the Step 2.1 verdict (prompt_kit/briefs/rename-2.1/REPORT.md §4–5, §8): multi-prefix splitting
is NOT continuity-preserving in splitsh-lite v1.0.1 (two plain `--prefix` flags are a hard parse
error; a single-prefix split after a rename yields an unrelated fresh root). So continuity is
preserved by REFERENCE, not by commit-graph: the pre-rename split chain stays reachable on the
split repo forever as `legacy/<old-slug>` + tag `<old-slug>-final`, and the new-prefix chain
becomes master. Chained renames: each hop re-derives its immediate predecessor from
renames.json (`old_slug_for` matches `new == $lib`), so a B→C hop names `legacy/B` while
`legacy/A` from the A→B hop remains untouched.

## Semantics implemented (plan-matching)

- Guard fires ONLY for dirs that are the `new` side of a `kind:"rename"` entry → merge/fold
  entries (candy-mouse, candy-forms batch-1) and the create entry (candy-gifview) never fire it.
- `status` is deliberately NOT a filter inside the guard: if the dir name equals a rename entry's
  `new`, the dir has already moved (Procedure R7 moves dir + sets status in the same commit), so
  divergence is real regardless of bookkeeping state. Today every guard-relevant lookup returns
  empty (measured below: "guard fires for zero dirs today") → non-renamed libs execute the same
  split + force-push behavior with zero extra network calls; the one per-run cost they do pay is
  a single local `php -r` lookup via `old_slug_for` (wording qualified per review finding 5 —
  "byte-identical path" overstated it).
- Fail-closed: if the `git fetch` of the remote tip errors, `set -e` aborts the job BEFORE the
  force-push — the workflow never force-pushes over an uninspected remote master. (An empty
  ls-remote answer — brand-new/empty split repo, e.g. candy-gifview's first sync — skips the
  guard, which is correct: nothing to preserve.)

## Plan/reality conflicts encountered (and resolution)

1. Plan step 2.2 title + #1 (`prefixes_for()` feeding multi-prefix splitsh-lite) vs 2.1 verdict:
   the plan's own #2 clause says use multi-prefix ONLY if 2.1 said CONTINUOUS, otherwise keep the
   single prefix + legacy-branch push guarded by ancestor test. 2.1 said NOT-CONTINUOUS, so the
   fallback branch is implemented and `prefixes_for()` is intentionally NOT wired (verdict §8.1).
   renames.json is still the derived old→new source — via `old_slug_for()` for ref naming.
2. Brief wording "derive its lib list … from tools/renames.json instead of a hand-maintained
   list": no hand-maintained lib list exists in this workflow — the list comes from
   `scripts/affected-libs.php` (filesystem scan). Changing the list source to renames.json would
   break syncing of the 9 libs that have no rename entry (ansi, files, metrics…) — violates the
   byte-identical regression bar. Resolution: list derivation unchanged; renames.json drives the
   mapping + (via plan #5) force-all triggers. Recorded here per brief instruction.
3. Plan commit message `sync: split renamed libs with their pre-rename prefixes` describes the
   multi-prefix world 2.1 refuted (would be a false history entry). Used the brief's message
   `rename: sync workflow reads renames.json` instead — plan #2's own "otherwise" branch governs.
4. Plan #4 said the rewritten comment should state the prefix set "includes pre-rename names from
   tools/renames.json" — untrue in the fallback (single current prefix only). Comment states the
   deterministic-for-a-given-prefix-set fact plus the rename discontinuity + preservation scheme,
   all measured. Dropped the stale "all 56 libs split + push cold in ~70s" timing claim from the
   same comment block (contradicted by 2.1's ~95s cold single-lib measurement).
5. `scripts/affected-libs.php` edit: brief says "no tools/*.php" and "nothing else changes" — but
   plan step 2.2 #5 explicitly mandates adding `tools/renames.json` to affected-libs.php's
   force-all array (it is `scripts/`, not `tools/`, and the plan is authoritative where more
   specific). Done, with an in-code WHY comment.
6. Divergence of a NON-renamed lib (should be impossible: same HEAD + same prefix ⇒
   deterministic, fast-forward) gets no preservation path — that case pre-exists this step's
   scope and adding speculative saves there would change non-renamed behavior, violating the bar.

## Verification (all run 2026-10-15, no pushes)

- `python3 -c "import yaml…safe_load(...)"` → YAML OK.
- All 5 embedded `run:` blocks extracted via yaml and `bash -n` → OK (incl. modified step
  "Split + force-push each affected lib").
- Embedded `php -r` snippet extracted → `php -l` clean; `php -l scripts/affected-libs.php` clean.
- Dry logic test (sourced real `old_slug_for()` from the extracted step, cwd=repo root):
  - `candy-flappy→honey-flap`, `sugar-markdown→candy-shine`, `candy-code→sugar-crush`, <!-- @rename-keep -->
    `candy-code-web→sugar-crush-web`, `sugar-core→candy-core` (post-rename names answer old slugs).
  - `candy-mouse`, `candy-forms`, `candy-gifview`, `docs`, and every current pending-old dir
    answer empty.
  - Name-set semantics vs disk: pending entries' `old` all exist as dirs, their `new` none exist;
    batch-1 non-pending entries' `new` (candy-mouse/candy-forms/candy-gifview) exist as dirs →
    "current-name set: all OK" and "guard fires for zero dirs today".
- `php scripts/affected-libs.php --files tools/renames.json` → `force_all: True`.
- `php scripts/affected-libs.php --all | head -c 300` → valid JSON, 61 all_libs.
- End-to-end local simulation of the guard (file:// bare remote, unrelated second root;
  evidence /tmp/opencode/rename-22/sim + transcript): diverging first push creates
  `legacy/candy-old` + tag `candy-old-final` at the pre-rename tip, then master = new root;
  second (fast-forward) sync leaves guard silent and legacy refs untouched → all PASS.

## Deviations

- Commit message per conflict-3 above.
- No remote commands executed (barred by brief); first renamed-lib sync will exercise the new
  path in CI after the corresponding batch's Procedure R push.

## Review round (report: /tmp/opencode/rename-rv-2.2/REVIEW.md — APPROVE-WITH-FIXES)

All five findings applied in the follow-up commit (subject `rename: sync workflow review fixes (2.2 rv)`;
fix sha = the commit containing this line — tracker row 2.2 intentionally still cites the main commit
0b6d99af2). Files touched: workflow + this NOTES.md only.

1. MAJOR ls-remote masking — FIXED: `|| true` deleted from the `remote_tip=$(git ls-remote … | cut -f1)`
   line. Repro with the shipped line extracted verbatim from the YAML: unreachable repo → rc=128,
   script aborts BEFORE any force-push (fatal "does not appear to be a git repository", no post-assign
   echo); genuinely empty-but-existing bare repo → rc=0 + empty tip → guard skipped, force-push path
   proceeds (finding-1 kept path re-proved).
2. MAJOR fail-open `old_slug_for()` — FIXED: helper now `exit(1)` with a STDERR message on (a) missing
   tools/renames.json, (b) unparseable JSON, (c) missing/non-array `entries`. Repro in a fixture cwd:
   all four shapes → rc=1, empty stdout, message `…refusing to sync uninspected`; under the step's
   `set -e` the `prev=$(old_slug_for "$lib")` assignment kills the job before any push.
3. MINOR duplicate `new` claims — FIXED: >1 matching rename entry → exit 1
   (`2 rename entries claim new slug dup (a1, a2) — refusing to guess`, measured in fixture map).
4. MINOR chained-rename mislabel window — documented: guard comment now states A→B→C collapsing names
   the A-era chain `legacy/B` (reference survives; ref name marks the hop, not the era).
5. MINOR NOTES wording — qualified above (one extra local `php -r` per lib per run; push/split
   behavior and zero extra network calls for non-renamed libs; workflow guard comment aligned too).

Re-verification after fixes: `yaml.safe_load` OK; all 5 extracted run blocks `bash -n` OK; `php -l` on
the extracted `old_slug_for` snippet OK; regression re-run against the REAL map: `candy-flappy→honey-flap`, <!-- @rename-keep -->
`sugar-crush→''`, `candy-mouse→''`, and all 61 dirs of `affected-libs --all` still answer empty with
rc=0 ("fires=0, rc!=0 count=0"). Fresh-file `grep` of the shipped blk4 confirms the no-`|| true` line.
