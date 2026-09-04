# prompt_resume.md — the entry point for the prompt-architecture plan

> **This file has two jobs, and which one it is doing depends on when you read it.**
>
> 1. **Before the plan starts** it is a **start prompt**: hand it to a fresh agent with no prior
>    conversation and the plan begins correctly.
> 2. **After the plan starts — which is where it is now** — it is **rewritten after every step** so
>    that it becomes a **resume prompt**: hand it to a fresh agent with no prior conversation and the
>    plan picks up from exactly where it is and runs to the end. That is this file's job today, and
>    §0 is the instruction that makes it run to the END rather than to the next question.
>
> The rewrite instructions are in §R at the bottom. They are part of the file on purpose — whoever
> rewrites it is reading it.

**Current state: PHASE 5 IN FLIGHT - P5.S3 CLOSED (33 of 63), Phases 5: 3 of 6 merged. master = resume/worklog bookkeeping tip directly over `de81f45e4` (= P5.S3 merge `97ced919e` + upstream sync `71cab0fca` + skip-roster fix), clean tree. The 2026-09-04 USER-AUTHORIZED upstream sync landed 16 origin/master commits (other lane's qwen/sglang Q1-Q10 provider work - incl SINGLE-SYSTEM MERGE which touches the Sglang multi-system concern from the P0 audit) + `composer update -o -W` (law waived, named reason) - vendor/sugarcraft is now 20 PACKAGIST REAL DIRS, not local symlinks; every figure below reflects that tree. NEW AUTHORITATIVE BASELINE: 10774 / 165986 / 0F / Skipped 2 / EXIT 0. Goldens UNMOVED through P5.S3 (escape is transparent on clean content) - contrary to the old plan expectation for S3; legitimate movement still expected at S4-S6. Next: author P5.S4 brief + step-text extraction, then S4 lead; S4->S6 serial. §8 carries current state.**
---

## 0. STANDING ORDER — run to completion

**The user has asked for this file to be handed to a fresh agent that then runs the plan to the end.
That is your instruction. Do not stop at a phase boundary to ask whether to continue.**

Concretely:

1. Work the queue in §8 in order. When Phase 3's close review passes, **immediately open Phase 4**
   and keep going. Same at every later boundary. `prompt_plan.md` has twelve phases (0-11) and 63
   steps; Phases 0-2 are closed, Phase 3 is nearly closed, Phases 4-11 remain:
   **4** Token accounting and cache observability · **5** The PromptSection architecture ·
   **6** The rules tier and the trigger union · **7** Wire the dormant seams ·
   **8** Rebuild the compaction prompt · **9** Tool descriptions as prompt ·
   **10** Cache breakpoints · **11** Docs, sweep, final audit.
2. **Rewrite this file and append to `prompt_worklog.md` after EVERY step and EVERY phase close.**
   Not at the end of a session — after each one. If you are running out of context, doing this is the
   last and highest-value thing you do (§R).
3. **Decide the ordinary things yourself.** Batch composition, merge order, whether a finding is worth
   a fix step, whether an agent's work meets the bar, whether to schedule a follow-up as its own step
   or fold it into a queued one. You are the orchestrator; that is the job.

**STOP AND ASK only for these.** Everything else, keep moving.

- A **§1.10 dormant-code escalation** — you or an agent would have to REMOVE unfinished, dormant,
  unwired or unreachable code, or the only way forward is a redesign. Record it verbatim under
  `Awaiting user decision:` with `file:line`, what calls it (or that nothing does), and the options.
  **Escalating is a COMPLETED step, not a failed one — record it and MOVE ON to the next step.** Never
  block the queue on an unanswered escalation.
- A **genuine blocker**: the work cannot proceed without an answer, and no assumption is safe.
- Anything that would need a **`git push`**, or would touch `/home/sites/crush-lane-{a,b,c}` or
  `docs/plans/crush_code_*.md` / `left_steps.md`.
- **Before starting Phase 5 or Phase 6**, check §5's collision table. The `src/` file-count census
  reason is RESOLVED and no longer serialises those phases, but per-file collisions with the other
  plan are still live. If a lane has a round in flight on a file a step declares, ask the supervisor
  rather than reading the lane worktrees — and in the meantime run the steps that do not touch it.

**One decision is outstanding right now and it does NOT block anything** — Gemini function calling,
described under `Awaiting user decision:` in §8. Carry it forward unanswered, every rewrite, until the
user answers. Do not decide it yourself and do not let it hold up the queue.

---

## 1. Who you are and what you are doing

You are the **orchestrator** for the sugar-crush prompt-architecture plan, running in
`/home/sites/sugarcraft` on branch `master`.

You do not write production code. You spawn agents, verify their work against test output you run
yourself, merge it, commit it, and maintain two bookkeeping files. Everything that touches
`sugar-crush/src/` or `sugar-crush/tests/` goes through a spawned step agent, every time, including
changes that feel too small to be worth spawning for.

## 2. Read these first, in this order

1. **`/home/sites/sugarcraft/prompt_plan.md`** — your complete operating manual. Read it in full
   before doing anything else. §1 is the execution contract, §2 is concurrency, §3 is bookkeeping,
   §4 onward are the phases, §16 is the lessons every agent you spawn must be given, §17 is the
   invariants, §18 is what not to build. Inside §1, **§1.10 (removal is not an available outcome)**
   and **§1.11 (what counts as a test)** go to every agent you spawn, alongside §16 and §17.
2. **`/home/sites/sugarcraft/prompt_worklog.md`** — the record. It holds the conventions, the
   required entry format, one worked example, and every step entry so far. Read the format; you will
   be writing in it after every step.
3. **`/home/sites/sugarcraft/prompt_expand.md`** — the 4,063-line research dossier the plan
   executes. Do **not** read it end to end now. Each step names the sections its agent must read;
   read those sections when you brief that agent, and read §0 and §1 now so you understand the lead
   finding.
4. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` — repo conventions.

## 3. What has been built so far

Phases 0-3 are closed; Phase 3 closed when close-review cycle 3 (FINAL) found no code or test defect —
its six record-side findings were fixed and merged as `58150a432`. Ten things a fresh agent needs about the
**current** shape of the code:

1. **The prompt reaches the model.** `Runtime::buildSystemPrompt()` (`sugar-crush/src/Runtime.php`)
   assembles seven layers and `Runtime::run()` puts them on `CompleteRequest::$systemPrompt`. SIX
   of the seven providers transmit it on **both** `complete()` and `completeStream()` — the
   EchoProvider is EXEMPT and the exemption is pinned by assertion.
   `tests/Providers/SystemPromptTransmissionMatrixTest.php` pins the wire slot per protocol against
   a roster derived from `src/Providers/`. Measured end to end: **assembled == golden == wire,
   5,176 B**, `messages[0].role = 'system'`.
2. **Vertex has THREE arms.** `P1.audit-fix-1` (`03d8fed37`) hoisted the prompt into the Google
   `instances[0].context` slot; `P1.audit-fix-3` (`e0d00b6db`) built a real Gemini
   `:generateContent` arm with `systemInstruction` and streaming. Routing is by model FAMILY, not
   publisher; the legacy `instances` arm stays for `chat-bison`. **Gemini still cannot call tools** —
   see `Awaiting user decision`.
3. **The prompt is deterministic and golden-pinned.** Clock, platform and cwd are injectable;
   `golden-system-prompt.txt` (md5 `32ea749d…`) pins the assembly byte-for-byte and
   `golden-agent-prompt.txt` (`ef0326dd…`) pins `Agent::systemPrompt()`. Both **unmoved since P3.S5's
   merge point (`405252a41`)** — re-confirmed at `99227d29c`: audit-fix-3's fixtures diff is zero
   bytes. **State the window a no-move claim covers** — see §4's correction for what happens when
   you do not.
4. **`<env>` IS LAST IN BOTH ASSEMBLERS.** P3.S1 moved it from Runtime layer 2 to layer 7. The two
   assemblers are still deliberately separate, but on a **layer-set** argument (seven layers versus
   two), NOT an ordering one. Since `P3.audit-fix-3` BOTH assemblers share **ONE project-root
   resolution** — the `--root` flag orients the `<env>` block of the live Agent/WorkflowEngine path,
   not just Runtime's (a close-review finding: the seam no single step's file list could see).
5. **The write-signal is WIRED on the Runtime path, and MEASURED-BUT-NOT-WIRED on the Agent path.**
   P3.S5 (`405252a41`) marks the `Runtime` from `EngineBackend`'s per-step loop. P3.S6 (`f958ba8e6`)
   established the Agent path's per-step seam **is real and live** — in `Workflows/WorkflowEngine.php`,
   outside its declared scope — and pinned the cost instead: one render = 5 git subprocesses (3
   suppressed), a K-stage workflow = 5×K, one `ProcessExecutor` dispatch = 10 because it renders
   twice, and the stages see ONE DISTINCT PROMPT. A §18 row records this as **escalated, not
   waived**, and an exact-list assertion over `AgentResult::__construct` reds the day a tool-call
   field is added — the change that unblocks it.
6. **The write-primitive scanner fails CLOSED. Eighteen-plus defeats, five reviewers.** Beyond the
   `T_NAME_RELATIVE` and alias-subtraction fixes, `P3.audit-fix-3` read the CONSTRUCTION CHANNEL:
   anon classes and same-file named subclasses (with aliased parents), `self`/`static`/`parent` in
   every scope, and `class_alias()` in every string spelling — indented heredoc/nowdoc terminators
   and escaped-backslash decode included. **Count the ledger from `tests/RuntimeTest.php`'s own
   pins — never trust a brief's number.** An unknown spelling costs a FALSE POSITIVE, never a
   silent miss — except the declared residuals, EACH PINNED: cross-file trait users,
   cross-file/imported parents, NAMESPACED extends parents, same-file CONSTANT and computed
   `class_alias`, roster-side self-in-subclass, and cross-file LITERAL `class_alias` pairings — the
   last declared at close-review cycle 3 (MEASURED LATENT; `class_alias` count in `src/` is 0).
7. **The tree-wide census set is DERIVED, not hand-maintained.**
   `sugar-crush/tests/TreeWideGuardRosterTest.php` walks 440 test files and derives **67** guards
   that scan `src/` or `tests/` wholesale, `unaccounted 0`. `P3.audit-fix-3` extended the
   classifier's alias arms (imported-`as`, `class_alias` literals) — the five derivation numbers
   are **UNCHANGED**: the new arms are declared-latent, zero live population. `prompt_plan.md`
   §1.2 action 7b points at it.
 8. **Usage has real buckets and the providers fill them honestly.** `src/Usage.php` carries
    `inputTokens`/`outputTokens`/`cacheReadTokens`/`cacheCreationTokens` (each `?int`, missing != 0),
    `promptTokens() = cacheRead + cacheCreation + input` (output deliberately EXCLUDED — identity and
    reason pinned), and the fork socket round-trips all six keys with null-vs-zero intact. Every
    provider routes wire usage through public parse seams; per-provider cache-field EVIDENCE (live
    probe / vendored DTO / UNVERIFIED-documented) lives in the worklog — where a protocol reports no
    cache field on the real deployment (Sglang/Custom, probed 2026-09-02) the parser invents nothing
    and a test pins the absence. The P4.S1 `UsageTest` tripwires pass by REAL remediation: an
    expected provider-set + exact-count locators red the day any provider's split regresses.
 9. **Cache health renders in the STATUS LINE only.** `Renderer::cacheIndicator()`/`formatCacheAge()`
    add a fourth fitted piece below spend — `round(cacheRead/promptTokens()*100)`% + age of the
    newest reported usage entry, TTL printed never coloured (design §4.16). The hard constraint —
    zero transcript messages — is pinned by a 12-tick armed-loop test carrying its own known-positive
    control, per-tick transcript-signature asserts and an in-test painted-frame needle scan; a
    planted `/context`-style append reddens the signature claim ITSELF, first red at tick 0.
10. **Buckets have NO response-path consumer yet** — the widen-CompleteResponse seam is recorded-open
    in §8's travel ledger: parse + total/cost routing is live, end-to-end cache observability ships
    when that seam lands. The status line is the one shipped consumer.
 11. **E18: one oversized exchange no longer starves the queue.** `ContextCompactor::truncateOversizedExchange()`
     (:229-305) truncates ONLY a message whose own estimate reaches the blocking tier — aggregate overflow still
     returns byte-identical; `Chat::intraExchangeTruncation()` rescues BOTH blocking sites (`submit()` and
     `applyModelCompaction()`), rebuilding via `messageWithContent()` — an 11-field splice-copy pinned by a
     known-answer test (a gutted copy re-stamping `createdAt` reddens it). Measured before/after on a real
     800,040-char exchange: rising refusals [200520..201032] became [200520, 93126, 93149, 93172, 93195] with
     every turn dispatched; the +23/turn honest growth is disclosed, asserted as never-above-first-reading. The
     95% tier constant untouched; goldens unmoved. Residual recorded in the docblock: a drafted (not sent) giant
     can still rescue ~108,113 estimated tokens into a 100,000 window — draft-aware bound is a follow-up.

 12. **PromptSection + PromptFence are LIVE (P5.S1/S2/S3).** `Runtime::buildSystemPrompt()` renders an ordered `PromptSection` list through ONE assembler; P5.S2 migrated the env/memory/repoMap snapshot OBJECTS onto it (wrap-not-copy, memoization identity-pinned; the assembler's `render()===''` skip is the sole suppression). P5.S3 added `src/Context/PromptFence.php` — the ONE fence-escape authority (byte-oriented, 5-tag roster, idempotent, fail-loud) — and routes ALL FOUR production fences' user-/repo-derived bytes through it: `<project-memory>`, `<env>` (incl BOTH carried HIGH/SECURITY vectors: the unstaged-edit diff-body forge AND the raw branch-name interpolation), `<repo-map>`, and the inline `<project-instructions>` splice. A6 characterization pins now assert FIXED polarity. The 255-per-component ref argument is dead: raw git reads are capped at 255 + escaped, pinned by a real 359-byte multi-segment ref. Goldens UNMOVED since P5.S1 - escape transparent on clean content.

## 4. How to resume

**Key figures below were measured by the orchestrator 2026-09-04/02 and each row names its sha. Re-run a check ONLY
if the sha it names has moved. Do not spend seven minutes re-measuring a suite this file already gives you.**

**IN FLIGHT: NOTHING.** P5.S2 merged `5c8505501`; every step worktree of mine removed; every branch merged or
deleted. Master = bookkeeping tip directly over it, porcelain clean (ignore `.ocx/receipt.jsonc` - harness
telemetry, `git checkout --` before porcelain gates). Next action: WRITE THE P5.S3 BRIEF (see §8), then spawn
its lead.

### Verified this session — do NOT redo unless the sha moved

| Check | Result | Measured at |
|---|---|---|
| **MASTER full suite (CURRENT, post-P5.S3 + UPSTREAM-SYNC + roster-fix)** | **`Tests: 10774, Assertions: 165986, Failures: 0, Errors: 0, Skipped: 2`, EXIT 0** (cwd checkout root, serial, stdin=/dev/null, probe 0, prediction-first; box-law pair GREEN this arm; Skipped = 1 roster + 1 conditional GitignoreAwareness path-repo-symlink guard whose precondition is absent in packagist layout) | `de81f45e4` |
| (SUPERSEDED by P5.S3 + upstream sync) **MASTER full suite (post-P5.S2)** - branch-tip figure carried by the diff-empty argument (belt deliberately NOT re-run), cwd /home/sites/prompt-step-P5.S2 root, </dev/null, serial, probe 0 | **Tests 10665 / Assertions 165289 / 0 fail / 1 skip** (MMG-201 clean arm; 165286 at 198; prediction written BEFORE and hit exact). merge-base == master tip 4493db1e2 and merge tree == gated tree de9e8aceb (diff empty) so the figure describes master by construction. Nine-file census 176/31593; roster 17/1103; testFiles 441; unaccounted 0; goldens UNMOVED system 32ea749d84938811ac9331419cae7380 / agent ef0326dd38535aaa2f1d715919bff26e | branch `5c8505501` (gate @ de9e8aceb) |
| **MASTER full suite (SUPERSEDED by P5.S2)**, gate at branch tip + belt on master, both cwd-checkout-root, `</dev/null`, serial, box quiet | **`Tests: 10644, Assertions: 165010, Skipped: 1`**, 0 failures (EXIT 0) - MMG-198 arm (165013 at the other arm). Test count EXACT vs prediction; cmp.py vs the P4.S4-era junit: sole movers ExchangeSummaryTest +18a/+6t + MouseModalGuard arm flip 201->198, ZERO remainder; post-merge belt on master printed the IDENTICAL figure (`/tmp/p4s5-belt.out`); `git diff --stat e2554332a master -- sugar-crush/` EMPTY so the figure provably describes master | `142cef6ce` |
| (SUPERSEDED by P4.S5) **MASTER full suite at the post-P4.S4 tree**, checkout root, `</dev/null`, serial, box quiet | **`Tests: 10638, Assertions: 164995, Skipped: 1`**, 0 failures (EXIT 0) — MMG-201 arm; 164992 at the 198 arm. Gate at branch tip `0ca4c088d` + belt re-run on master `/tmp/p4s4-belt.out` printed the IDENTICAL figure; `git diff --stat 0ca4c088d master` EMPTY | `1500ad32b` |
| P4.S4 scope + cycle record | merged 3 files +1848/−7 (`src/Context/ContextCompactor.php`, `src/Chat.php`, `tests/Context/ContextCompactorTest.php`); the declared `tests/CompactorTest.php` proved UNRELATED (different class) — reported not edited; lead cycles 1-5 (dedicated fixers; one fixer death ladder-handled), orchestrator cycle-6 fixer closed 3 MAJORs (alignment pin, 11-field copy pin, comment-only headroom narrowing — 2938/2938 token-identity proven), cycle-7 fresh reviewer NO FINDINGS incl revert-experiment; identity 8/8 literal, 0 EMAIL bytes | `1500ad32b` |
| P4.S5 scope + outcome | CLOSED AS MEASURED (outcome b): E23 collapse REAL+reachable (text-only sha256 key `src/Context/ContextCompactor.php:96-99`; both twins offered `:625`; one shared summary lookup `:1200`; tool_calls payloads key-blind) but LOSS measured FALSE - `summarizeExchanges` emits one row per PAIR, each twin keeps its line; the only collapse-attributable effect is the `Chat::parseExchangeSummaries` isset-guard dropping a second paraphrase of byte-identical text (benign by content identity); the `[2x]` fold is stage-3 documented design and fires with no map. 2 files +251/-2, src COMMENT-ONLY (elementwise token identity, lead + reviewer); 6 pins in the PRE-EXISTING ExchangeSummaryTest 16/36->22/54, each mutation-isolated; lead 2/5 cycles with dedicated fixer + cycle-2 fresh reviewer PASS; identity 2/2 literal, 0 EMAIL bytes; merge commit amended once for the message fill (parents preserved) | `142cef6ce` |
| F6b gap-filler MERGED | merge commit bcf419855: 5 commits 98aeffbb6..3bea55552, 4 files +10/-10 comment/string-only, line counts preserved; gate cwd F6b worktree root serial </dev/null probe 0 = Tests 10644 / Assertions 165013 (MMG-201 arm; 165010 at 198) / 1 skip / 0 fail / EXIT 0, prediction exact, cmp.py sole mover MouseModalGuardTest 198->201 (+3, dTests 0) - zero unexplained; sugar-crush diff 3bea55552 vs master EMPTY (tree-wide diff = the 3 bookkeeping record files only, read by no test); identity 20/20 literal + 0 EMAIL bytes; C1 5 / C2 2 / C3 NO FINDINGS, cap-3 held; belt figure appended in worklog-6 entry | `bcf419855` |
| F6c gap-filler MERGED | merge commit de1048ccf: 5 commits e39b0adc1..4620c5156 + sync 295b95e40, 3 files +83/-9 - PRR 6 cites (incl disclosed :501 STRING change), plan :1606/:1610 rule-42 in-line, StatusLineSegmentTest +74 = transcriptSignature same-count-REPLACE third control (22/4113->23/4117); proofs: PRR 751 LOC + 3113 tokens elementwise, plan 3626 unchanged, goldens byte-identical, path-gate 0; gate at 295b95e40 (cwd F6c worktree root, serial, </dev/null, probe 0) = Tests 10645 / Assertions 165017 (MMG-201 arm; 165014 at 198) / 1 skip / 0 fail / EXIT 0 - prediction exact; cmp.py sole mover StatusLineSegmentTest +4a/+1t, no MMG flip, zero unexplained; ancestry note: user re-merge pulled the sync tip into master history - merge-base = 295b95e40 and rev-list master ^branch = 0, so the merge tree == gate tree and the figure provably describes master (belt = corroboration); review C1 3minor/3nit -> fix-1, lead-caught fix-1 regression -> fix-2, C2 1minor -> fix-3, C3 CLEAN, cap-3 held; brief claims measured FALSE (GlobFigureDriftTest is the strlen() settings-glob generator, NOT a line counter; no guard polices File.php:NNN cites); 9 repo-shared stash entries (other plan) observed, NOT dropped; worktree + branch torn down after merge | `de1048ccf` |
| cwd, branch, clean tree | `/home/sites/sugarcraft`, `master`, porcelain empty | every commit |
| commit identity | `Joe Huss` / `detain@interserver.net` — 24/24 objects author+committer clean across the whole P4.S2 window AFTER the metadata repair; 8 gmail commits remain in P3.audit-fix-2 history (un-rewritable, recorded). NEW ROOT CAUSE of the recurring `[EMAIL]` defect: agents SEE the sanitized token in injected context and ECHO it — briefs must command the literal address; orchestrator object-byte-scans incoming commits before every merge | `80db1b27d` |
| (SUPERSEDED by P4.S4) **MASTER full suite** at the P4.S2-era tree, checkout root, `</dev/null`, serial, box quiet | **`Tests: 10615, Assertions: 164754, Skipped: 1`**, 0 failures (EXIT 0) — MMG-198 arm; 164757 at the 201 arm | synced `47f7b477a` / replay `c8f01cdbe` (orchestrator gate) |
| master tree == the tree that figure was measured on | `git diff --stat c8f01cdbe master` EMPTY — c8f01cdbe is the identity-repaired replay of 47f7b477a (`diff --stat 47f7b477a c8f01cdbe` also EMPTY, trees byte-identical), so the figure describes master and re-running is provably redundant; belt `/tmp/p4s2-master.out` the belt re-run on master at `80db1b27d` CONFIRMED it verbatim: 10615 / 164754 / 1 skip / 0 fail, SUITE-EXIT=0 (`/tmp/p4s2-master.out`) | `80db1b27d` |
| goldens | `32ea749d…` (system) · `ef0326dd…` (agent) — **unmoved since `405252a41`**, zero-byte fixtures diff re-confirmed across the WHOLE Phase-4 window (cycle-9 reviewer measured `f2204a7c4..HEAD`) | `80db1b27d` |
| (SUPERSEDED - packagist layout) nine-file census subset | `OK (176 tests, 31390 assertions)` at the SYNCED tip `47f7b477a`, over exactly the nine `HAND_MAINTAINED_CENSUS_SET` files (`tests/TreeWideGuardRosterTest.php:407-417`), cwd `sugar-crush/`, serial, `</dev/null`. Pre-sync `1eab2e0ed` the same nine read 176/31352 — the +38 is GlobFigure/SymbolCitation derived growth over the synced S3 layer. **A census figure names its file list AND its tree** | `47f7b477a` |
| (SUPERSEDED - packagist layout) **derived** guard roster | roster **67**, candidates 83, walkerFiles 181, testFiles 440, **unaccounted 0** — UNMOVED through all of Phase 4 batches 1-2. The S2 brief's '(NEW) -> 441' claim was FALSE — `UsageWiringTest.php` pre-exists since `738c586c1`; corrected in place. Roster test itself 17 tests / 1,101 assertions | `47f7b477a` |
| P4.S2 scope | merged window 9 files +1934/−103: five providers + `src/Usage.php`/`src/Util/TokenTracker.php` (the latter TWO comment-only, token-identity proven 929/269 elements) + `tests/UsageTest.php` + `tests/Integration/UsageWiringTest.php` | `80db1b27d` |
| metadata-only repairs (record) | `f7122adfb` = tree-identical rewrite of one `[EMAIL]`-authored tip commit (cycle-5 era, via commit-tree + rebase --onto); `c8f01cdbe` = the same repair for the fix-8 pair (`10907d74e`/`1f009e095`) + sync replay — all verified tree-byte-identical to originals, 0 EMAIL bytes in every object | `80db1b27d` |
| detached-HEAD anomaly (record) | the FIRST S3 merge landed on stray `211f4f5b1` — the main checkout had crept to detached HEAD; master ref unharmed, reflog keeps the stray; repaired by checkout + re-merge → `23a36254b`. LAW: assert `branch --show-current` == master AND porcelain 0 in EVERY pre-merge check | `23a36254b` |
| path-repo gate, **from the repo root** | `php tools/check-path-repos.php --no-lib-path-repos` exit 0 | `80db1b27d` |
| **nine-file census subset (CURRENT)** | `OK (176 tests, 31786 assertions)` at `de81f45e4` - pre-sync 31593; +193 derived growth across the sync window | `de81f45e4` |
| **derived** guard roster **(CURRENT)** | roster **67**, candidates 83, walkerFiles 181, testFiles **442**, unaccounted 0; roster test 17/1105 | `de81f45e4` |

### PROGRESSION, all checkout root, all serial — the only comparable series

```
c7e5a6454  10500 / 161982 / 1   pre-merge master
1279d91cf  10503 / 162166 / 1   + P3.S4-fix-1
5cabca4a8  10519 / 162241 / 1   + P3.S5-fix-1     (PREDICTED exactly before merging)
f958ba8e6  10526 / 162447 / 1   + P3.S6           (test count predicted exactly; +12 found and attributed)
980670c0b  10547 / 163710 / 1   + P3.audit-fix-2  (ALL THREE figures predicted exactly)
5f716b34d  10556 / 163806 / 1   + P3.audit-fix-3  (MMG-198 arm; the lead's 163809 = MMG-201 arm;
                  cmp.py: sole mover MouseModalGuardTest 201->198, dTests +0, every other class
                  identical; +205 tests / +3,158 assertions at this arm vs baseline 10351/160648)
58150a432  10556 / 163809 / 1   + P3.CLOSE-r3-fix  (record-side; RuntimeTest +16 doc-comment lines,
                  comment-only proven token-identical; orchestrator re-ran the full suite on master
                  at THIS tip: 10556 / 163809 (MMG-201 arm) / 1 skip — see gate-r3fix.out)
f2204a7c4  10574 / 163972 / 1   + P4.S1   (MMG-198 arm; 163975 at 201; prediction hit exactly;
                  UsageTest 19/54 -> 37/191; nine-file 176/31255 -> 176/31284 via GlobFigureDrift +29)
23a36254b  10582 / 164469 / 1   + P4.S3 lead   (MMG-198 arm; every lead suite run pre-predicted;
                  orchestrator gate at 770873a9d exact; StatusLineSegmentTest 55 -> 63 tests)
a834207d4  10582 / 164483 / 1   + P4.S3-fix8   (164486 at 201 arm; test-only StatusLineSegmentTest
                  +68/-16; fix-8 agent suite prediction 164469+14 EXACT; orchestrator belt exact)
80db1b27d  10615 / 164754 / 1   + P4.S2   (164757 at 201 arm; prediction 10612 tests, the +3
                  adjudicated per-class: UW restructure folded 3 pre-existing cases in; cmp.py
 5a87ce80a  10615 / 164754 / 1   + bookkeeping (resume rewrite #3, three worklog entries, S2 brief roster correction)
 1500ad32b  10638 / 164995 / 1   + P4.S4   (MMG-201 arm; 164992 at 198; gate at 0ca4c088d + belt on master
                   IDENTICAL; ContextCompactorTest 57->80 tests; +241/+23 attributed per-class zero remainder)
 b7ec850c6  (bookkeeping - resume rewrite #4, P4.S4 worklog entry, S4+S5 step briefs; no suite delta)
 8b4167d32  (record correction - nine-file census provenance 31460 -> 31461; no suite delta)
 142cef6ce  10644 / 165010 / 1   + P4.S5   (MMG-198 arm; 165013 at 201; gate at e2554332a, test count EXACT vs
                    prediction; cmp.py sole movers ExchangeSummaryTest +18a/+6t + MMG arm flip 201->198, zero
                    remainder; belt on master IDENTICAL; PHASE 4 CLOSED - 30 of 63)
 5c8505501  10665 / 165289 / 1   + P5.S2   (MMG-201 arm; 165286 at 198; gate at branch tip de9e8aceb == merge tree ->
                    belt skipped via diff-empty argument; +6t/+59a fully attributed to 6 new RuntimeTest pins;
                    lead cyc1 2x CLEAN; independent review-2 CLEAN 0maj/1minor-preexisting; E1/E2/E3 + four sandbox
                    reversions reproduced; census 176/31593; goldens byte-identical - step 32 of 63)
 97ced919e  10696 / 165527 / 1   + P5.S3   (fence-escape authority PromptFence + both carried vectors CLOSED + ref-cap + A6 rewritten; gate caught 2 history-dependent guards -> fix-2 rescoped them; review-3 re-gate hit green arm to the unit; cmp bit-reproducible; belt skipped - diff-empty arg; merge message defang ruling; 33 of 63)
 71cab0fca  10774 / 165983 / 2   + UPSTREAM-SYNC (origin/master 16 commits - qwen/sglang Q1-Q10 lane, ZERO conflicts; composer -o -W by user waiver - vendor now packagist real dirs; NO new php errors; SuiteSkipRoster EXIT 1 -> conditional entry)
 de81f45e4  10774 / 165986 / 2   + roster-fix (tests-only; full suite EXIT 0 at the NEW FLOOR; sole cmp mover +3 explained by the roster loop)
```

### FOUR METHOD CHANGES THAT ARE NOW PART OF THE PLAN — use them

1. **Reconcile a moved total with a per-class JUnit diff, FIRST — not last.** PHPUnit's JUnit
   `<testcase>` carries an `assertions` attribute. Run both sides with `--log-junit` and diff per
   class: `python3 prompt_kit/tools/cmp.py <branch-junit> <master-junit>`. **This is the single
   highest-leverage tool this plan has produced.** On P3.audit-fix-2 it took a `+7` remainder the
   per-file figures could not explain and named five tree-wide guards in one pass.
2. **The census set is DERIVED now — run `tests/TreeWideGuardRosterTest.php`.** The hand-maintained
   nine survive as a cheap pre-check. Do not grow the list; if something is missing, the derivation
   is what should be taught to see it.
3. **State a prediction BEFORE running a suite, then check it.** Done on every merge in Phase 3. On
   P3.audit-fix-2 all three figures were predicted exactly. A prediction that misses is information;
   a figure with nothing to compare against is not.
4. **A measurement can be provably redundant, and saying so beats re-running it.** Sync the branch to
   master BEFORE measuring; then after merging, `git diff <synced-tip> HEAD` empty proves the branch
   figure describes master. Record the reasoning, not a second seven-minute run.

### CORRECTIONS SO THEY STOP PROPAGATING

- **`ps -eo pid,cmd | /usr/bin/grep -c '[v]endor/bin/phpunit'` — the box-quiet probe this plan has
  used for weeks — RETURNS A FALSE 1.** The `[v]` bracket defeats a self-match by grep, but not by
  the harness's enclosing `bash -c`, whose argv contains the whole script text including the phpunit
  path. So it alarms whenever it runs in the same command as the suite it guards, which is the only
  way anyone runs it. *How measured:* printing the matching line gives a single
  `/bin/bash -c source …` wrapper and no php process. The failure direction is a false ALARM, the
  safe one — **but an alarm nobody can explain is an alarm that gets waved through, and the day it
  means something it will look identical.** Use instead:
  ```sh
  ps -eo cmd | /usr/bin/grep -c '^php .*phpunit'      # 0 = box quiet
  ```
- **THE GOLDENS WERE NOT "UNMOVED THROUGH THE WHOLE OF PHASE 3".** *What is true:* the system golden
  moved three times and the agent golden twice; both have been unmoved only **since the P3.S5 merge
  point**. *How measured:* `git show <sha>:…/golden-*.txt | md5sum` at each Phase 3 merge point.
  All three moves are legitimate: P3.S1's and P3.S3's ARE those steps' stated purpose, and the third
  is fixture hermeticity (`OS version: Linux 6.8.0-138-generic` → `OS version: <host>`), not
  behaviour. **Why it matters:** the false sentence taught the next reader that any golden move in
  Phase 3 is a red flag, so they would either alarm at three legitimate moves or trust the sentence
  and skip the check. **State the window a no-move claim covers, or the claim is unfalsifiable.**
- **AND THE FIRST VERSION OF THAT CORRECTION GOT THE ATTRIBUTION WRONG** — caught by the close
  reviewer within the hour. It said the `<host>` change happened at **P3.S5**, because that is the
  row where the md5 changes. **P3.S5 moved NEITHER golden**; the change was `33df838d0`
  (P2.audit-fix-1), which sits between P3.S4 and P3.S5 in first-parent order.
  **THE GENERAL LESSON:** a table of `git show <sha>:file | md5sum` gives **state at each point**,
  and every merge inherits everything merged before it. Turning that into "step X changed it" is a
  category error. **To attribute a change to a commit, ask the commit:**
  `git diff <sha>^ <sha> -- <path>`, or `git log --first-parent -- <path>`. A value first differing
  at row N means the change landed at or before N — not at N.
- **`--filter AgentTest` is a regex that ALSO matches `SubAgentTest`.** Prefer a path
  (`… sugar-crush/tests/Agents/AgentTest.php`) when you want one file.
- **Assertion totals are DETERMINISTIC across sequential uncontended runs** — proved twice on
  P3.S5-fix-1 (162057 both times). The old 18-assertion spread came from two **concurrent** full
  suites. Keep the box quiet for a merge-deciding figure; do not treat single-run totals as noisy.
- **BEWARE BACKTICKS IN A `git commit -m "…"`.** Bash runs them as command substitution; it corrupted
  the P3.S6 merge message in two places. **Write commit messages to a file and use `-F`.**
- **Bash cwd DOES persist in this harness.** A `cd` in one call leaves the next call there. It has
  produced a false GREEN once — a `git diff` whose pathspec matched nothing from the wrong directory
  printed nothing, which reads exactly like "no changes". **Anchor with `git -C <path>` and absolute
  paths.**

- **A HEADLINE SUITE FIGURE CAN HAVE TWO LEGITIMATE VALUES.** `MouseModalGuardTest` arms itself by
  viewport (assertions 198 vs 201 — observed variants 168/181 too; under a live tty even a hard
  failure at `:792` even with `COLUMNS`/`LINES` unset — tty-ness itself drives it). So
  `163,806` and `163,809` are BOTH "the master number". NEVER adjudicate a ±3 headline delta by
  headline: run `cmp.py` per-class first — if the sole mover is MouseModalGuardTest, the tree did
  not change behaviour. Do NOT weaken or "fix" the test.
- **md5 OVER A RE-SERIALIZED TOKEN STREAM IS SERIALIZATION-DOMAIN SENSITIVE.** The cycle-2 brief
  quoted md5 prefixes of stripped `token_get_all()` streams; they did not reproduce under another
  (equally honest) serialization even though the underlying claim was TRUE. State token-stream
  identities **elementwise** (token count + array-equality of `[id,text]` pairs).
- **THE 320 / 29,926 NINE-FILE CENSUS FIGURE WAS AN ORCHESTRATOR ERROR.** It was propagated from a
  pickup verification note that carried NO FILE LIST, and the set it described was never the nine.
  Corrected 2026-09-02 by direct measurement: the nine `HAND_MAINTAINED_CENSUS_SET` files measure
  **OK (176 tests, 31255 assertions)** at `cf41aacd6` (176 / 31245 at `470e43569`) — cwd
  `sugar-crush/`, serial, `</dev/null`. **THE LESSON:** a census figure must always be accompanied
  by the file list it was measured over; without its domain a number is not a measurement, and this
  one survived into a brief, a worklog entry and this table before anyone re-derived it (rule 1).
- **AGENTS ECHO THE SANITIZED IDENTITY TOKEN.** Two P4.S2 fix agents committed with author email
  literally `[EMAIL]` — their context shows that placeholder wherever the address is named, and they
  copied what they saw instead of writing the real value. Cheap to repair PRE-merge
  (`git commit-tree` metadata rewrite with env-var identities, trees byte-identical — done twice:
  `f7122adfb` and `c8f01cdbe`); impossible after push. BRIEFS MUST SAY: write
  `Joe Huss <detain@interserver.net>` as a literal, never copy an identity from injected context.
  ORCHESTRATOR: `git cat-file` byte-scan every incoming object before merging.
- **FINALIZE AND EYE-READ THE MERGE MESSAGE FILE BEFORE THE MERGE.** One merge ran while the message
  still carried the `GATEFIGURELINE` placeholder, because the fill script died on a backslash-n
  literal inside a single-quoted python string — the recurring harness trap, violation number six.
  Detected at once; fixed via `git commit --amend -F` (message-only, both merge parents preserved) —
  tip moved `73f238bfe` -> `80db1b27d` with an identical tree. Record the amend, never hide it.
- **A FIGURE NAMES ITS TREE, NOT JUST ITS FILE LIST.** 176/31352 and 176/31390 are BOTH true — of
  different trees (pre-sync `1eab2e0ed` vs synced `47f7b477a`). Quoting one as "the" census figure
  made a review-brief claim false-by-no-fault; cite figure AND commit together.

- **A FINISHED task() SESSION NEVER GETS WOKEN BY A BACKGROUND-NOTIFICATION.** A step lead that ends its turn saying "waiting for <pty_exited>" has ABANDONED the step, not paused it; two such returns happened this session and only the ladder resumption + orchestrator-side disk re-measurement (14 checks) recovered the truth. BRIEFS MUST COMMAND: run long suites IN-SESSION (a task agent may use a background process but must wait on it with bounded sleep+poll of ITS OWN process before returning) or return only when every promised measurement is on disk.
- **`.ocx/receipt.jsonc` DIRT IS HARNESS TELEMETRY.** The tooling rewrites two installedAt stamps in it and dirties the main tree between sessions. `git -C /home/sites/sugarcraft checkout -- .ocx/receipt.jsonc` before any porcelain assertion; never commit it, never stash it. (History shows zero dedicated bump commits - restoring matches the convention.)
- **THE IDENTITY BYTE-SCAN TARGETS THE BRACKETED TOKEN**: `cat-file commit <sha> | /usr/bin/grep -c '\[EMAIL\]'` must be 0. Scanning for bare `EMAIL` self-flags honest prose in your OWN commit message (a P5.S2 merge-message abort at first sight of "0 EMAIL bytes"; adjudicated, message kept, rule recorded).

- **COMMIT MESSAGES ARE FIXTURE INPUT - WRITE THEM TAG-FREE.** The `<env>` block's Recent-commits window renders the checkout's own subjects; PromptFence now defangs tag bytes there (correct behavior), so ANY whole-prompt/whole-block assertion scanning for escape residue (`&lt;`) goes red the moment history legitimately contains a tag - self-referential fixture poisoning, caught by the P5.S3 orchestrator gate AFTER the lead's and two reviewers' green runs (none had run the full suite at final tip). LAW: (a) grep -c '<' == 0 on every commit-message file before use; (b) residue guards scope to the fenced REGION or the specific LINE with presence-first assertIsInt, never whole assembled text; (c) the final full-suite figure must come from a run at the FINAL tip after the LAST subject enters the log window.

- **`composer update` IS NOT PERMANENTLY FORBIDDEN - IT IS ORCHESTRATOR/USER DECISION WITH A NAMED REASON.** Executed 2026-09-04 by user command (recorded WL-B verbatim). Consequence to remember: `sugar-crush/vendor/sugarcraft/*` are packagist REAL DIRS; local monorepo lib edits do NOT reach sugar-crush tests anymore; all pre-sync figures are historical (tree-named); SuiteSkipRoster carries one CONDITIONAL EXPECTED entry (path-repo symlinks) that fires only in this layout - CI's injected layout flips it back to asserting.

- **STALE FIGURE IN THE RESUME ITSELF: PromptSectionTest measures 12/33 (was quoted 12/32).** Agents echo what briefs say; re-derive per-step. The SuiteSkipRoster guard exits the WHOLE phpunit process 1 on roster violations even with 0 failures - read the bootstrap banner, not just the counts line.

### If the tree HAS moved, or you distrust any of the above

1. Confirm `/home/sites/sugarcraft`, `master`, `git status --porcelain` empty.
2. Confirm the identity — it fails silently and cannot be repaired later without rewriting history:
   ```sh
   git -C /home/sites/sugarcraft config user.name    # must print: Joe Huss
   git -C /home/sites/sugarcraft config user.email   # must print: detain@interserver.net
   ```
   Re-check after every step, not only before committing — ORCHESTRATION-RULE-2 in §7. And check
   the AUTHORS of incoming commits too, not just your own config: `git log --format='%an <%ae>'
   <base>..prompt/<ID> | sort | uniq -c` must show ONE identity (the gmail-address incident).
3. Confirm the newest entry in `prompt_worklog.md` matches the last plan commit in `git log`. If one
   is missing, **reconstruct it before doing anything else** (`prompt_plan.md` §3.3).
4. `git worktree list` — expect EXACTLY ONE: `/home/sites/sugarcraft`. Any `/home/sites/prompt-step-*`
   you did not just create is stale; run §1.12's checks before removing it, and check it for ignored
   files worth rescuing first. `/home/sites/crush-lane-{a,b,c}` belong to the other plan.
   Leave them alone.
5. Re-take the master baseline SERIALLY, and record the cwd beside the number:
   ```sh
   php sugar-crush/vendor/bin/phpunit -c sugar-crush/phpunit.xml --colors=never </dev/null | tail -4
   ```
6. Then read §8 and do exactly what `Next step` says.

## 5. The sequencing gate — checked

**CHECKED 2026-08-26, re-confirmed 2026-09-03 — decision: proceed.** Phases 0–4 are safe to run alongside the other plan
(`docs/plans/crush_code_*.md`): they add no new files under `sugar-crush/src/` and touch no
lane-owned files except `Chat.php`/`ContextCompactor.php` in Phase 4. **Do not start Phase 5 or
Phase 6 while the other plan has a round in flight** — they add most of the ~11 new `src/` files and
will fight the file-count census. Re-check before Phase 5 by asking the supervisor rather than
reading the lane worktrees.

Collisions still live (re-check before the named phases):

| Collision | Detail |
|---|---|
| `sugar-crush/tests/Tools/BuiltInToolCorpusTest.php` + `sugar-crush/src/Context/RepoMapBlock.php` | **RESOLVED 2026-08-29 — this was never a real collision during this plan.** The `src/` cardinality assertions were removed by `8706d2ec4` (*"decouple BuiltInToolCorpusTest and RepoMapBlock from the src/ file-count census"*), which `git merge-base --is-ancestor 8706d2ec4 19533373e` confirms is an **ancestor of P0.S1** — so it was already gone when this plan took its baseline. Found independently by two retrospective reviewers. MEASURED: zero integer `assertSame` in that file; zero `297 files` in `RepoMapBlock.php`; adding a `src/` file (298) leaves the census set at `OK (103 tests, 9432 assertions)` — nothing reds. `BuiltInToolCorpusTest.php:285-287` now carries `CENSUS_RESOLUTION = 'this file deliberately asserts NO cardinality over src/…'`. Phases 5/6/10 need **no** thin batches on this account; plan their concurrency from §2.1 like any other phase. Still an ordinary hot *file* if two steps edit it. See `prompt_plan.md` §17.1. |
| `sugar-crush/src/Backend/EngineBackend.php` | Held by an in-flight lane; wanted by this plan's P7.S3. |
| `sugar-crush/src/Chat.php` + `sugar-crush/src/Context/ContextCompactor.php` | The other plan carries a long-standing, untouched backlog of context-window / compaction / spend-cap findings in exactly these two files. This plan's Phases 4 and 8 rewrite them. |
| `sugar-crush/src/Tools/BuiltIn/Bash.php` | The other plan has two open items rewriting its **behaviour**; this plan's P9.S3 rewrites its **description**. |
| `sugar-crush/src/Agents/AgentDefinition.php` | The other plan's `C7` — `$defaultTools` is inert — gates what this plan's P7.S5 preset prompts are allowed to claim. |
| `sugar-crush/tests/Support/` (whole directory) | Assigned wholesale to an in-flight lane. This plan's P2.S4 wants `PromptFixture.php` there; P2.S4 carries the workaround. |
| `sugar-crush/tests/` tree-wide census tests | `SymbolCitationDriftTest`, `SwallowingCatchCensusTest`, `DuplicatedTestHelperDriftTest`, `ChildWallClockBudgetTest`, `EnvRosterDriftTest` and others walk the whole tree. **Every test file this plan adds can red one of them.** Several are owned by in-flight lanes. |

## 6. The loop you run, in short

Full detail in `prompt_plan.md` §1. The short form:

- Pick the next phase's next batch. Five steps at a time, chosen so their declared file lists are
  **disjoint**. Fewer when the phase does not offer five.
- One git worktree per step, branched from current `master`:
  `git -C /home/sites/sugarcraft worktree add /home/sites/prompt-step-<STEP_ID> -b prompt/<STEP_ID> master`
- **Then give that worktree a `vendor/`, or nothing in it can run a test.** `sugar-crush/vendor/` is
  gitignored, so a fresh worktree has no autoloader and no `vendor/bin/phpunit`, and agents may not
  run `composer install`. Hard-link it in and verify it points at the **worktree**:
  ```sh
  cp -al /home/sites/sugarcraft/sugar-crush/vendor \
         /home/sites/prompt-step-<STEP_ID>/sugar-crush/vendor
  cd /home/sites/prompt-step-<STEP_ID>/sugar-crush && php -r '
    $p = require "vendor/composer/autoload_psr4.php";
    echo $p["SugarCraft\\Crush\\"][0], PHP_EOL;'
  ```
  That must print `/home/sites/prompt-step-<STEP_ID>/sugar-crush/src`. **Do not use `ln -s`** — a
  symlinked `vendor/` makes the autoloader resolve to the **main repo's** `src/`, so the agent's own
  edits never load and every test result is about the wrong code. Full detail and the measurements:
  `prompt_plan.md` §1.2 action 2.
- Spawn the step agent with the step text, its file list, the `prompt_expand.md` sections it names,
  and `prompt_plan.md` §1.10, §1.11, §16 and §17.
- The step agent implements **and updates the tests**, then spawns a review agent (brief in
  `prompt_plan.md` §1.4). Findings → fix agent → **a brand-new** review agent → loop. Break only on a
  clean review. Cap five cycles, then the step is blocked.
- **If any agent comes back empty, aborted, or truncated: it died — it did not "have nothing to
  say".** A blank reviewer is not `NO FINDINGS`; a blank step agent has not finished the step. Work
  the ladder in `prompt_plan.md` §1.8: resume the same agent if you can, otherwise read its worktree
  (§1.8.4) to find out how far it got, then relaunch a new agent **in that same worktree** with a
  continuation brief telling it what is already there and not to start over. Blank returns get five
  attempts, not three. Never write the missing report yourself.
- You run the tests yourself and record **your** numbers.
- Merge each step back into `master` in the main repo dir, one at a time, with a test run between
  merges. Commit directly to `master` with the detailed message format in §1.6. **Do not push.**
- Remove the worktree.
- **Append the worklog entry and rewrite this file.** The step is not complete until you have.
- Between batches, spawn a sync agent to bring every live worktree up to current `master`.
- At the end of each phase, spawn a phase review agent over all that phase's commits together.
  Findings → fix → **a new** phase reviewer → loop. Cap three cycles.

## 6a. Harness note — these documents were written for OpenCode, not Claude Code

**Confirmed by the user, 2026-08-29.** `prompt_plan.md`, this file, and `prompt_worklog.md` were
authored assuming OpenCode as the harness. Follow their **substance** in full — the step loop, the
review→fix→**new**-reviewer cycles, disjoint declared file lists, one worktree per step, per-step
bookkeeping, measure-don't-assert, and never removing dormant code. Do **not** follow their
agent-handling **mechanics** literally when the harness is Claude Code.

Specifically, these do not apply here and should not be attempted:

- PTY handling of any kind, and the `pkill -f 'phpunit.*prompt-step-<ID>'` watchdog in
  `prompt_plan.md` §19.
- Judging an agent's liveness by transcript mtime or by hunting a pid, and killing it by pid
  (`prompt_plan.md` §1.8.6). Use the harness's own completion notification.
- Rung 1 of the recovery ladder (`prompt_plan.md` §1.8.3) as an OpenCode capability. Under Claude
  Code, continue a spawned agent with `SendMessage` addressed to that agent; if that is not
  possible, drop straight to rung 2 (read the worktree) and rung 3 (a new agent in the same
  worktree with a continuation brief).
- OpenCode's `task` vs `delegate` spawn routing. Use the normal Agent tool.

**And do not POLL a running agent.** When a spawned agent is working, produce **no output and take
no action** until the harness delivers its completion notification — it always does, with the
agent's full report. Do not emit a stream of short marker messages (`a1`, `a2`, `a3`… or any
`<letter><incrementing id>` sequence) every second or few seconds, do not re-run `ListAgents` on a
timer, do not read the agent's partial-output file to check on it, and do not schedule a short
wake-up to look again. The user has reported this filler **three separate times** across sessions;
it is pure waste, it buries real output, and because it looks like progress it disguises an
orchestrator that is doing nothing. Say once what you are waiting for and what you will do when it
lands, then stop. **This is the same substitution as the rest of §6a**: the OpenCode-era liveness
machinery in `prompt_plan.md` §1.8.6 and §19 exists because that harness had no completion
notification. Keep every rule about *what must be true* — a blank return means the agent DIED, is
never `NO FINDINGS`, and is never a finished step — and drop the polling.

Everything §1.8 says about *what must be true* still holds without change: a blank, truncated, or
aborted response means the agent **died** and is never a result; a reviewer that returns nothing has
**not** returned `NO FINDINGS`; the orchestrator runs its own tests and records its own numbers;
never write a dead agent's missing report yourself.

## 7. Non-negotiables

- Never modify `docs/plans/crush_code_*.md` or `left_steps.md`.
- Never touch `/home/sites/crush-lane-{a,b,c}`.
- Never `git push`, unless a push is genuinely required to complete a merge. If you think it is,
  stop and ask.
- Never run `caliber`. Never suppress a git hook (`--no-verify`, `core.hooksPath=/dev/null`).
- Never run a global `pkill`.
- Never run `composer install`/`composer update` without deciding to for a named reason — it
  de-symlinks `vendor/sugarcraft/*` and silently voids every measurement taken after it.
- Never weaken, skip, rename-out, or delete an existing test to make a change pass.
- **Never remove unfinished, dormant, unwired, or unreachable code — yours or anyone's.** Removal is
  not an outcome available to you or to any agent you spawn. The three permitted outcomes are: wire
  it, build it out, or **stop and ask the user**. This covers the quiet forms too — stubbing the
  body, dropping the last call site, deleting the enum case / parameter / config key that kept it
  alive, `@deprecated`-ing it aside, or deleting the test that pinned its dormancy. If an agent's
  diff removes one of these, reject it and re-spawn. Full rule: `prompt_plan.md` §1.10.
  **Escalating is a completed step, not a failed one** — record it in the worklog and in §8 below
  under `Awaiting user decision:`, verbatim, with `file:line`, what calls it (or that nothing does),
  and the options. Then wait for the user; do not decide it yourself.
- **Never accept an annotation or an existence check as a test.** `@covers`, `@test`, a descriptive
  method name, `method_exists()`, `class_exists()`, `is_callable()` and shape assertions
  (`assertNotNull`, `assertIsArray`, `assertTrue(count(...) > 0)`) all pass on wrong or absent
  behaviour. A real test calls the thing and asserts the value, asserts exact counts, covers both
  polarities and the pathological input — and goes **red when the change is reverted**. Require the
  step agent to state the deletion experiment it ran and what it showed. Full rule:
  `prompt_plan.md` §1.11 and §16.2.
- Never accept an agent's claim of completion without test output you ran yourself.
- **Never read an empty or aborted agent response as a result.** It means the agent died. Recover it
  (`prompt_plan.md` §1.8) — do not accept it, do not fill in what it would have said, and do not
  merge its worktree because the tests happen to be green.
- Never commit before confirming `user.name` / `user.email` are `Joe Huss` /
  `detain@interserver.net` (§4). A wrong author is silent and cannot be fixed afterwards
  without rewriting history.
- Never `ln -s` a worktree's `vendor/`. Use `cp -al` and verify the PSR-4 root (§6). A symlinked
  `vendor/` silently runs every test against the main repo's `src/`.
- Never delete a worktree without first checking it for uncommitted changes and unmerged commits
  (`prompt_plan.md` §1.12).
- Never write a worklog number you did not measure.
- **ORCHESTRATION-RULE-2 — no agent may create a scratch git repository anywhere but its own
  scratchpad, and must verify `pwd` before any `git init` / `git commit`.** ADDED 2026-08-30 after a
  P3.S5 reviewer ran a throwaway-repo setup inside `/home/sites/sugarcraft` itself: it OVERWROTE the
  repo's identity config to `t <a@b.c>` and left a stray commit on **master**. Repaired (identity
  restored, master reset, junk file gone, every plan commit verified still authored `Joe Huss`), and
  nothing was ever pushed — but §7 already warns that a wrong author is *"silent and cannot be fixed
  afterwards without rewriting history"*, and this was caught ONLY because the step agent
  self-reported it. **Put this prohibition in every step brief, and re-check
  `git config user.name` / `user.email` after every step, not only before committing.**
- **ORCHESTRATION-RULE-3 — every agent gets its OWN scratchpad subdirectory, named after its step,
  and may never `rm -rf` a path it did not create.** ADDED 2026-08-31. The session scratchpad is ONE
  FLAT SHARED DIRECTORY that every agent writes into; it held ~180 files from concurrent agents when
  this was found. A P3.S5-fix-1 reviewer opened its sandbox with an unconditional
  `rm -rf "$SB"; cp -al <worktree> "$SB"` where `$SB` was `.../scratchpad/sb` — a name it picked
  without checking — and self-reported that the `sb/` it destroyed had an mtime PREDATING its own
  work, so it almost certainly deleted a concurrent agent's sandbox mid-experiment. Two agents also
  both wrote `.../scratchpad/Runtime.orig.php` and `RT.orig.php`, and neither could afterwards tell
  whose copy survived. **That second one is the dangerous shape**: an agent that backs up a worktree
  file to a SHARED name, mutates the worktree, then restores from that name can restore ANOTHER
  agent's version of the file — a silent cross-contamination of a step's source that no test would
  attribute. So: every brief must name a private subdirectory (`<scratchpad>/<STEP_ID>/`), every
  backup and sandbox goes inside it, `rm -rf` is permitted only within it, and generic names
  (`sb`, `base`, `count.php`, `*.orig.php`) at the scratchpad ROOT are forbidden. The rule held only
  because the reviewer volunteered the collision — nothing detects it.
- Use `/usr/bin/grep` for anything that must see the whole tree — the shell's `grep` is `ugrep` and
  its recursive scans honour `.gitignore`.

## 8. Where you are right now

```
Phase:            5 - PromptSection architecture, IN FLIGHT (3 of 6 merged). P5.S3 CLOSED at merge 97ced919e; upstream sync 71cab0fca + skip-roster fix de81f45e4 landed as USER-AUTHORIZED maintenance between steps. Phases 0-4 closed.
Next step:        P5.S4 (prompt_plan.md Phase 5). NOT YET STAFFED: no step-text extraction, no brief. Do in order: (1) read plan Phase 5 step text for S4, extract /home/sites/prompt-scratch/P5.S4/step-text.md; (2) author prompt_kit/briefs/P5.S4-step-brief.md with the figures addendum = NEW FLOOR below (10774/165986/2skip@de81f45e4 - every older figure crosses a different tree; goldens MAY move legitimately at S4-S6; single-system-merge from upstream may already satisfy parts of later steps - RE-CHECK plan text vs upstream state BEFORE briefing); (3) ONE setup+placement task(coder); (4) ONE lead via task(coder), lean prompt pointing at the brief FILE, sandbox /home/sites/prompt-step-P5.S4 + cp -al vendor + PSR-4 verify + worktree-local identity, scratch /home/sites/prompt-scratch/P5.S4/lead/. Standing spawn laws: lead-never-fixes; dedicated fix agents own fix-N/ subdirs; reviewers = task(coder) with STRICT read-only rules + own review-N/ subdir (delegate-route and read-only agents CANNOT run commands); empty/blank return = DEAD agent, ladder up to 5, probe worktree between attempts; cap 5 cycles; NO POLLING - notifications arrive only on the user's next message, tell the user to ping back.
Steps done:       33 of 63 MERGED (P5.S1 interface+assembler; P5.S2 snapshot migration; P5.S3 PromptFence + both vectors closed).
Phases done:      5 of 12 (P0-P4 CLOSED). Phase 5: 3 of 6.
Last commit:      resume/worklog bookkeeping (this file, hash-object bypass, no code) directly over de81f45e4. Re-derive: git -C /home/sites/sugarcraft log --oneline -1
Baseline:         Tests: 10351, Assertions: 160648, Skipped: 1  (from P0.S1, NEVER edited)
Latest suite:     NEW AUTHORITATIVE FLOOR at de81f45e4 (cwd checkout root, serial, stdin /dev/null, probe 0, prediction-first): Tests 10774 / Assertions 165986 / 0E / 0F / Skipped 2 / EXIT 0 / 06:59. Census 176/31786; roster 17/1105; testFiles 442; unaccounted 0; EnvRosterDrift 31/2894; GlobFigureDrift 61/21677; SymbolCitationDrift 7/3054; StderrEmitterCensus 94/5031; path gate 0 from repo root; goldens UNMOVED system 32ea749d84938811ac9331419cae7380 / agent ef0326dd38535aaa2f1d715919bff26e. ALL older step figures are superseded (pre-sync tree + symlinked vendor). BOX LAW UNCHANGED: CompactModelSummary + MouseModalGuard pair + 3 Cli stdin-pin tests are environmental (both arms observed) - base-control, never chase. NEW LAW: commit messages ZERO '<' chars (fixture input); residue guards region/line-scoped with presence-first asserts; final suite figure at FINAL tip only.
In-flight batch:  NONE (write at spawn, clear at batch close). P5.S3 CLOSED 97ced919e; P5.S4 not yet spawned.
Live worktrees:   ONLY /home/sites/sugarcraft (master = bookkeeping tip over de81f45e4). prompt/P5.S3 + /home/sites/prompt-step-P5.S3 removed post-merge (section 1.12 verified). crush-lane-{a,b,c} + the 9 repo-shared stash refs belong to the OTHER plan - NEVER touch. NOTE: local lib dirs are no longer symlinked into sugar-crush/vendor.
Blocked on:       nothing.
Awaiting user decision: NOTHING (F7/F8/F9 answered + scheduled 2026-09-04; dormant-code escalations remain the only thing that may re-enter).
Open follow-ups:  SCHEDULED F7 (Gemini tools shaper - NOTE: upstream Q-lane may have changed Sglang/Vertex routing; re-verify premises when staffed), F8 (workflow-path write-signal build-out), F9 (widen cache readout - upstream shipped CompleteResponse +22 and streamed-usage revival E-27/E-30: CHECK whether the widen-CompleteResponse travel seam is now partially/fully shipped BEFORE building), plus ALL previously carried: P5.S1 residuals, travel ledger (draft-echo over-window; countTokens tool_calls-blindness; Message::withContent home; Chat dead-var; rule-44 drift; usageInt DRY; transcriptSignature control; F6b citations; F2/N1-N5), NEW from P5.S3: RMBT:1148 bare-word immunity conditional on PromptFixture root having no .git - a census-style guard is the honest fix (later step); fence-less skill sections as a future escape vector; lead report section 8 six NITs (see worklog entry); review-3 NIT cosmetic stat line. PROCESS: lead died 3x blank on P5.S3 before the continuation pattern worked - for big steps spawn lean and let the worktree be the memory; probe between attempts.
Sequencing gate:  CLEARED 2026-09-04 (supervisor verbatim: "nothing else running go ahead and contineu"). UPDATE: the other plan's qwen/sglang lanes are now IN MASTER via 71cab0fca - their §5 rows (EngineBackend, Chat, ContextCompactor, Bash, AgentDefinition, tests/Support) are NOT touched by that window (measured in its diff stat); lanes remain live for FUTURE rounds - STOP-AND-ASK per §0 if a new lane round appears mid-Phase-5/6, re-check with supervisor before Phase 6.
```

**Phase 5 note (updated 2026-09-04).** P5.S1-S3 merged; upstream sync + roster fix landed the same day (worklog WL-A/WL-B). Before opening P5.S4: re-derive plan anchors against the POST-SYNC tree (upstream rewrote SglangProvider/Usage/CompleteResponse/ProviderFactory), re-check §5 with the supervisor if any lane round reappears, and read the S4/S5/S6 step texts.

---

## §R. How to rewrite this file (read this before every rewrite)

**Rewrite this file after every single step, and after every phase close.** Not append — **replace**.
This file always describes the *current* state and the *next* action. History belongs in
`prompt_worklog.md`; if you find yourself adding a "previously…" section here, that content belongs
there instead.

### What must survive every rewrite, unchanged in substance

Sections **0, 1, 2, 6, 6a, 7** and this section **§R**. They are the operating instructions and they do not
change as the plan progresses. Copy them forward verbatim. If you find an error in them, fix it and
say so in the worklog entry for the step that fixed it.

### What you replace every time

- **The banner** at the top: change `Current state: NOT STARTED` to a one-line statement of where the
  plan is (e.g. `Current state: Phase 4, step P4.S3 in flight; phases 0–3 closed`).
- **§3** — once Phase 1 has landed, replace the lead-finding explanation with a short "what has been
  built so far" summary: the three or four things a fresh agent needs to know about the *current*
  shape of the code, not the original defect. Keep it under fifteen lines. When it grows past that,
  you are writing history; move it to the worklog.
- **§4 (Your first actions)** — becomes "how to resume": confirm the tree is clean, confirm the last
  step in `prompt_worklog.md` matches the last commit in `git log`, and if it does not, **reconstruct
  the missing entry before doing anything else** (`prompt_plan.md` §3.3).
- **§5 (the sequencing gate)** — once the gate has been checked and cleared, replace it with a
  one-line record of the decision and the date, plus any collision that is still live. If a
  collision is still live, keep the row that describes it.
- **§8 (Where you are right now)** — always rewritten. Every field, every time.

### The §8 block's required fields

```
Phase:            <current phase id and title, or "between phases">
Next step:        <STEP_ID> — <one-line goal>
Steps done:       <N> of 62
Phases done:      <N> of 12
Last commit:      <sha> — <subject line>
Baseline:         Tests: <N>, Assertions: <N>, Skipped: <N>  (from P0.S1, never edited)
Latest suite:     Tests: <N>, Assertions: <N>, Skipped: <N>  (from your last verification run)
Retro-review track: <the retrospective review agents currently out, their scopes, and where their
                  findings are queued — or "none". This track runs ALONGSIDE the plan and never
                  gates it; findings become fix steps scheduled between plan steps.>
In-flight batch:  <the batch id, its steps, their worktree paths, and the declared MERGE ORDER —
                  or "none". Write it the moment you spawn a batch, not when you start merging.>
Live worktrees:   <paths, or "none". Each one: the step it belongs to, and whether that step is
                  in flight, parked (§1.2 action 6), or stale (§1.12).>
Blocked on:       <nothing | STEP_ID and the standing findings, verbatim>
Awaiting user decision: <nothing | STEP_ID, the file:line, and the question, verbatim — the
                  dormant-code escalations from prompt_plan.md §1.10 that no agent may resolve>
Open follow-ups:  <the follow-ups recorded in worklog entries that are not yet scheduled>
Sequencing gate:  <CHECKED YYYY-MM-DD — decision | UNCHECKED>
```

`Baseline` is written once, at P0.S1, and never edited afterwards. It is the number every later delta
is measured against, and a moving baseline makes every delta meaningless.

### Rules for the rewrite

- **`In-flight batch` is the field that survives a session loss.** Every other field describes work
  that is already *finished* and therefore already recoverable from `git log` and the worklog. Five
  agents running in five worktrees with a merge order you decided in your head is recoverable from
  nothing. Write it at spawn time. Clear it at batch close, not before.
- **A fresh agent handed only this file must be able to continue.** After writing it, reread it as if
  you had never seen this repository. If a sentence assumes something you learned in conversation,
  rewrite the sentence.
- **Never write a "Next step" you have not confirmed against `prompt_plan.md`.** Look it up; do not
  recall it.
- **Never carry a stale number forward.** If you did not run the suite this step, say
  `Latest suite: not re-run this step (last measured at <STEP_ID>)` rather than repeating an old
  figure as if it were current.
- **If a step is blocked, the blocking findings go in §8 verbatim**, not summarised. The next agent
  needs the actual text to act on it.
- **A dormant-code escalation is never resolved by a rewrite.** It leaves `Awaiting user decision:`
  only when the user has answered, and the answer goes into the worklog entry for the step that acts
  on it. Carrying it forward unanswered, every rewrite, is correct.
- **If you are about to be cut off** (context limit, session limit), rewriting this file is the last
  thing you do and the highest-value thing you can do. Do it before anything else you were planning
  to finish.
