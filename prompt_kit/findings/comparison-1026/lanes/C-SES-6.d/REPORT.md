# C-SES-6.d — slim/reproducible phar release assets (workflow-only) — CLOSED

Worktree /home/sites/cc-cmp-C-SES-6.d, branch cc/C-SES-6.d, base aa38e06b1.
One commit, one file: `.github/workflows/release-phar.yml` (+83/-8). NEVER pushed.

## What shipped (measured against the working tree)
- **gz twin**: new step "Build the gz twin (slim transfer asset)" runs the same
  tree through `build-phar.php --out=dist/candy-code-gz.phar --gz` (the script's
  --gz replaces --out with the compressed bytes, so the asset lands at exactly
  that name). Same `SOURCE_DATE_EPOCH="$(git log -1 --format=%cT "$TAG")"` line
  as the plain build — the C-SES-6.b stamping machinery governs BOTH artifacts
  of one run (each run: body is a fresh shell, so the export is repeated, not
  shared).
- **manifest**: plain Build step gains `--manifest-out=dist/candy-code.phar.packages.json`;
  Checksum step fail-closes on `test -s` before publishing.
- **formula + installer**: Checksum step renders `candy-code.rb` via
  `tools/gen-homebrew-formula.php --version="$TAG" --sha256="$phar_sha"` where
  `$phar_sha` is `cut` from the plain sidecar's first field (formula and sidecar
  are read from one file, cannot disagree), and stages `candy-code/tools/install.sh`
  verbatim into dist/.
- **signing**: the Sign step loops `["candy-code.phar","candy-code-gz.phar"]`,
  emitting per-artifact `minisig` + `sha256.sig` (sidecar-body leg) trios plus
  both sidecar-of-sidecar sums. Keyless early-exit is shared, so all six skip
  together; the publish gate stays `[ -f dist/candy-code.phar.minisig ]`.
- **publish**: asset list = plain pair, gz pair, packages.json, candy-code.rb,
  install.sh, plus 6 signature files when signed (7 unsigned / 13 signed).
  Release notes name the plain asset as the self-update target and carry the
  plan's risk sentence: **the gz twin requires ext-zlib at RUNTIME to open**.
- **OWNER RULING §6.3 honored**: Homebrew tap push DECLINED — formula ships as
  release asset ONLY. No push leg, no new secret anywhere in the diff.

## Plain-asset non-disturbance (verified, not assumed)
`src/Cli/SelfUpdate.php` selects by EXACT asset names (`PHAR_ASSET`/
`SIDECAR_ASSET` consts :178-206, `=== $asset['name']` matching :824-830), so
`candy-code-gz.phar`, `candy-code.phar.packages.json`, `candy-code.rb` and
`install.sh` ride beside the plain leg untouched.

## Guards (C-SES-9.d + neighbors)
- `tools/tests/WorkflowRunInterpolationTest.php` (the C-SES-9.d guard): parses
  release-phar.yml run bodies, floors release-phar.yml at 10 bodies — the file
  now yields 13. New bodies bind TAG through step `env:` and expand `"$TAG"`
  quoted; no `${{ steps.* }}` / `${{ github.event.inputs.* }}` in any run text.
- Full `tools/tests/` suite in-worktree: **OK (499 tests, 2639 assertions)**
  (`candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
  --no-configuration tools/tests/`, plain pipe, env -u LINES -u COLUMNS).
  Includes ActionYmlInstallTest + CandyCodeImageCacheBustTest (workflow-adjacent).
- Mutation proof (new bodies are really scanned): planted
  `${{ steps.tag.outputs.tag }}` into the new gz-build body → guard filter went
  red EXACTLY 1F (`Tests: 3, Failures: 1`, headline arm, WorkflowRunInterpolationTest:200);
  restored with cp from /tmp backup, md5 verified identical to pre-mutation tip.
- `bash -n` over all 13 extracted run bodies (python3+yaml walk): 0 failures.

## YAML validation used
- `python3 -c 'yaml.safe_load(...)'` → OK.
- PHP `yaml_parse_file()` (ext-yaml present on this box) → OK.
- `candy-code/vendor/bin/yaml-lint` exists but cannot run standalone here
  (symfony/console absent from the linked candy-code vendor) — disclosed, the
  two readers above cover it.

## Untouched (scope proof)
No candy-code `src/` or `tests/` edits; no docs rows; no new php files →
**no DUR** (no durations.tsv row owed, no suite-figure change — the heavy-arm
PharCompatTest references the workflow only in prose, which stays true).
suite-figure.json / durations.tsv / dist/ all untouched (git status: 1 file).
