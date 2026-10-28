### C-SES-6.c — `install.sh` + Homebrew formula generator
- item: C-SES-6 (sources: GAP-PLAT-03, p2-miss: packaging channels)
- kind: gap · effort M
- approach:
  - **`tools/install.sh`** (POSIX sh) does six things:
    1. checks for `php` 8.3+;
    2. resolves the latest `candy-code/v*` release, or `--channel dev`;
    3. downloads the `.phar`, `.sha256` and `.sha256.sig` (falling back to `.minisig`);
    4. verifies the sha256 with `sha256sum` or `shasum -a 256`;
    5. verifies the Ed25519 signature with `php -r` sodium and a PINNED key that must equal `SelfUpdate::PUBLIC_KEY_BASE64`;
    6. installs to `--dir` (default `~/.local/bin`) as `candy-code`, mode 0755. This relies on C-SES-6.a.
  - **No env vars.** Options are flags, never `CANDY_CODE_*` env vars. `EnvRosterDriftTest` requires every env var a doc names to be read in `src/`, which an installer variable never is.
  - **Homebrew.** `tools/gen-homebrew-formula.php` renders `packaging/homebrew/candy-code.rb.tmpl` (url, sha256, version, `depends_on "php"`, the phar installed as `bin/candy-code`).
  - **README.** The one-liner plus the brew lines.
- files.create: `candy-code/tools/install.sh`, `candy-code/tools/gen-homebrew-formula.php`, `candy-code/packaging/homebrew/candy-code.rb.tmpl`, `candy-code/tests/Cli/InstallScriptTest.php`, `candy-code/tests/Cli/HomebrewFormulaTest.php`
- files.modify: `candy-code/README.md` (Install section)
- tests: create:
  - `candy-code/tests/Cli/InstallScriptTest.php`: `sh -n`; the pinned key equals `SelfUpdate::PUBLIC_KEY_BASE64`; an end-to-end run against `file://` fixtures (via a `--base-url` flag) installs, and refuses a tampered sidecar;
  - `candy-code/tests/Cli/HomebrewFormulaTest.php`: the rendered formula carries the version and sha.
- docs/guards: README Install; `EnvRosterDriftTest` is untouched by construction (no env vars)
- serialized: [docs:README.md]
- deps: C-SES-6.a, C-SES-5.b
- phase: normal
- ruling: none
- risks: The test spawns `sh`, `curl` and `php`. Skip when `curl` is absent. Tests are not in the `src/` child-lifetime census.

