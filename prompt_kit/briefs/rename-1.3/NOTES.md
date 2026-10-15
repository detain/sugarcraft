# Step 1.3 — split candy-flip → lib + new app candy-gifview

Evidence log (2026-10-15). Plan authority: plan_rename.md lines 596-628 (step 1.3) + Part A rules.

## What moved / was created
- `candy-flip/bin/candy-flip` → `candy-gifview/bin/candy-gifview` (git mv; bin is now a 2-line shim over the new Cli class; autoload-probe text copied exactly, brand string flipped).
- New `candy-gifview/src/Cli.php` (`SugarCraft\GifView\Cli`): arg parsing, `--help`/`-h`, preset selection, viewport sizing (`SizeIoctl::query(1)` behind `stream_isatty(STDOUT)`, 60×24 fallback), decode-error → stderr + rc 1, `$play` closure seam for tests.
- New `candy-gifview/src/Lang.php` (namespace `gifview`) + `lang/*.php` (16 locales) carrying ONLY `cli.usage` + `cli.no_autoload`; those two keys were REMOVED from all 16 `candy-flip/lang/*.php` (they existed solely for the bin).
- `candy-flip/tests/BinSizeQueryPolarityTest.php` → `candy-gifview/tests/` (git mv), rewritten: scans BOTH `bin/candy-gifview` and `src/Cli.php` for `(int) STD*` casts (token-based), pins the descriptor-1 + isatty-gate substrings in Cli, keeps the behavioural PTY test against the new bin.
- candy-gifview skeleton per AGENTS adding-a-lib: composer.json (type project, bin, requires candy-core+candy-flip+candy-pty; NO repositories block), phpunit.xml, README.md, CALIBER_LEARNINGS.md, examples/view.php, .vhs/view.tape, .assets/icon.png, .gitignore.
- candy-flip composer.json: `type: project → library`, `bin` removed, description re-framed as toolkit. Player/Renderer/Decoder/AnsiArt family all stay in candy-flip. candy-pty require STAYS (Renderer::withAdaptiveSize uses SizeIoctl).
- Wiring: root composer.json require+repositories, root composer.lock (`composer update 'sugarcraft/*' --no-install`), docs/MATCHUPS.md (gifterm reference row → CandyGifView; flip gained a Libraries row; Apps row replaced by CandyGifView), PROJECT_NAMES.md (rationale row + the line-118 app mention now says CandyGifView), root README.md (flip row MOVED to the libraries table as toolkit; gifview row added to Apps), docs/index.html (icon strip + lib-card + demo-card for gifview; flip card re-worded), docs/_data/candy-gifview.{json,body.html} + flip body Quickstart now points at the candy-gifview CLI + flip json tagline → toolkit, `php tools/gen-docs.php` regenerated all docs/lib pages (+ new candy-gifview.html; gen-docs --check rc 0), .github/workflows/vhs.yml both lists, codecov.yml fix + component, scripts/bootstrap-org-repos.sh DESCRIPTIONS, tools/renames.json candy-gifview status pending → "renamed".

## Suite figures (linked vendors via refresh-deps --mode=linked)
| suite | before | after | note |
|---|---|---|---|
| candy-flip | 196T/903A/0F/exit0 | **191T/887A/0F/exit0** | −5T = moved LangTest cli.usage test (1) + moved BinSizeQueryPolarityTest (4) |
| candy-gifview | — | **16T/71A/0F/exit0** | Cli 8 + Lang 3 + BinSize 5 (PTY test executed, not skipped, on this box) |
| candy-top | 2110T/107777A | 2110T/107831A/exit0 | test count exact; A-wobble +54 is the documented CANDY_TOP_LOGO=random per-open re-roll non-determinism (campaign floor note says "~107.8k"), untouched by this step |
| candy-mosaic | 661T/14820A/5S | 661T/14820A/5S/exit0 | unchanged |
| sugar-reel | 552T/8489A | 552T/8489A/exit0 | unchanged |
| tools/tests | 414T/1391A | **418T/1398A/exit0** | +4T/+7A = docs/_data-driven provider rows for the new candy-gifview page (expected drift, green) |

sugar-crush: ZERO references to `SugarCraft\Flip` / candy-flip in src|bin|composer.json → no crush filter run needed (per brief).

## Gates
- `php tools/check-path-repos.php --no-lib-path-repos` → rc 0 (61 libs scanned; candy-gifview manifest carries NO repositories block — the linked-mode refresh had injected one into the working copy; stripped before commit).
- `php tools/gen-docs.php --check` → rc 0 ("all 61 pages + index.html counts match generated output"; generation tally 40 libraries / 21 apps after flip moves tables).
- `composer validate` in candy-gifview + candy-flip → manifest valid (only complaint is the gitignored local composer.lock content-hash, expected after manifest edits; lock is never committed).
- `php -l` clean on all new/edited PHP.
- Live CLI smoke: `--help` → usage on stdout rc0; no args → usage on stderr rc1; missing file → rc1 (message emitted by candy-flip Decoder).
- candy-top `refresh-deps --status` shows `escaping=1` warning — NOT reproducible as an escaping vendor symlink (all candy-top vendor/sugarcraft links resolve inside the monorepo); pre-existing tool quirk, record-only, figures green.

## Deviations / decisions
1. `.vhs/view.gif` committed as a byte-copy of candy-flip's `play.gif` (placeholder; CI re-renders). AGENTS says "Don't commit GIFs" but candy-flip's committed `play.gif` + vhs.yml render job is the repo precedent followed here.
2. examples/play.php + .vhs/play.tape STAY in candy-flip: plan rule 4 — they demo the library API (Decoder+Player+Program directly), not the CLI. candy-gifview got NEW examples/view.php + .vhs/view.tape driving the CLI.
3. Missing-file error text keeps the `candy-flip:` brand (raised by the library's Decoder, not rewritten by the app) — honest layering; CliTest asserts the neutral `no such file` fragment.
4. gifview emoji 📽 (flip keeps 🎞).
5. Pre-existing reds untouched per brief: candy-mines 1F+4W, sugar-dash 2F, candy-pty hang (none in the suites this step ran). <!-- @rename-keep -->
6. No ci.yml edit (scripts/affected-libs.php auto-discovers), no durations.tsv row (non-crush lib), no codecov flag file beyond fix+component, no Packagist/org repo creation (plan defers to step 2.5).
