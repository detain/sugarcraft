### C-AGT-2.c — Composite action installs with root semantics + self-test
- item: C-AGT-2 (sources: GIT-2-P1, GIT-2-P2)
- kind: fix · MAJOR · effort M
- approach: Root cause: `action.yml:103` runs `composer global require "sugarcraft/candy-code:…"`. Stability flags are root-only, the global root is `stable`, and Packagist carries no stable sibling, so the install cannot resolve; no workflow has ever run it. The same line is the README's user install (`README.md:149`) and `docs/CI.md:164`. Fix:
  - install with root semantics: `composer create-project --no-dev --prefer-dist "sugarcraft/candy-code:${CANDY_CODE_CONSTRAINT}" "$RUNNER_TEMP/candy-code"`, and add its `bin/` to `$GITHUB_PATH`;
  - fix the README and CI.md global-install lines the same way, or with `composer global config minimum-stability dev && … prefer-stable true` first;
  - document the only real pin, `dev-master#<split-sha>`, and that it pins candy-code only, not its siblings;
  - add a self-test workflow (`uses: ./candy-code`, echo provider, `permission-mode: plan`, path-filtered on `candy-code/action.yml` and `candy-code/docs/CI.md`) asserting `exit-code == 0` and a non-empty `result`;
  - add a `tools/tests` guard that `action.yml` never runs `composer global require` and never interpolates `${{ inputs.* }}` inside a `run:` body.
  - Update CI.md's "no repo test surface by design" paragraph.
- files.create: `.github/workflows/candy-code-action-selftest.yml`, `tools/tests/ActionYmlInstallTest.php`
- files.modify: `candy-code/action.yml` (install step, `candy-code-version` description), `candy-code/docs/CI.md` (install description :26, input table :40, "without the action" :164, "Linting this action" :173-178), `candy-code/README.md` ("Install as a user" :146-152)
- tests: create `tools/tests/ActionYmlInstallTest.php` (runs in the `tools-guards` job, not the candy-code suite, so no durations row); the self-test workflow is the behavioural test
- docs/guards: `docs/CI.md`, `README.md` install block. `ReadmeRosterDriftTest` is unaffected (not a roster).
- serialized: [workflows] [docs:CI.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: the self-test installs from Packagist dev-master (the split repo), not the PR's tree, so it proves the install leg and the envelope, not unmerged PHP.

---

## C-AGT-3 — Preset memory & MCP scope

