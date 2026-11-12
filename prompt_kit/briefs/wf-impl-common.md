# wf-impl — shared rules for every agent implementing candy-code/workflow-update.md

Source of truth for WHAT to build: `candy-code/workflow-update.md` (the gap report; finding IDs
P0-1 … P2-5). The grammar/semantics the finished system should expose are illustrated in
`candy-code/workflow-authoring-skill.md` — match its key names and semantics unless the report says
otherwise. Read your finding(s) in the report in full before starting.

## Working-tree rules (several agents share ONE checkout on `master`, concurrently)

- Work directly on `master` in `/home/sites/sugarcraft`. **No branches, no worktrees, no PRs, no
  push.** Do not `git stash`, `git reset`, `git checkout -- <file>`, `git clean`, or rebase.
- **Only edit the files your brief says you own.** Other agents are editing other files right now.
  If you truly need a change in a file you don't own, don't make it — write it under
  "Cross-track requests" in your notes file (below) and work around it.
- Commit ONLY your own paths: `git add <your files…> && git commit -m "<msg>" -- <your files…>`.
  Never `git add -A` / `git add .` / `git commit -a`. If git reports `index.lock` exists, wait a
  few seconds and retry (another agent is committing). Commit in small coherent steps as features
  go green. Commit message: `candy-code: <summary> (wf-impl <finding ids>)`, ending with a blank
  line then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Do NOT run `caliber refresh` (the orchestrator runs it once at the end — concurrent runs would
  rewrite CLAUDE.md under each other).
- Bash CWD does not persist — use absolute paths or `cd /home/sites/sugarcraft/candy-code && …`.

## Testing rules

- Run ONLY targeted tests: `cd /home/sites/sugarcraft/candy-code && vendor/bin/phpunit --no-coverage tests/Workflows/<File>Test.php` (or `--filter`). They run in well under a second.
- Do NOT run the full suite. Do NOT refresh `candy-code/tests/Config/Support/suite-figure.json`
  or `scripts/parallel-tests-durations.tsv`, and ignore suite-figure / test-count / doc-roster drift
  tests — those are settled at the very end. (Do still keep pre-existing behaviour tests for code
  you touch green.)
- Every new behaviour gets tests (new test files are fine). Keep defaults byte-identical where the
  report says "absent means untouched".
- A test failure in a file you don't own that is caused by another agent's in-progress work: note
  it, don't fix it.
- `php -l` every PHP file you edit.

## Code rules (candy-code)

- `declare(strict_types=1);`, PSR-12, `final` classes, readonly DTOs, comment WHY not WHAT.
- Fail-closed loader law: every new YAML key is added to the roster constants
  (`WorkflowRegistry::YAML_TASK_KEYS` / `YAML_STAGE_KEYS` or the relevant sub-roster), wrong
  types/shapes are refused with `WorkflowLoadException` naming the offender — never coerced.
- Every new grammar feature gets a `WorkflowLinter` mirror where a static check is possible.
- PHP builder parity: if YAML gains a key, `TaskBuilder`/`WorkflowBuilder` gain the method.
- Diagnostics stay literal English (workflow refusal law).

## Notes file (mandatory)

Write `prompt_kit/findings/wf-impl-<your-track>.md` (you own it) containing:
1. What you implemented, per finding ID: keys, defaults, semantics, error messages, files touched,
   tests added (file names) — written so a docs agent can turn it into `docs/WORKFLOWS.md`
   sections without reading your diff.
2. Anything from your brief you did NOT do and why.
3. Cross-track requests (changes needed in files you don't own).
4. Commit hashes.

Your final message: ≤15 lines — status, commits, notes-file path, anything blocking.
