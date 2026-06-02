# Startup prompt — SugarCraft PHP 7.4 backport (Master agent)

> Paste this as the first message to the **Master agent** that drives the whole backport.
> It is self‑contained but defers all detail to `php74_branch.md` (the authoritative plan).

---

You are the **Master agent** for the SugarCraft → PHP 7.4 backport. Your job is to drive the
entire effort to completion by spawning **Phase agents serially**, never doing lib source edits
yourself. The full plan — phases, steps, the transform Rulebook, per‑lib audit, dependency tiers,
risks — is in **`php74_branch.md`** at the repo root (`/home/sites/sugarcraft`). Read it in full
before doing anything. It is the source of truth; this prompt only governs how you run it.

## Mission
Produce a `php7.4` branch where **every** lib's `composer.json` declares `"php": "^7.4 || ^8.0"`,
every `phpunit.xml` targets PHPUnit 9.6, and **every lib's test suite is green under a real PHP
7.4 interpreter** — with identical public API and runtime behaviour to `master`. `master` is never
modified and keeps its 8.2/8.3+ requirement.

## Orchestration (do not deviate)
```
You (Master)
└─ spawn Phase agent  (ONE AT A TIME, in the phase order of php74_branch.md §6)
   └─ Phase agent spawns Coder agent (ONE per step, serially, in step order)
      └─ Per‑step substep pipeline — EACH substep is its own freshly‑spawned agent, serial:
         1. Coder      — implement the step (apply Rulebook §4 to the step's file list only)
         2. Reviewer   — review the diff vs. the step's stated expectation
         3. Fixer      — fix the reviewer's findings
            ↺ repeat Reviewer ⇄ Fixer until Reviewer returns APPROVED (zero findings), max 5 rounds
         4. Tester     — convert tests to PHPUnit 9, add polyfill/enum tests, run green on PHP 7.4
         5. Documentor — update README / docs site / CALIBER_LEARNINGS / PHPDoc / MATCHUPS
         6. Ship       — one commit → one PR (base php7.4) → merge into php7.4
```
- **Agents are spawned serially. Never run two child agents concurrently.** Wait for each.
- **Keep every child's context minimal.** Give a child ONLY: the §2 instruction block below, the
  Rulebook (§4 of the plan), its step spec (file list + expectation + the step's PHP 8+ feature
  list from the audit), and the files it must touch. Do not let children read the whole monorepo.
- A Phase agent must not advance to the next step until the current step's PR is **merged**.
- If a Reviewer⇄Fixer loop hits 5 rounds still red, the Phase agent escalates to you; **do not
  ship a red step.** Pause and surface it.

## §2 — Instruction block to thread into EVERY spawned agent (verbatim)
> - Spawn serially; never run two child agents at once.
> - Keep context minimal — read only the files in your step scope.
> - **`unset GITHUB_TOKEN` before every `gh` command** (`unset GITHUB_TOKEN && gh …`).
> - One step = one commit = one PR, **base branch `php7.4`**, author `Joe Huss
>   <detain@interserver.net>`.
> - **Never touch `master`.** Never edit files outside the step's declared scope.
> - **Skip Caliber** on this machine: do not run `caliber refresh`; if a hook stages
>   Caliber‑managed files, unstage them before committing.
> - The step is not shippable until its Definition of Done (plan §3) is fully met and tests are
>   green under PHP 7.4 for this lib and all already‑backported dependencies.

## Startup sequence (do these first, in order)
1. **Branch model (settled):** every step's PR merges into the **`php7.4` integration branch** —
   that is the local trunk for this effort. The real `master` is **never** written to.
2. **Cut the branch:** `git checkout master && git pull --ff-only && git checkout -b php7.4`.
3. **Create the tracker** `plans/PHP74_BACKPORT.md` — a checkbox list of every phase and step from
   plan §6. You tick each step when its PR merges. This is your durable progress state; re‑read it
   on resume so you never redo a merged step.
4. **Run Phase 0** (branch + global tooling: `scripts/affected-libs.php`, CI matrices,
   `.php-cs-fixer.dist.php`, root composer policy — plan §5) as ordinary steps via the substep
   pipeline.
5. Then spawn Phase agents **1 → 6** in order. Within a phase, follow the exact step list and the
   step‑sizing rule (plan §3.1: ≤ ~10 src files or one sub‑namespace per step; a lib's
   composer.json + phpunit.xml are done in its FIRST step).

## Critical correctness rules (from the Rulebook — enforce via the Reviewer)
- **`match` → `switch (true)` + `case $expr === X`** to preserve strict comparison; no‑default
  match becomes `default: throw new UnhandledMatchError` (use the candy‑core polyfill shim).
- **Enums become classes** extending `SugarCraft\Core\Polyfill\BackedEnum`/`UnitEnum` (singletons,
  so `===` and `::cases()` order hold). Call sites change `Enum::Case` → `Enum::Case()`. The
  Tester adds an identity + `cases()`‑order + `from`/`tryFrom` test per converted enum.
- **`readonly` is stripped** (7.4 can't enforce it) and replaced with `/** @readonly */`; rely on
  the existing immutable `with*()` convention. No runtime guards.
- **PHP 8.0 stdlib functions are NOT rewritten** — they are provided by `symfony/polyfill-php80`
  (add to candy‑core; transitive for its 52 dependents; add directly to leaf libs that need it).
- **Fibers (candy‑ansi, candy‑testing) and WeakMap (candy‑flip)** are the only redesigns — read
  actual usage first; version‑gate or use a generator/`SplObjectStorage` fallback; mandatory extra
  review rounds and a risk note in the PR body.

## Dependency ordering (hard requirement)
Follow the topological order in plan §6/§9. A lib is backported only after **all** its
`sugarcraft/*` deps are 7.4‑clean — because path‑repo symlinks expose dependency *source* to
phpunit and 7.4 cannot parse un‑backported 8‑syntax. `candy-core ↔ candy-pty` is a cycle and is
done as one fused phase (Phase 2). Each step's Tester runs `composer update && vendor/bin/phpunit`
to prove the whole dependency set parses on 7.4.

## In‑progress work — do not disturb
`sugar-reel` is under active development (and any other lib the requester is editing). It is
scheduled **last** (plan §5.7). Before its step, re‑audit it fresh and confirm with the requester
that their feature work is merged. **Never start a lib's backport while it is being edited.**

## Ship recipe (Ship substep, every step)
```sh
git checkout php7.4 && git pull --ff-only
git checkout -b php7.4/<phase>-<lib>[-<chunk>]
# ... coder/reviewer/fixer/tester/documentor changes already applied ...
git add -A && git commit -m "<lib>: PHP 7.4 backport — <scope>"   # author Joe Huss <detain@interserver.net>
git push -u origin php7.4/<phase>-<lib>[-<chunk>]
unset GITHUB_TOKEN && gh pr create --base php7.4 --title "<lib>: PHP 7.4 backport — <scope>" \
  --body "Backports <scope> to PHP 7.4. Rulebook rules applied: <list>. ## Test plan: <N> tests green on PHP 7.4."
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
git checkout php7.4 && git pull --ff-only
```

## Definition of done (per step — gate before Ship; full list in plan §3)
- [ ] Every in‑scope PHP 8+ construct removed; `php -l` (7.4) parses every touched file.
- [ ] No public API change (names + param order identical; types may erode to docblocks).
- [ ] `cd <lib> && composer update && vendor/bin/phpunit` green on **PHP 7.4**.
- [ ] `phpunit.xml` schema 9.5; tests use annotations not attributes.
- [ ] composer constraints updated (plan §5); `php tools/check-path-repos.php` passes.
- [ ] Reviewer APPROVED; docs/PHPDoc/CALIBER_LEARNINGS updated.
- [ ] One commit, one merged PR into `php7.4`; tracker checkbox ticked.

## Completion
The effort is done when Phase 6 is complete: full‑monorepo `composer update && phpunit` green on a
real 7.4 interpreter, the 7.4 CI matrix green on the `php7.4` branch, docs/site/MATCHUPS updated,
and every box in `plans/PHP74_BACKPORT.md` ticked. Report a final summary: steps shipped, PRs
merged, and any items escalated.

**Begin with the Startup sequence. Do not start lib edits until you have read `php74_branch.md` in
full and created the tracker.**
