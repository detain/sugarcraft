# P12 closeout — SugarCraft rename campaign COMPLETE (2026-10-17)

Verdict: campaign done. Master pushed at `d8845b67a` (`rename: complete`), origin in-sync 0/0, all guards rc0 at the tip, tools/tests at floor 435T/2147A. Remaining work is human-side only (list below).

## Batches (all landed, welded, R8/R9 fired)

- **A (3 renames)** — pilot batch; pre-F chain, see git log.
- **B (12 renames)** — apps; pre-F chain.
- **C (13 renames)** — components; pre-F chain.
- **D (7 renames)** — media/rendering; pre-F chain.
- **E (13 renames)** — foundation; pre-F chain.
- **F (2 renames)** — sugar-crush → candy-code (+ web); weld `aab9eacc9`, R8/R9-F `156688b84`, review-fixes `9d0fd549b`. <!-- @rename-keep historical provenance -->
- **9 docs/website/rulebook/agent-configs** — `a54e105b4` · `f82d19557` · `15bd64e2e` · `51f4249cf`.
- **10 finalization** — automatable CLOSED `67564524c` (archives, metadata, checklist, Packagist probe loop; disposition in plan_rename.md).
- **11 externals** — webhooks `5f1eb46` · php-dup-finder `24841ff` (stayed VCS per owner gate) · phlix `f2f85e0` + `5ba0c6b10` (lock regen + phar rebuild, pushed) · provirted OWNER-GATED.

## Floors & figures

| Surface | Figure |
|---|---|
| candy-code K=8 shard gate | 22,571T / 424,942A (+16 assertion wobble by design) |
| tools/tests suite | 435T / 2147A (incl. PrefixPolicyTest `8d13ebc49`) |
| check-rename-residue --all-done | 52 entries / 0 findings |
| gen-docs --check / gen-ansi-readme --check / check-path-repos / check-child-lifetimes | all rc0 |
| battery | 60 libs clean vs baseline (minesweeper 1F+4W + dashboard 2F standing pre-existing; candy-vcr 4,213A = +4A tracked; candy-top 2,108T = base 2,107 + 1 static, no regression — `501384aab` corrected the phantom 2,110) |
| Redirects | verified local + live (docs stubs + GitHub renames) |

## CI

All 3 workflows (ci, sync, vhs) green at `8d13ebc49`; root-closure self-healed post-index. Split repos carry no workflows (structural — monorepo CI only).

## Packagist

51/51 new packages registered + indexed (visit-3 sweep: 16/16 landed, zero re-fires; deferred-406 CLOSED). Old packages ride the human abandon checklist (next item).

## OWNER-PENDING (explicitly unchecked)

- [ ] 52 Packagist web-UI abandons — `prompt_kit/briefs/rename-packagist-abandon-checklist.md` (61-line file, 52 abandon rows incl. the 2 absorbed repos).
- [ ] provirted — 4 decisions: (a) phar-collision clear; (b) flip-mode VCS/Packagist-after-verify/no-flip — now viable since 51/51 indexed, patch prepped 2 files/6 lines at `/tmp/opencode/rename-p11/provirted/REPORT.md`; (c) PHP 8.2→8.3 TUI bump; (d) description drift.
- [ ] teams-chat-bot — deployed `.env` explicit-slug-list check against renamed packages.
- [ ] oc.7z — re-supply owed; never stage.

## Harness lessons (this campaign, for future rounds)

1. `phpunit --list-tests | wc -l` over-counts when test names line-wrap — baselines must count test lines, not physical lines (candy-top 2,110 phantom).
2. `refresh-deps.php --status` cannot detect a stale autoload map after a vendor prune — full `--mode=linked` refresh is the cure; re-measure, don't trust status.
3. `scripts/parallel-tests.sh` default `--out /tmp/parallel-tests` carries `done-*` resume markers across runs — always pass a unique `--out`.
4. After any lib dir move, re-run `refresh-deps` before the first suite (pruned symlinks produce mass false reds, pass-1 lesson).
5. Ledger/tracker edits: targeted line edits + `git diff --stat` sanity (the r77 ledger-wipe class of defect); a commit cannot contain its own SHA — stamp rows cite the parent tip.
