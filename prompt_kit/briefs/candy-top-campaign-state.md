# candy-top campaign — supervisor handoff state

Authoritative plan: `plan_top.md` (✅ + commit hash marks = reviewed, fixed, committed). This file is the
supervisor's working state for resuming after a context reset. Update it whenever the in-flight set changes.

_Last updated: 2026-10-08 (third /compact; U4a GPU fix round in flight)._

## Operating rules (user-mandated)
- Commit straight to `master`, author/committer **Joe Huss <detain@interserver.net> only**, **no Co-Authored-By**,
  **no PRs/branches**. Push after each commit; if rejected, `git fetch && git merge --no-edit origin/master`
  (upstream usually only adds regenerated VHS GIFs) then push. Never rebase/stash (agents share the tree).
- Every unit of work: implementer agent → independent reviewer agent → fix round(s) → re-review until APPROVE
  → supervisor commits ONLY that unit's paths → mark `plan_top.md` with ✅ + hash (separate small commit).
- Up to ~5 agents concurrently on disjoint paths. Agents never git stash/checkout/reset/clean/commit; supervisor
  commits. When a shared file (e.g. `candy-top/lang/en.php`) holds two units' edits, stage partially
  (build the blob without the other unit's block, `git hash-object -w` + `git update-index --cacheinfo`).
- Skip Caliber entirely. Don't touch sugar-crush. `unset GITHUB_TOKEN` before any `gh`.
- Docs for every feature (phase P-H). Ask the user only for genuine decisions; otherwise keep going.

## Done (on master)
Lib lanes L1 `0153b65e4` · L2 `073913a44` · L3 `37f5c6744` · L4 `1bbbca37f` · L5 `57cd08b9f` · L6 `0859cfa4f` ·
L7 `07a1e5e6f` · L8 `39c65280c` · integration `03d5ef164` · candy-top Theme `672c64680` · Collect `b2d9207cc` ·
Config+Lang `7c893a54b` · proc_open census fix `ea1949fd2` · Wave U0+U1 collectors `02f178b91` · Wave U2 block2
`503ee9bb7` · Wave U config keys `96dcdc43a` · P-H sibling-lib docs `776bd06b5` · Wave U1b VM awareness `b071eb8b7` · candy-core resize repaint `5442e8b1f` · P-A app shell `b1bd8fc12` · P-C net panel `7ac64e5f3` (+ sugar-dash NetAutoScale::rescaleNow) · P-B cpu+mem `8e4559946` · P-E proc `52f994d15` · P-D disks+battery `a856be8bd` · P-G mellow theme `632e08d49` · P-F1 overlays/menus `083176989` · P-G VHS tape `3469999a7` · U5 FreeBSD collectors `18fd19467` (not wired yet) · candy-core hangup-safe restore `fe9cdecdb` · P-F2 options/presets/persistence `16b9dcdaa`. · census candy-top share `274ab740b` · U5 FreeBSD wiring `70c09bec9` · child-lifetime pin 28 `39235a259` · P-H candy-top docs `4956d682f` · P-G TTY polish `500696d7e` (P-G complete) · U4b #1791c tree-state `41b18d59f` · U4a GPU/NPU collectors `a8f442188`. **All v1 phases P-A…P-H are ✅.**
btop-derived byte-exact oracles: `prompt_kit/tools/btop-{graph,netscale,theme}-oracle.cpp`.
References: `prompt_kit/findings/{btop-upstream-prs,nvidia-smi-skynet2,kvm-reference,freebsd-reference}.md`.
skynet2 (`ssh root@skynet2`, read-only nvidia-smi queries OK) = 4× RTX PRO 6000 GPU host.

## In flight
- (none) — U4a GPU/NPU collectors committed `a8f442188`. Reviewer note for the box-slot unit: bound the #1008
  unmeasured-hold (heldFrom) to N samples so a permanently-gone GPU stand-in doesn't freeze its last values.

## Next
1. **U4 box slots unit** (after U4a commits): wire `$platform->gpu()` in CpuPanel::standard (update PlatformWiringTest
   comment ~line 56), `shown_gpus` → `withVendors(...)`, `withProcesses()` only when proc shows GPU columns; #1730
   any-GPU box slots on the #1881 grid (`gpu_box_columns="Auto"`, `gpuN` any N max 6), NPU display; #1552 proc
   columns/sorts (proposed keys: proc_gpu_only bool false, proc_gpu_graphs bool true, proc_sorting += `gpu`,
   `gpu memory`, optional `npu` word in shown_gpus). New Schema keys/KeyRows → README regen
   (`CANDY_TOP_UPDATE_DOCS=1 vendor/bin/phpunit tests/Docs`) + docs/_data + gen-docs.
2. **#1873 container box** (after box slots — both touch the layout grid).
3. Then the campaign is complete: final state-file/plan tidy, MATCHUPS/README already 🟢.
- Outstanding but blocked: FreeBSD live ps/iostat/ifconfig/netstat -W captures (tech.trouble-free.net ssh down).
- Perf note: real ProcList 40-47 ms @1160 pids, 56-72 ms with io (kernel floor); sampling is on the loop thread.
- Deviations/deferrals are recorded per phase in `candy-top/CALIBER_LEARNINGS.md` (P-A…P-F sections).

## Process notes
- Snapshot-test every commit in isolation: `git checkout-index -a --prefix=$JOB_TMP/idx/` then
  `cp -a candy-top/vendor $JOB_TMP/idx/candy-top/vendor` and run phpunit there (JOB_TMP=/home/my/.claude/jobs/b8d2a5fd/tmp).
- Shared files (lang/en.php, Panels.php, CALIBER_LEARNINGS.md) staged partially via python + `git hash-object -w`
  + `git update-index --cacheinfo` when another unit's edits are present.
- Never run `refresh-deps.php` with an uncommitted composer.json (it `git checkout`s `*/composer.json`).

## Known open issues (not campaign-caused)
- candy-core `DescriptorSinkArgumentCensusTest` has 2 failures from sugar-crush commit `f41b307ae`
  (2026-10-05) — pre-existing red on master; reported to user, not fixed (outside plan) unless asked.
- `terminal_sync=false` ignored (candy-core always wraps DEC 2026); sampling runs on the loop thread (P-E must
  keep ProcList under the R4 50 ms budget).
