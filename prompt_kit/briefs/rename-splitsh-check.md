# rename step 2.1 — splitsh-lite multi-prefix experiment (verdict)

**Result: NOT CONTINUOUS → step 2.2 MUST use the fallback** (plan_rename.md step 2.1 #6, second
bullet: push the old split master to `legacy/<old-slug>` and tag `<old-slug>-final` in the split
repo before the first force-push after a dir rename). Full command-by-command evidence:
`prompt_kit/briefs/rename-2.1/REPORT.md`.

## Why (one paragraph)

splitsh-lite v1.0.1 accepts a repeated `--prefix` flag, but two **plain** prefixes are rejected at
argument-parse time with
`invalid value "…" for flag -prefix: Cannot have two prefix splits under the same directory: honey-flap ->  vs candy-flappy -> `
— every plain prefix targets the split repo's root and root collisions are forbidden (the check is
the same even for two never-related dirs). So the plan's step-4 command
`splitsh-lite --prefix=honey-flap --prefix=candy-flappy` never runs, and the step-2.2
`prefixes_for()` multi-prefix scheme cannot work as written. After a `git mv honey-flap
candy-flappy`, the single-prefix split `--prefix=candy-flappy` emits a **1-commit unrelated root**
(`021beded9`, "1 commits created") — `git merge-base --is-ancestor $OLD $NEW` fails (rc=1).
splitsh-lite does not follow renames across prefixes.

## Verdict facts step 2.2 needs

- `git version 2.43.0` (dev box); `git subtree` (single `--prefix` only) ships with it;
  `git-filter-repo` is NOT part of git (conda copy here only — not a CI-pinned option).
- Pinned binary reproduced from the workflow: splitsh-lite v1.0.1, sha256
  `ec46c5a4…dc4a9d99` verified OK. `--help` grammar: `-prefix value — The directory(ies) to split`
  (repeatable flag; forms `--prefix=from` and `--prefix=from:to`, where `to` = target path inside
  the split repo).
- Determinism confirmed: pre-rename `--prefix=honey-flap` split = `29667bf6d556bbdae85103f4c4f9ed135fbcd1ca`
  — **identical to live `sugarcraft/honey-flap` master** (`git ls-remote`, fetched 2026-10-15).
- **Working invocation for 2.2:** keep the SINGLE-prefix form `sha=$(/tmp/splitsh-lite --prefix="$lib")`
  with `$lib` = the *new* dir name, and add before the force-push:
  `old=$(git ls-remote <split-url> refs/heads/master | cut -f1)`; if `$old` is non-empty, fetch it
  (`git fetch <split-url> $old`) and `git merge-base --is-ancestor FETCH_HEAD $sha` fails, first
  `git push <split-url> $old:refs/heads/legacy/<old-slug>` and
  `git push <split-url> $old:refs/tags/<old-slug>-final` (guard wording per plan 2.2 #2: "the
  remote master is not an ancestor of $sha"). Do NOT add `prefixes_for()` multi-prefix args.
- Alternative found but rejected for 2.2: `--prefix=honey-flap:candy-flappy --prefix=candy-flappy`
  *parses* and preserves all history (84 split commits, flat tip tree), but replays every historical
  commit with files nested under `candy-flappy/` → every split SHA rewrites and OLD is still not an
  ancestor → not a fast-forward; worse than the legacy-branch fallback. Documented in the REPORT §6.
