# P11 batch-1 §5 re-check — five docs files (HOOKS · SKILLS · MEMORY · SETTINGS · COMMANDS)

- Date: 2026-09-09
- Base: master @ `322a970fa` ("prompt-resume: pair (24)+(29) closed - next is Phase 11 staffing") — matches expected tip.
- Scope: Phase 11 batch-1 declared files `candy-code/docs/HOOKS.md`, `SKILLS.md`, `MEMORY.md`, `SETTINGS.md`, `COMMANDS.md`.
- Batch 2 (NOT in this re-check; future): `docs/ARCHITECTURE.md`, `docs/PROMPT_ENGINEERING.md` (new), `src/Context/MemoryBlock.php`, `tests/Integration/PromptEndToEndTest.php` (new), `tests/Integration/SystemPromptWiringTest.php`.

## Method

Per file: `git -C /home/sites/sugarcraft log --oneline -3 -- <path>`. Classification rule: last-touch is
**prompt-lane** when the subject carries a `P<digit>` token; where the subject is ambiguous (no P-token),
topology decides via `git merge-base --is-ancestor <sha> 447541d7e` (subject is advisory, topology decides —
prior lesson). A last-touch that is NOT an ancestor of `447541d7e` would be a foreign post-Phase-9 touch → HALT.

## Verbatim last-3 logs

```
=== HOOKS.md ===
8d913b2a8 candy-code: HOOKS.md's AuditHook row cites the accessor instead of a path E328 moved
ff54c377f candy-code: the ASK clip marker the modal "shows" is never on screen, and a docblock's figures came from a neighbouring experiment
05c5e6eb1 candy-code: the guard snippet HOOKS.md called "correct at every size" failed open
=== SKILLS.md ===
d421f7bd0 candy-code: my own new paragraph got the single-* clause backwards
6a8d7a9d4 candy-code: E90 — document the paths: glob semantics E85 changed
2a616e406 Merge round-40 lane sglang: route thirteen more launch warnings onto the transcript seam
=== MEMORY.md ===
da178befb P7.S6 fix-cycle-2: importer docblocks told the truth post-wiring; ARCHITECTURE/MEMORY/Bootstrap prose; nit batch
f043e2d29 P7.S6 fix-forward: drop the false prompt-cap clamp, imports live in agent scope by design Post-gate review measured the claim against the source: MemoryBlock::capture folds ONLY the project scope, and the doc-block of that class states that user and agent entries never reach the prompt. The importer writes MemoryScope::Local, which the store normalizes to agent. So the headroom clamp inherited from the plan premise bounded agent entries against a project-scope render cap they never touch, and the refusal text asserted a falsehood. Option A per the ruling: keep the agent-scope importer, remove the cap mechanism, restore honesty everywhere it leaked.
45d8d1572 P7.S6: /memory import claude|opencode wired with cap headroom + sentinel
=== SETTINGS.md ===
e8ca33436 candy-code: P7.S3 cycle-1 review fixes - dedupe enabledSkills, fail loud on its shape, name the disable
74f1c179a candy-code: P7.S3 skills-into-the-prompt — withSkills gains its first production consumer at the composition root
96e6577a3 candy-code: close the P6.S4 cycle-2 prose overclaims and test-direction gaps
=== COMMANDS.md ===
754d03e23 P6.S3 fix1 (MINOR-3): correct the built-in command row count in COMMANDS.md
8de875d38 candy-code: the bound that was "removed and replaced" had been removed
8d15443c3 candy-code docs: ten new pages, and the five claims in them that were false
```

## Verdict table

| file | last-touch | classification | basis |
|---|---|---|---|
| candy-code/docs/HOOKS.md | `8d913b2a8` — "HOOKS.md's AuditHook row cites the accessor instead of a path E328 moved" | prompt-lane | subject has no P-token (E328 is an audit-round token, advisory) → topology: ancestor of `447541d7e` = TRUE |
| candy-code/docs/SKILLS.md | `d421f7bd0` — "my own new paragraph got the single-* clause backwards" | prompt-lane | no P-token → topology: ancestor of `447541d7e` = TRUE |
| candy-code/docs/MEMORY.md | `da178befb` — "P7.S6 fix-cycle-2: …" | prompt-lane | subject carries P7 token |
| candy-code/docs/SETTINGS.md | `e8ca33436` — "P7.S3 cycle-1 review fixes - …" | prompt-lane | subject carries P7 token |
| candy-code/docs/COMMANDS.md | `754d03e23` — "P6.S3 fix1 (MINOR-3): …" | prompt-lane | subject carries P6 token |

Topology corroboration: all 15 shas surfaced above (all `-3` rows for all five files) were checked;
the eight distinct tips/parents sampled as evidence — `8d913b2a8 ff54c377f 05c5e6eb1 d421f7bd0 6a8d7a9d4 da178befb e8ca33436 754d03e23` —
all return ANCESTOR-of-`447541d7e`. Zero non-ancestors → zero foreign post-Phase-9 touches.

## Post-P24 batch check

```
$ git log --oneline --full-history 08f6cbb22..master -- candy-code/
(empty — 0 lines)
```

`08f6cbb22` is "Merge branch 'prompt/P24' — prompt-fence prior-summary widening (pair 24 + 29)".
Since that merge, master has added only markdown plan/resume material outside `candy-code/` — as expected.

## Crush-lane quiescence (quoted, lanes not read)

From `docs/plans/crush_code_RESUME.md:24-27`:

> **The decision on record is: run `prompt_plan.md` to completion FIRST, then come back for round 61.**
> Round 60 cleared the two crush_code items that blocked it (§2.6 of `prompt_plan.md`, rows 1 and 5).
> While that plan runs, **launch no crush_code round** — rows 2 and 7 of its interference table are
> free only because no round is in flight, and they re-block the instant one is.

Consistent with observation: `git worktree list` shows only `/home/sites/sugarcraft 322a970fa [master]` —
no crush lane is registered/in flight; `/home/sites/crush-lane-*` was not touched by this re-check.

## VERDICT: **CLEAR**

All five batch-1 files' last touches resolve to prompt-lane history; no foreign post-Phase-9 touch on any of
them; `candy-code/` is untouched since `08f6cbb22`; crush lanes quiescent. Proceed to batch-1 briefing.
