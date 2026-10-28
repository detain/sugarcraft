# C-SES-6.c — install.sh + Homebrew formula generator — LANDED

Commit: `38ae2f185992aaf7d35b41f041b00b2c034f8efd` on `cc/C-SES-6.c` (base cf0cfd0be).
Author/committer both Joe Huss <detain@interserver.net> (%ae grep=1, %ce grep=1, zero Co-Authored-By). Never pushed.

## Files
- create `candy-code/tools/install.sh` (mode 100755, POSIX sh, `sh -n` and `bash -n` clean)
- create `candy-code/tools/gen-homebrew-formula.php`
- create `candy-code/packaging/homebrew/candy-code.rb.tmpl`
- create `candy-code/tests/Cli/InstallScriptTest.php` (12 tests) — NEW, needs durations.tsv row at gate
- create `candy-code/tests/Cli/HomebrewFormulaTest.php` (7 tests) — NEW, needs durations.tsv row at gate
- modify `candy-code/README.md` (Install section: one-liner + flags + brew lines)

## Behavior
install.sh ladder mirrors SelfUpdate: php 8.3+/curl/sodium checks → release
selection (stable: highest comparable candy-code/vX.Y.Z, drafts+prereleases
skipped; dev: exact candy-code/dev tag, prerelease allowed; both required
assets with non-empty URLs; GitHub API leg follows Link rel=next up to 5
pages, --base-url reads one releases.json from a mirror, file:// included) →
download phar/sidecar/sig → sidecar format+name legs → pinned-key Ed25519
verify (.sha256.sig over EXACT sidecar bytes preferred; legacy .minisig over
raw phar bytes with deprecation note; neither present → refuse) → sha256
compare (sha256sum/shasum) → staged install to --dir (default
$HOME/.local/bin, /usr/local/bin if HOME unset) as `candy-code` mode 0755.
Flags only, zero getenv, zero CANDY_CODE_/SUGARCRUSH_ names (pinned).
Generator: validates version (1.2.3 | v1.2.3 | candy-code/v1.2.3), sha256
(64 lowercase hex) or --phar hash, url override, renders template, refuses
to leave any placeholder, stdout or --out for C-SES-6.d to attach.
§6.1 honored: formula is an asset renderer only, no tap, no push.

## Tests (targeted, plain pipe, cwd=candy-code, -c phpunit.xml)
- `InstallScriptTest + HomebrewFormulaTest + EnvRosterDrift + ReadmeRosterDrift + DocFigureProseDrift + GlobDialectDifferential + DuplicatedTestHelperDrift + ChildWallClockBudget`: **OK (197 tests, 55444 assertions)**.
- Manual smoke (`/tmp/opencode/cc-ses6c-smoke.php`): happy install (bytes+0755+spaced dirs), tampered sidecar → pinned-key refusal, digest mismatch behind intact sig → sha256 refusal, minisig leg installs, unsigned release refused — all as designed.

## Inherited state disclosed
- ReadmeSuiteFigureDriftTest pair reds 25537-pinned vs 27240-live at this
  tree; only 19 of that gap are mine — the pair was stale at base
  (sibling waves' test adds). Re-pin + durations.tsv rows for the two new
  files are GATE duties; never committed here.

## Bugs caught in-lane
- `curl ... -- "$1" -o` made `-o` a URL (curl `--` ends options) → fixed to `-o "$2" -- "$1"` before commit, re-proved.
- Generator flag regex needed digits for `--sha256` → fixed pre-commit.

## FIX-1 (review-r1, follow-up commit on cc/C-SES-6.c)
- MAJOR-1 CURED: two WALKS_A_DIRECTORY_THE_TEST_MADE rows added to
  candy-code/tests/TreeWideGuardRosterTest.php, alphabetical between
  Cli/HelpTest.php and Cli/JsonSchemaEnvelopeTest.php:
    'Cli/HomebrewFormulaTest.php' => ['glob($this->root.\'/*\')']
    'Cli/InstallScriptTest.php' => ['RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS)']
  Each carries a teardown-cleanup classification comment (lane-fg precedent).
  TreeWideGuardRosterTest alone: 17T/3559A OK (+4A = the licensed rows now
  counted), 0F. Lane filter (8 classes): 197T/55444A OK — byte-exact vs r1.
- NIT-1 TAKEN: install.sh:308 GitHub-leg curl gains `--` before "$next"
  (defense-in-depth, mirrors the :301 BASE_URL leg; bash -n green; the
  pagination leg is live-GitHub-only so zero tests read the line — no re-pin).
- NIT-2 LEFT as CLOSEOUT carry: README:154 "no environment variables" —
  review did not flag it step-local-load-bearing.
