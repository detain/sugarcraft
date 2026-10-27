# C-SES-9.d — RELEASE WORKFLOW INTERPOLATION HARDENING — CLOSED

Commit: 06a599a96 on cc/C-SES-9.d (base 86f43fae2), worktree /home/sites/cc-cmp-C-SES-9.d. Author+committer detain@interserver.net verified by separate greps; zero Co-Authored trailers; tree clean; never pushed.

## What landed
- `.github/workflows/release-phar.yml` — Resolve tag / Attach HEAD / Build / Publish: `github.event_name`, `github.event.inputs.tag`, `steps.tag.outputs.tag` all moved to per-step `env:` (EVENT/TAG); scripts read `"$EVENT"`/`"$TAG"`.
- `.github/workflows/release-app-phar.yml` — scope / attach / install / build / sign / checksum / smoke / compat / publish: same treatment via env EVENT/TAG/APP (sign step's pre-existing APP binding reused; stamp-assert step's env bindings were already the safe shape).
- `.github/workflows/release-candy-top-phar.yml` — tag / attach / build / publish: same (brief line-list was stale; build-step interpolations fixed too — guard demanded all).
- NEW `tools/tests/WorkflowRunInterpolationTest.php` — 3T/134A. Text-region `run:`-body parser (ActionYmlInstallTest/CandyCodeImageCacheBustTest doctrine — NO symfony/yaml: tools-guards runs the phpunit PHAR with no autoload, a yaml dep would be a skip-path). Headline arm fails on `${{ github.event.inputs.` or `${{ steps.` inside any run body of the three files (i-modifier: contexts resolve case-insensitively). Positive arms pin the EVENT/TAG/APP env bindings and the `"$TAG"`/`"$APP"` read-backs, so deletion-without-relocation goes red. Forbidden spellings assembled as escaped regex fragments (plan §1.9 literal hazard).

## Gates (all plain-pipe, env -u LINES -u COLUMNS)
- RED-BEFORE: base ymls restored → new guard 2F (headline + binding arms); edits restored → green.
- Full tools battery: `php candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration tools/tests` → **OK (489 tests, 2578 assertions)** incl new file.
- candy-code five-guard filter: 133T, failures = ONLY the pre-existing ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 26,093 — base-inherited campaign drift, gate re-pin duty; zero candy-code files touched here).
- YAML validity: ruby psych parses all three; semantic scan (steps/run-body classification) CLEAN, bodies 12/10/11.

## Notes
- actionlint not installed on this box — risk note says verify by review otherwise; done (behavior equivalence: single-quoted interpolated literals → double-quoted env expansions, one argv token each; `env:`/`if:` interpolations untouched — safe evaluator-side forms).
- tools-guards job globs `tools/tests/` — new file auto-included; NO candy-code durations row, NO suite-figure change (per brief).
