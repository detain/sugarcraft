# crush_report roadmap — wave integrator brief

You integrate one finished wave of the sugar-crush roadmap execution plan
(`prompt_kit/findings/crush-report/17-execution-plan.md` = crush_report.md Appendix R, §1.5).
Your prompt lists the wave, the group branches to integrate (in cherry-pick order), each group's
final report summary, and each group's owned files/regions (the wave table row).

## Procedure
1. `git -C /home/sites/sugarcraft fetch -q origin`. Create `/home/sites/sugarcraft-wt/<wave>-int` detached at `origin/master`
   (`git -C /home/sites/sugarcraft worktree add --detach /home/sites/sugarcraft-wt/<wave>-int origin/master`), then copy vendors:
   `for v in vendor sugar-crush/vendor sugar-mcp/vendor candy-core/vendor; do mkdir -p <int>/$(dirname $v); cp -a /home/sites/sugarcraft/$v <int>/$v; done`.
   Scratch dir `/home/sites/sugarcraft-wt/.scratch/<wave>-int` as `TMPDIR`.
2. **Scope guard** per group: `git -C /home/sites/sugarcraft diff --name-only origin/master...fix/<wave>-<g>` must be within the group's owned files + its steps' new files/tests + named doc sections + `scripts/parallel-tests-durations.tsv` + generated doc blocks. For hotspot files check `git diff -U0` hunks sit inside the group's named methods/regions. Use judgement: a small necessary out-of-row edit that cannot conflict with another group of this wave is acceptable — note it. A violation that collides with another group's region: skip that group (report it for rescheduling).
3. Cherry-pick serially in the given order: `git cherry-pick origin/master..fix/<wave>-<g>`. Conflicts in the durations TSV → take the union (keep format/sort). Conflicts in generated blocks → re-run the generator. Trivial textual conflicts in shared docs (different sections, adjacent lines) → resolve by keeping both sides. Any real semantic conflict → `git cherry-pick --abort`, reset to before that group, report it for rescheduling.
4. Re-measure derived figures and fix them in a follow-up commit `sugar-crush: <wave> integration — re-measure derived figures`: spelled counts and census figures (`DocFigureProseDriftTest`, `*CensusTest`, `TreeWideGuardRosterTest`), `GlobFigureDriftTest`, `SymbolCitationDriftTest`, and re-run any existing generators (`tools/gen-settings-doc.php --write`, `tools/gen-command-docs.php --write`, tool-roster generator) once they exist.
5. Tests (no full suite): the union of every group's new/changed test files run concurrently (`xargs -P12`), plus all of `tests/Config` and `tests/Commands` (each as its own phpunit run in parallel shards by file), plus the tools gates:
   `php tools/check-path-repos.php --no-lib-path-repos`, `php tools/check-child-lifetimes.php`, `php tools/check-one-type-per-file.php`, `candy-core/vendor/bin/phpunit --no-configuration tools/tests/`; and `sugar-mcp` targeted tests if sugar-mcp changed.
   Unexplained red → run `scripts/parallel-tests.sh 8 --durations scripts/parallel-tests-durations.tsv` (sharded) to localise, then fix in an integration commit if small and clearly caused by this wave's interplay; otherwise revert the offending group's commits and report it. Pre-existing reds (also red on origin/master) are reported, not fixed.
6. **Report edits** (one commit `docs: crush_report — <wave> landed`): in `prompt_kit/findings/crush-report/17-execution-plan.md` delete each landed step's row from §4 (the group row, or the landed step IDs from a partially landed row) and its entry in §5; in `99-synthesis.md` delete each fully landed item row/bullet in Part III / V / VI (leave partially landed items with the remaining part only). Delete a design section in 13/14/16 only once every step citing it has landed. Never add "fixed"/history notes. Then run `bash prompt_kit/tools/assemble-crush-report.sh` and commit `crush_report.md` too.
7. Commits authored by the configured Joe Huss <detain@interserver.net>, each ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Skip Caliber (unstage Caliber-managed files if a hook stages them). Never `git stash`, never `pkill -f`.
8. `git -C <int> push origin HEAD:master` (if rejected because master moved, `git fetch` + `git rebase origin/master` and retest the doc gates, then push). Then `git -C /home/sites/sugarcraft pull --ff-only`. Then remove the wave: `bash /home/sites/sugarcraft/prompt_kit/tools/crush-wave.sh rm <wave> <groups…>` and `git -C /home/sites/sugarcraft worktree remove --force /home/sites/sugarcraft-wt/<wave>-int`.

## Final report (≤ 50 lines)
```
WAVE <wave> integrated → master <sha>
landed steps: …
skipped/rescheduled (step, reason, needed files/regions): …
HANDOFF items carried from groups: …
tests run → results; pre-existing reds: …
figures re-measured: …
```
