# rename step 2.1 — splitsh-lite multi-prefix experiment (full evidence)

Date: 2026-10-15. Repo under test: `/home/sites/sugarcraft` master @ `6e8887928` (clean).
Everything ran LOCALLY in a scratch clone under `/tmp/opencode/rename-2.1/` — no remote was ever
added that can push, nothing was pushed, the scratch dirs are disposable. Verdict summary lives in
`prompt_kit/briefs/rename-splitsh-check.md`; this file is the command-by-command record with
outputs. Raw logs: `/tmp/opencode/rename-2.1/logs/` (same content quoted here).

## 1. Pinned binary install (same pin as `.github/workflows/sync-sugarcraft.yml`)

```sh
D=/tmp/opencode/rename-2.1 && cd $D && curl -fsSL \
  https://github.com/splitsh/lite/releases/download/v1.0.1/lite_linux_amd64.tar.gz -o s.tgz \
  && tar -xzf s.tgz ./splitsh-lite \
  && echo "ec46c5a409422bf55b26f7de1faab67c2320362934947f984872b3aedc4a9d99  splitsh-lite" | sha256sum -c -
```

```
splitsh-lite: OK
splitsh-lite version v1.0.1
```

`./splitsh-lite --help` (full output in logs/01-help.txt) — relevant lines:

```
  -prefix value
        The directory(ies) to split
  -commit string
        The commit at which to start the split (optional)
  -origin string
        The branch to split (optional, defaults to the current one) (default "HEAD")
  -legacy
        [DEPRECATED] Enable the legacy mode for projects migrating from an old version of git subtree split (optional)
```

`-prefix value` is a Go repeatable flag ("directory(ies)") — repeatable **yes**, but see §4: the
semantics are multi-directory-into-one-repo with per-prefix target paths (`--prefix=from[:to]`),
NOT a union-at-root of rename-chain names.

## 2. Throwaway clone

```sh
git clone /home/sites/sugarcraft $D/mono    # tip = 6e888792848199fae89be21e8f65c146ce487583 = master
```

## 3. Pre-rename split SHA (plan step 3)

```sh
cd $D/mono && $D/splitsh-lite --prefix=honey-flap <!-- @rename-keep -->
# → 29667bf6d556bbdae85103f4c4f9ed135fbcd1ca   ("83 commits created, 9039 commits traversed, in 1m34.303s")
OLD=29667bf6d556bbdae85103f4c4f9ed135fbcd1ca
```

Determinism cross-check (plan step 5, live repo):

```sh
git ls-remote https://github.com/sugarcraft/honey-flap refs/heads/master <!-- @rename-keep -->
# → 29667bf6d556bbdae85103f4c4f9ed135fbcd1ca	refs/heads/master
```

**Identical.** The local split reproduces the live split byte-for-byte → splitsh-lite v1.0.1 output
is deterministic for a given prefix, and sugarcraft/honey-flap was in sync with monorepo master at <!-- @rename-keep -->
6e8887928 (logs/03, logs/04).

## 4. The prescribed multi-prefix command (plan step 4) — FAILS AT PARSE

```sh
cd $D/mono
git mv honey-flap candy-flappy <!-- @rename-keep -->
git -c user.name='Joe Huss' -c user.email=detain@interserver.net commit -qm test   # 44807677e
$D/splitsh-lite --prefix=honey-flap --prefix=candy-flappy <!-- @rename-keep -->
```

```
invalid value "candy-flappy" for flag -prefix: Cannot have two prefix splits under the same
directory: honey-flap ->  vs candy-flappy -> <!-- @rename-keep -->
```

rc=2, nothing runs. The same error appears for two **never-related** dirs
(`--prefix=candy-async --prefix=candy-core` → `… candy-async ->  vs candy-core -> `), proving the
guard is "two prefixes with the same target path (empty = split-repo root)", not rename detection:
**plain `--prefix` can only be used ONCE per invocation in splitsh-lite v1.0.1.** (logs/05, logs/08)

The error text `from -> to` also reveals the flag grammar: `--prefix=from[:to]` where `to` is the
*target path inside the split repo*.

## 5. Continuity answer for 2.2 — single-prefix after rename (the only runnable form)

```sh
$D/splitsh-lite --prefix=candy-flappy          # on the renamed tree
# → 021beded9f652c9d565b18f66a7cf83d9beda58e   ("1 commits created, 9040 commits traversed")
git merge-base --is-ancestor $OLD 021beded9…   # rc=1
```

**NOT-CONTINUOUS.** splitsh-lite does not follow renames across prefixes: the post-rename split is
a fresh unrelated root containing only the `test` commit. Therefore after each future `git mv`
step-2.2 must either (a) run the SINGLE-prefix split with the NEW dir name and accept the new root
(the OLD history vanishes from `master`), or (b) keep `--prefix=<old>` until no further old-path
commits occur — the plan's prescribed consequence applies: **(a) + push the pre-rename split master
to `legacy/<old-slug>` and tag `<old-slug>-final` in the split repo before the first force-push**
(plan 2.1 #6 fallback, guard "remote master is not an ancestor of $sha" per 2.2 #2). The
`prefixes_for()` helper from 2.2 #1 must NOT be wired into the splitsh-lite call. (logs/06, logs/07)

## 6. `from:to` variants (measured, both rejected for 2.2)

- Grammar check: `--prefix=candy-async:candy-async --prefix=candy-core:candy-core` parses and runs
  (464 split commits, `e27e0412a26ddf713eae7cf67c3b8dfb8678f9f6`) — each source lands under its own
  subdirectory in the split repo. Wrong layout for our per-lib repos (they are root-flattened).
- Creative continuity attempt: `--prefix=honey-flap:candy-flappy --prefix=candy-flappy` (old dir <!-- @rename-keep -->
  mapped INTO the new dir's root position). Parses; "84 commits created" →
  `0524cdfa126a3de77c11fb894757db1d0740fb4e`; tip tree is flat (`composer.json`, `src`, …) but every
  pre-rename split commit carries the files nested under `candy-flappy/`
  (`git ls-tree --name-only $VC^` → single entry `candy-flappy`), so ALL historical split SHAs
  rewrite: `git merge-base --is-ancestor $OLD $VC` → **rc=1, NOT-CONTINUOUS**. It preserves the
  change history inside one branch (better than nothing if the legacy-tag fallback were ever
  refused), but it is not a fast-forward and re-hashes the whole split — strictly worse than the
  §5 fallback. (logs/09, logs/11, logs/12)

## 7. Alternatives shipping with git (plan/brief question)

- `git --version` → **git version 2.43.0** (dev box; workflow runners are ubuntu-latest, same
  family). `git subtree` IS available with it, but `git subtree split` takes exactly ONE
  `--prefix` — it cannot express the multi-prefix idea either, and its output DIVERGES from
  splitsh-lite v1.0.1:

  ```sh
  # fresh clone of pre-rename master (mono2)
  git subtree split --prefix=honey-flap <!-- @rename-keep -->
  # → 3506e91bdbb79114f94294b699c307b6c4fe0bf9   (84 commits)
  ```

  vs splitsh-lite's `29667bf6d…` on the SAME input. Fetched both chains into one object store:
  **tip trees byte-identical** (`1e1ec347256b2d523c4abecdbe66a2781087f411` on both sides), same
  tip subject/author/committer/timestamps, chain lengths 84 (subtree) vs 82 (splitsh,
  `rev-list --count`; its progress bar printed "83 commits created"), and the two chains share a
  merge-base (`c21feeaf0…`) but DIVERGE mid-history — splitsh-lite handles merge commits
  differently than `git subtree split`. **This empirically confirms plan 2.2 #4:** the workflow
  comment claiming splitsh-lite "emits the IDENTICAL split SHA as `git subtree split`" is FALSE —
  must be rewritten (and nobody should "switch to subtree" as a rename workaround).
- `git-filter-repo` exists on this box (conda copy, `a40bce548d2c`) but is NOT shipped with git
  and NOT in the pinned CI image workflow — not a drop-in for 2.2.
- splitsh-lite `-legacy` flag help-text ("migrating from an old version of git subtree split")
  corroborates that the two tools' histories are separate lineages by design.

## 8. Verdict (what step 2.2 must use)

**NOT CONTINUOUS.** The plan's multi-prefix experiment cannot even execute on the pinned binary
(§4), and the single-prefix reality after a rename produces an unrelated root (§5). Step 2.2 must
therefore implement the plan's **fallback** (2.1 #6 second bullet):

1. Keep the exact current single-prefix invocation:
   `sha=$(/tmp/splitsh-lite --prefix="$lib")` — `$lib` = the dir's CURRENT (post-rename) name.
   Do not add `prefixes_for()`/`--prefix` repetition — every two-plain-prefix call is a hard parse
   error (`rc=2`).
2. Before the first force-push to a renamed split repo, guard on
   `old=$(git ls-remote <split-url> refs/heads/master | cut -f1)`; if `$old` non-empty and
   (after `git fetch <split-url> $old`) `git merge-base --is-ancestor FETCH_HEAD $sha` fails →
   `git push <split-url> $old:refs/heads/legacy/<old-slug>` and
   `git push <split-url> $old:refs/tags/<old-slug>-final`, then force-push `$sha` to master.
   The `from:to` rewrite trick (§6) is available as a last resort but rewrites every historical
   split SHA — explicitly rejected.
3. For chained renames the same rule applies per hop: each force-push after a dir move strands
   the previous chain under `legacy/<previous-slug>`; `tools/renames.json` gives 2.2 the old-name
   list for naming those branches/tags.

Determinism note for CI: a cold single-prefix split of a lib with 9k-commit traverse takes ~95 s
here (honey-flap, 83 split commits); repeat runs with the same prefix set reproduce SHAs <!-- @rename-keep -->
byte-exactly (§3 live-repo match). The `.git/splitsh.db` cache is shared across invocations in one
run — with the fallback, 2.2's per-lib loop needs no cache changes.

## 9. Cleanup

`$D/mono`, `$D/mono2` and their 1.4 GB deleted after evidence capture; raw logs kept at
`/tmp/opencode/rename-2.1/logs/` (volatile). Durable record = this file +
`../rename-splitsh-check.md`. Nothing was ever pushed; scratch clones had no push remotes.

