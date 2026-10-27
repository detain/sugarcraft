# C-SES-5.d — GHCR image cache-bust + BUILD-REF — LANDED

Commit: `d4c136c40` on `cc/C-SES-5.d` (base `fd4f36875`), author/committer
`detain@interserver.net` (separate %ae/%ce greps = 1 each), zero
Co-Authored-By. Never pushed.

## Files (+/−)

| file | + | − |
|---|---|---|
| `candy-code/Dockerfile` | 33 | 5 |
| `.github/workflows/candy-code-image.yml` | 61 | 16 |
| `candy-code/docs/DOCKER.md` | 30 | 4 |
| `candy-code/docs/SERVER.md` | 6 | 5 |
| `tools/tests/CandyCodeImageCacheBustTest.php` (NEW) | 366 | 0 |

## Shape landed

- **Dockerfile**: top-level `ARG CANDY_CODE_REF=local` declared immediately
  before the create-project RUN, which echoes the value as its first command —
  the instruction text turns with every CI run, defeating the constant-layer
  freeze under `cache-from: type=gha`. Same RUN then records the resolved
  reference into `/opt/candy-code/BUILD-REF` via
  `composer show <pkg> --format=json | php -r ...` — fail-closed: an
  unparseable composer JSON `exit(1)`s the build. Default `local` keeps the
  documented plain `docker build` working without `--build-arg`.
- **Workflow**: publishing trigger moved from `push:` (which raced the split
  sync) to `workflow_run` after `Sync to sugarcraft org` completes, gated on
  `conclusion == 'success' && head_branch == 'master' && event == 'push'`;
  `release` arm admits only `candy-code/v*` tags (`startsWith` on
  `github.event.release.tag_name`); metadata gains
  `type=match,pattern=candy-code/v(.*),group=1` (versioned tag on release) and
  the `org.opencontainers.image.revision` label (head_sha on workflow_run);
  build-args `CANDY_CODE_REF=${{ github.sha }}-${{ github.run_id }}`; new
  Summary step cats BUILD-REF out of the loaded image (`load: true`) into
  `GITHUB_STEP_SUMMARY`. `pull_request` proof arm survives with
  `push: ${{ github.event_name != 'pull_request' }}`.
- **Docs**: DOCKER.md publishing paragraph flipped to workflow_run-after-sync
  prose, new cache-bust/BUILD-REF section with the
  `docker run --entrypoint cat … /opt/candy-code/BUILD-REF` inspection
  snippet, Building-locally ARG note appended; SERVER.md container paragraph
  truth-flipped (first-green-run history 39525070215 kept).
- **Guard**: `CandyCodeImageCacheBustTest` — 7 tests / 49 assertions,
  region-anchored fail-closed scanners (create-project RUN, `on:` block,
  summary run-body) plus a Symfony/Yaml parse of `jobs.build.if` (same
  doctrine as ActionYmlInstallTest/PinnedVersions next door; the `>-` fold
  joins with single spaces and swallows trailing `(`, so a hand fold could
  not read the gate honestly). Pre-fix red: 6/7 failures against base
  (7th pins the surviving PR-arm property — legitimately green pre/post).

## Deviations (disclosed)

1. **BUILD-REF probes `sugarcraft/candy-code-web`, not the app package.**
   `composer show sugarcraft/candy-code` inside its own create-project root
   answers "Package not found" (measured; a create-project install never
   registers the root package under its name, and the app's own composer.json
   IS the root). The web package is installed by the same RUN's
   `composer require`, resolves from the same split stream, and carries the
   identical dist reference — the design block's lag-signal intent is fully
   preserved. Dockerfile comment block states this.
2. **jobIf reads via symfony/yaml** instead of a hand fold (see above); the
   class_exists guard skips (not fails) if the dep is absent — it is present
   in tools/tests' runtime via candy-code/vendor (the gate shape CI uses).

## Gates

- `php candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
  --no-configuration tools/tests` → **OK (486 tests, 2436 assertions)** incl.
  the new file; no durations.tsv row (tools-guards glob run), no
  suite-figure change (no candy-code test minted).
- candy-code filter
  `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift|BootstrapLaunchFormatConstants`
  → 151 tests, **only the pre-existing figure-staleness pair red** (25537
  pinned vs 25666 live — foreign weld `fd4f36875` postdates the pin; closeout
  re-pin duty, untouched here), all doc/launcher/glob guards green.
- Workflow YAML parses (`python3 -c yaml.safe_load`) and php -l clean; Dockerfile
  grep: exactly one ARG CANDY_CODE_REF line.
- New-file RED-before-fix proven on base; after-shape GREEN above.

## Seams for closeout (not touched here)

- Figure re-pin (README + suite-figure.json) — the staleness pair above;
  this step's diff never enters candy-code's enumeration.
- The Summary step reads `:latest` via `docker run` — harmless on PR runs
  because `load: true` puts the freshly built image under the tag locally;
  first-ever run on a cold cache would print the BUILD-REF-unreadable
  fallback rather than fail the publish.

## Round 2 (fixer 1ff716b92 on cc/C-SES-5.d)

- MAJOR-1 (CI-shape disclosure — the builder's round-1 run was yaml-available):
  locally the gate `php candy-code/vendor/bin/phpunit --bootstrap
  tools/tests/_bootstrap.php --no-configuration tools/tests` loads a per-lib
  vendor autoloader, so symfony/yaml resolved and the jobIf() legs EXECUTED;
  the tools-guards CI job (ci.yml, `tools: phpunit:10` PHAR, no composer
  autoload) never resolves Yaml, so those exact legs SKIPPED there. Proven
  both ways with a no-autoload stub runner
  (/tmp/opencode/cses5d-r2/noautoload-runner.php, shape-check line prints
  "symfony/yaml NOT resolvable"): round-1 file = testPublishingRides... +
  testTheReleaseArmIsFilteredToCandyCodeTags hit SKIP; round-2 file = 7/7
  PASS. jobIf() now extracts the four-space `if: >-` scalar as a text region
  (\s+-flattened, comments provably outside the scalar — YAML carries them in
  the fold), fail-closed on no-anchor/double-anchor/empty-scalar, and the
  previously unpinned `github.event.workflow_run.event == 'push'` conjunct is
  pinned. Verified the text fold equals the parser's value byte-for-byte
  (parsed scalar, whitespace-flattened, == extracted region).
- MAJOR-2: BUILD-REF row now leads with the observed package name
  (`file_put_contents(..., "sugarcraft/candy-code-web " . $v . " " . $r .
  "\n")`, extractor semantics unchanged, still fail-closed); DOCKER.md +
  SERVER.md each carry one honest clause that the recorded ref is the
  candy-code-web package's (same split-sync stream) and a package-side-only
  lag stays outside what the row proves. New test pin locks the row shape.
- MINOR: "SAME YAML parser" docblock sentence replaced with the text-region
  wording (class docblock doctrine paragraph rewritten likewise).
- NIT (taken): stepWith step-key regex accepts `- uses:` as well as `uses:`
  (spaces-only capture keeps the sibling-indent math identical); disclosed
  here — the file currently spells steps dash-leading only at indent 6 via
  `- uses:`, exercised by the build-args/metadata pins either way.
- Battery: 486 tests, 2442 assertions, OK (486T is the round-1 count;
  assertions +6 net — new pins). Five-guard: 133T with ONLY the inherited
  ReadmeSuiteFigureDrift staleness pair (live 25,666 vs pinned 25,537 —
  foreign-wave drift, this lane touches zero candy-code/tests files).
  Author 1ff716b92: %ae and %ce greps each detain@interserver.net, zero
  Co-Authored trailers, tree clean, NOT pushed (remote branch absent).
