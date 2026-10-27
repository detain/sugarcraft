# C-AGT-2.c — DONE, 1 commit

Tip: 04def8555 on cc/C-AGT-2.c (base cf12e96fb; not pushed). Author+committer detain@interserver.net verified by separate %ae/%ce greps; zero Co-Authored trailers; tree clean.

## Landed
- action.yml: install leg `composer global require` → `composer create-project --no-dev --prefer-dist --no-progress --no-scripts --no-interaction "sugarcraft/candy-code:${CANDY_CODE_CONSTRAINT}" "$RUNNER_TEMP/candy-code"` + `$RUNNER_TEMP/candy-code/bin` onto `$GITHUB_PATH`; `candy-code-version` description now names the create-project semantics and the only real pin (`dev-master#<split-sha>`, candy-code-only, siblings float).
- NEW tools/tests/ActionYmlInstallTest.php: run-body parser (handles `run: |`/`>`/inline, `- run:`); arms: extraction floor (≥3), no-global-require, create-project positive anchor (constraint-via-env, RUNNER_TEMP, GITHUB_PATH), no `${{ inputs.* }}` inside any run body. Fails pre-fix (2 arms), green post-fix.
- NEW .github/workflows/candy-code-action-selftest.yml: path-filtered (action.yml, docs/CI.md) push/PR + dispatch; `uses: ./candy-code`, no provider env → offline EchoProvider, plan mode; asserts exit-code==0 && non-empty result; outputs cross via env only; full-SHA action pins; permissions contents:read.
- docs/CI.md: install description :26 region, input-table row, "without the action" two-command block, "Linting this action" rewritten (guard + self-test now ARE the repo test surface; old "no repo test surface by design" retired with reason).
- README.md "Install as a user": create-project + PATH form; global-require refusal explained with the stability-flag root-cause; global-config remedy marked side-effecting.

## Gates (plain pipe, -u LINES -u COLUMNS)
- ActionYmlInstallTest: OK 4T/20A; pre-fix proof 2F. Full tools/tests dir: OK 478T/2378A (baseline 474T + my 4).
- candy-code --filter 'ReadmeRosterDrift': OK 10T/78A, rc=0 (2 PHPUnit Deprecations proven pre-existing: identical count at base README).
- five-guard: 133T, ONLY staleness pair (pinned 25537 vs live 25608, foreign era — weld/gate duty).
- BootstrapLaunchFormatConstants: green in combined roster filter (CI.md stays PAGE_QUOTE_IS_PROSE).
- php -l new guard; yaml.safe_load on workflow + action.yml: ok.

## Notes
- Deviation: resume-message path `candy-code/tools/tests/...` treated as typo; BRIEF files.create `tools/tests/ActionYmlInstallTest.php` followed (tools-guards job, outside candy-code suite → NO durations.tsv row, NO suite-figure touch; gate adds it to the tools runner only — it self-discovers in the dir glob, ci.yml needs no edit).
- action.yml run-body comments avoid the verbatim forbidden phrase (guard scans bodies as text; noted in the guard).

## Fixes (round 2)
- NIT fixed: interpolation arm pattern hoisted to const INTERPOLATES_INPUTS with the i
  modifier (GitHub resolves context names case-insensitively); other arms stay
  case-sensitive by design (shell text executes case-sensitively) — rationale in docblock.
- New pin testTheInterpolationPatternMatchesEveryCaseSpelling (5 forgeries + 2 negative
  controls); mutation dropping i -> exactly 1F. Gates: ActionYmlInstall 5T/27A; tools/tests
  479T/2385A OK (was 478/2378); php -l clean; action.yml + ci.yml parse OK. Tip fdd5c0f6c.
