# candy-top campaign — supervisor handoff state

Authoritative plan: `plan_top.md` (✅ + commit hash marks = reviewed, fixed, committed). This file is the
supervisor's working state for resuming after a context reset. Update it whenever the in-flight set changes.

_Last updated: 2026-10-08 (campaign complete; #1873 container box `82a422cf1`)._

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
`503ee9bb7` · Wave U config keys `96dcdc43a` · P-H sibling-lib docs `776bd06b5` · Wave U1b VM awareness `b071eb8b7` · candy-core resize repaint `5442e8b1f` · P-A app shell `b1bd8fc12` · P-C net panel `7ac64e5f3` (+ sugar-dash NetAutoScale::rescaleNow) · P-B cpu+mem `8e4559946` · P-E proc `52f994d15` · P-D disks+battery `a856be8bd` · P-G mellow theme `632e08d49` · P-F1 overlays/menus `083176989` · P-G VHS tape `3469999a7` · U5 FreeBSD collectors `18fd19467` (not wired yet) · candy-core hangup-safe restore `fe9cdecdb` · P-F2 options/presets/persistence `16b9dcdaa`. · census candy-top share `274ab740b` · U5 FreeBSD wiring `70c09bec9` · child-lifetime pin 28 `39235a259` · P-H candy-top docs `4956d682f` · P-G TTY polish `500696d7e` (P-G complete) · U4b #1791c tree-state `41b18d59f` · U4a GPU/NPU collectors `a8f442188` · U4 GPU boxes + #1552 proc GPU columns `7a6a33579` · U4 #1873 container box `82a422cf1`. **All v1 phases P-A…P-H are ✅.**
btop-derived byte-exact oracles: `prompt_kit/tools/btop-{graph,netscale,theme}-oracle.cpp`.
References: `prompt_kit/findings/{btop-upstream-prs,nvidia-smi-skynet2,kvm-reference,freebsd-reference}.md`.
skynet2 (`ssh root@skynet2`, read-only nvidia-smi queries OK) = 4× RTX PRO 6000 GPU host.

## In flight (post-plan "better than btop" round, user-requested 2026-10-08)
Committed: ctr libvirt VMs + engine detection `865af4587` · pastel default theme + border flows `da83521d1` ·
shared non-blocking GPU feed `2b58f32f5`.
**PAUSED by user 2026-10-08** — both implementers stopped mid-work; their edits stay UNCOMMITTED in the tree
(nothing reverted). Safety snapshot of the whole tree: local ref `refs/wip/candy-top-vm-ipmi` (4ebb882be; restore a
lost file with `git checkout refs/wip/candy-top-vm-ipmi -- <path>`). Resume each by SendMessage to its agent id
(transcripts kept), telling it it was paused and to re-check `git status` before continuing:
- VM dashboard — agent `a650c21a631c26f28` (stopped while writing the panel behaviour + paint test). Files: Collect/Vm{Domain,
  Fleet,FleetSnapshot,Guest}.php, Panel/VmsPanel.php, Panel/Vms/**, Source/Fake/FakeVms.php, View/VmsMode.php, tests
  AppVmsTest, Collect/Vm*Test, Panel/Vms/**, Source/FakeVmsTest, fixtures/panels/vms/; separate hunks in App.php, Layout.php,
  FrameBuilder.php, Config.php, Schema.php, KeyTable.php, OptionsCatalog.php, HostInfo.php, lang/en.php (block "vm dashboard"),
  help/options-edit overlay goldens, GpuPanelsTest/SchemaTest/KeyTableTest, prompt_kit/tools/candy-top-theme-preview.php.
- IPMI box PHASE 1 — agent `a1f34f621b8f9d55e` (stopped while writing the IPMI value types). Files: Collect/Ipmi/**,
  Collect/Process/** (generalised async runner), Collect/Gpu/SmiProcess.php (now delegating), tools/check-child-lifetimes.php
  row, tests/fixtures/ipmi/, lang/en.php block "ipmi box". Its live scratch copy is skynet2:/tmp/ipmi-live(.tgz) — remove
  when done. Phase 2 wiring only after the VM dashboard commits.
Shared-file staging helper: `python3 $JOB_TMP/hunks.py list|stage FILE idx,…` (applies selected -U0 hunks to the
HEAD blob by old line numbers; recreate from this description if the job tmp is gone).
Each: implementer → reviewer → fix → commit → note here.

## Remaining (blocked / optional)
- Blocked: FreeBSD live ps/iostat/ifconfig/netstat -W captures (tech.trouble-free.net ssh down).
- Live test hosts: skynet2 (4× NVIDIA, docker) and kvm521 (`ssh -i ~/.ssh/id_ed25519_new root@kvm521`, libvirt,
  cgroup v2); both keep a ~/sugarcraft clone (`git pull --all && cd candy-top && COMPOSER_ALLOW_SUPERUSER=1
  composer update -o -W --no-dev`). Smoke runs always use `--config <tmp>`.
- Perf note: real ProcList 40-47 ms @1160 pids, 56-72 ms with io (kernel floor); sampling is on the loop thread.
- Deviations/deferrals are recorded per phase in `candy-top/CALIBER_LEARNINGS.md`.

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
