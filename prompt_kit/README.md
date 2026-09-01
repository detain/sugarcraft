# prompt_kit/ — the durable half of the prompt-architecture plan's working set

Everything the plan in `prompt_plan.md` needs that is **not** a plan document: the reusable
instruments, the agent briefs, the review findings, and the accumulated project context. It exists
because all of it used to live in two places that a new session cannot reach.

## Why this directory exists

The plan is run by an orchestrator that spawns agents, and both halves of that arrangement kept
state outside the repository:

1. **A session scratchpad under `/tmp`.** Briefs, findings files, probe scripts and measurement
   output all lived there, and `prompt_resume.md` and `prompt_plan.md` *cited those paths* — so a
   resuming session was pointed at files that `/tmp` had already reclaimed. The 824-line phase
   review in `findings/` is the clearest case: it was the entire justification for a fix step, and
   it existed only in `/tmp`.
2. **Claude Code's per-project memory store** (`~/.claude/projects/<slug>/memory/`), which held 45
   pieces of hard-won project context. That store is specific to one harness on one machine.
   OpenCode cannot read it, and nothing in the repo referenced it.

Both are now mapped into files that travel with the checkout. **Prefer these paths over a
scratchpad path in anything you write** — a brief that cites `/tmp` is a brief that stops working.

## Contents

### `CONTEXT.md`
The memory entries, bodies verbatim, grouped by kind with an index. Read the header before the
entries: they record what was true **when written**, and several are superseded by their own later
text. Its generator is fail-closed for a reason the header explains.

### `tools/`
Small instruments, each of which earned its place by finding something.

| tool | what it does | why it exists |
|---|---|---|
| `cmp.py` | Diffs two PHPUnit JUnit XMLs and reports the **assertion count per test class**. `python3 prompt_kit/tools/cmp.py <a.xml> <b.xml>` | The highest-leverage tool this plan produced. When a suite total moves and the step's own files do not account for it, this names the mover in **one pass**. It replaced an episode in which twenty-five tree-wide guards were measured one at a time and every one came back identical. Generate the inputs with `phpunit --log-junit`. |
| `treewide-roster.php` | Derives which tests walk `src/` or `tests/` **wholesale**. **SUPERSEDED 2026-09-01** by `sugar-crush/tests/TreeWideGuardRosterTest.php`, which does the same derivation as a shipped, mutation-proven test (roster 67, `unaccounted 0`) instead of a scratch script. Keep this one for ad-hoc use; trust the test. | The plan requires every step to run the "census set" of tree-wide guards, and that set was a hand-maintained list. It was wrong three times in one batch. Channel A (consumers of the shared walker trait) is sound; channel B (tests rolling their own root-anchored walk) over-classifies and is a superset, not a roster. **Read its own output about its precision before trusting it.** |
| `scan.php` | Drives the shipped write-primitive scanner by reflection, so it can be run against known-answer input | The plan's review brief requires running any scanner against known-answer input *before* grading what it reports — "a scanner that answers the same way for every input reads as working". This scanner has been defeated **twelve** times, every one on a fully green suite. Established controls: `Write.php => ["file_put_contents","mkdir"]`, `Edit.php => ["file_put_contents"]`, `Read.php => []`. |
| `tokencensus.php` | Counts and hashes a PHP file's **executable** tokens, dropping whitespace and comments | Proves a "doc-block only" claim about a source file. Also carries its own warning: a census that strips `T_DOC_COMMENT` is blind to the doc-block's *content* by construction, and a phase review found a +435-line doc-block change that was executable-identical and whose prose was wrong. |

### `briefs/`
Agent briefs. These are long on purpose: each is the accumulated set of traps a previous agent fell
into, and shortening one re-opens whichever trap the deleted paragraph closed.

- **`phase-review-brief.md`** — the phase close reviewer's brief (`prompt_plan.md` §1.7): §1.4's
  nineteen checks, the phase-specific additions, the census set *and the fact that it is known
  incomplete*, the per-class JUnit method, the four rules that make a suite figure mean anything,
  and a do-not-re-report list so a re-found escalation cannot bury a new finding. **Reuse this for
  the next phase close**, updating the shas, the change-set and the claims-to-attack.
- **`P4.S1-step-brief.md`** and **`P4.S1-step-text.md`** — ready to spawn. The step text carries six
  measured hazards; the brief carries the review-loop mechanics every step agent needs.
- **`recovery-continuation-brief.md`** — the §1.8 rung-3 brief that recovered `P3.audit-fix-2` after
  its fix agent died without reporting. **The one that worked**, on attempt 1 of 5. Its header names
  the four properties to keep when you build the next one; the most important is that it opens by
  telling the agent NOT to start over, because a continuation agent's default instinct is to redo
  work it cannot see.

### `findings/`
- **`phase3-close-review-cycle-1.md`** — the Phase 3 close review, 824 lines, 11 findings, 4 HIGH.
  Kept whole rather than summarised: six of its findings were independently re-verified before any
  fix was commissioned, and its measurement commands are reusable. It also contains one finding the
  reviewer **withdrew by its own measurement**, recorded deliberately, because a prescription is a
  hypothesis until measured.
- **`P3.audit-fix-2-final-report.md`** — the continuation agent's report on the code half of that
  review, A1-A7. Kept because the FIRST agent on that step died without producing one, and that
  absence cost a whole recovery cycle: a report that lives only in a harness transcript is one
  `/clear` away from being the same absence again. Its §6 is the most reusable part — the agent
  contradicting its own brief on two points, with the measurement for each.

## Conventions for adding to this directory

- **A tool goes in `tools/` once it has found something twice.** A script that ran once belongs in a
  scratchpad; this directory is for instruments worth reaching for again.
- **Never edit a tool in place from an agent brief.** Tell the agent to copy it into its own
  scratchpad first — several agents run concurrently and share one flat scratchpad, and one of them
  destroying another's sandbox is a thing that has already happened here.
- **Findings files are append-only history**, like `prompt_worklog.md`. Correct one with a dated
  `CORRECTED:` note rather than a rewrite; a quietly rewritten findings file is indistinguishable
  from a fabricated one.
- **`CONTEXT.md` is generated, and the generator now ships beside it** —
  `tools/context-gen.py`, with the prose header split out as `tools/context-header.md`. Regenerate
  rather than hand-editing:
  ```sh
  python3 prompt_kit/tools/context-gen.py            # rewrite CONTEXT.md from the memory store
  python3 prompt_kit/tools/context-gen.py --check    # exit 1 if it is out of date; writes nothing
  ```
  It defaults to `~/.claude/projects/-home-sites-sugarcraft/memory`; `--memory-dir` points it
  elsewhere. Keep its assertions — they are what stopped a silently-empty file
  from shipping.
