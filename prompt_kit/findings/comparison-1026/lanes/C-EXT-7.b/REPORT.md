# C-EXT-7.b — lane report

**Verdict: DONE.** Branch `cc/C-EXT-7.b`, tip `3201a639e` (re-read from `git log`), base `e3a84187d`, one commit, tree clean, never pushed.

## Files (numstat at tip)

| file | +/− |
|---|---|
| `candy-code/src/Cli/Subcommands.php` | +27/−3 |
| `candy-code/tests/Bundles/BundleInstallTest.php` | +43/−0 |
| `candy-code/docs/PLUGINS.md` | +5/−1 |
| `candy-code/lang/en.php` | +1/−0 |

## What landed

- **Dot-name refusal:** `bundlesInstall` rejects any final name (manifest `name` or basename fallback) beginning with `.` via `str_starts_with` after the segment-regex gate; `.`/`..` still ride the pre-existing `invalid_name` branch. New key `cli.bundles.install.dot_name` (en.php only — candy-code ships no other locale file). Rationale pinned: `BundleDiscovery::scanContainer` globs `<container>/*`, which never matches a dot-entry, so a pre-fix `.foo` install sat in the root invisible to every later load.
- **`.git` strip after clone:** immediately after `Bundle::hasManifest` validation passes, `self::removeTree($staged . '/.git')` — the SAME hardened no-follow walker C-SEC-1.a landed (`removeTree`, PLG-1-P1: unlinks links as links, never descends). No second walker minted. No-op by shape on the path arm (copyBundleTree already skips `.git`). Placed before the rename.
- **Docblock truth:** `bundlesInstall` docblock (was the :1606-1612-era text, drifted to :1837-1849 at base) now states both arms land content-only trees and that leading-dot names are refused.
- **Docs in-step (§1.8):** PLUGINS.md "Installing and listing" paragraph carries both behaviours. No test guards PLUGINS.md prose (grep: zero test refs); no PLUGINS.md entry in the generated-region lists.

## Tests (both new ones fail pre-fix)

- `testADotPrefixedManifestNameIsRefusedInsteadOfInstallingInvisibly` — manifest name `.invisible` → EXIT_FAILURE, root empty.
- `testAClonedInstallShedsItsGitDirectoryBeforeTheRename` — clone seam plants `.git` (with nested file) in the scratch; success path renames; dest must have plugin.json and no `.git`.

**Red-before proof:** both new tests run against base `Subcommands.php` (src file swapped, fixed copy cp-backed-up first, md5-verified restore `ee1c68b9…`): `Tests: 2, Failures: 2`. Green-after below.

## Gates (cwd `candy-code`, plain pipe, `env -u LINES -u COLUMNS`)

| gate | base | tip |
|---|---|---|
| `--filter 'BundleInstall|Subcommands'` | 84T/495A OK | 86T/501A OK |
| `--filter 'BundleInstallTest'` (brief's class figure) | 17T/51A OK | 19T/57A OK |
| five-guard `ReadmeSuiteFigureDrift\|DocFigureProseDrift\|GlobDialectDifferential\|ChildWallClockBudget\|DuplicatedTestHelperDrift` | — | 133T/15,618A, **only** the 2 ReadmeSuiteFigureDrift staleness reds (pinned 25,537 vs live 25,987 — wave-wide drift, lane-expected; this lane never touches suite-figure.json) |
| `--filter 'LangParity\|CliLangCatalogue\|TuiLang'` | — | 24T/4,394A OK |
| `php -l` on both edited PHP src/test files | — | clean |

(Note: the brief's "17T/51A" was the class figure; the stated filter string also matches sibling `Subcommands*` classes — base measured 84T/495A, tip 86T/501A, delta exactly +2T/+6A from this lane.)

## §1.9 literal audit

No glob-shaped literals (no wildcard chars at all) and no `**Refused:**`-shaped strings in the new test/lang text.

## Deviations / seams

- Brief cited `Subcommands.php:1692` and docblock `:1606-1612`; live positions drifted (bundlesInstall at :1850, docblock :1837-1849) — matched by content, regions per plan §2 (`bundlesInstall` methods only).
- The dot-name refusal covers the basename-fallback path too (a directory named `.foo` with empty/absent manifest name would otherwise install invisibly identically) — one gate, both sources. Per the brief's risk note, dot-named bundles installed BEFORE this step stay invisible; this stops new ones only.
- No new spawn site; no new `proc_open`; E646 untouched (clone ceiling byte-unchanged).

## Durations

none — no new `*Test.php` files; `scripts/parallel-tests-durations.tsv` untouched.
