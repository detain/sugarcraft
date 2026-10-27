# REVIEW-2 — C-EXT-7.a fix round (cc/C-EXT-7.a @ c311273ad, base 0e5c35bf9, tree clean)

VERDICT: CLEAN

## Fix-commit confinement and law
- `git show --numstat c311273ad`: exactly two files — candy-code/docs/PLUGINS.md (7+/4−), candy-code/tests/Bundles/BundleDeclinedSurfacesTest.php (1+/1−). NO production src change rode the fix.
- Author law: `%ae` grep -c detain = 1; `%ce` separate grep = 1; Co-Authored count = 0; plain -m subject. Tree clean (`status --porcelain` empty).

## Axis 1 — PLUGINS.md :81/:89 bullets (r1 MINOR)
Fixed per the reviewer's own recommended wording. Truth-checked against shipped code:
- `hooksPaths()` (Bundle.php:213-236): inline `hooks` object answers `manifestPath()`; `mcpFile()` (Bundle.php:181-202): inline `mcpServers` answers the manifest path. "the declined-surface reader answers with the manifest path — hooksPaths()/mcpFile() names where … — " matches exactly.
- Launch notices (Bootstrap.php:2502-2514) interpolate ONLY `{count}`+`{names}` via lang keys bundles.hooks_declined/bundles.mcp_not_auto_started (en.php:1272-1273) — "while the decline notice names the affected bundles" is now true; the old "names the manifest" falsity is gone.
- Live-workspace /hooks: HooksHostCommand reads `$context->workspace?->hooks`, null leg composes HooksCommand::compose(null) → cmd.hooks.unmeasured ('Hooks: not measured — this session's hook chain could not be read.') + cmd.hooks.unmeasured.nochain row, builds nothing — matches the class docblock; PLUGINS.md carries no /hooks or 'not measured' claim that could contradict it (grep: zero hits).

## Axis 2 — test rename (r1 NIT)
`testAStringMcpServersManifestEntryIsADECLINEDConfigFile` → `...IsADeclinedConfigFile` at :268. Old spelling: zero hits tree-wide (php+md, vendor excluded); new name single occurrence; diff is the identifier line ONLY — body assertions (assertSame mcpFile path + decline-notice assertCount) intact, no behavior change, no vacuity. durations.tsv is file-keyed (1 row for the file, no per-method rows) — unaffected.

## Axis 3 — REPORT.md Round-2 section
Present (lanes/C-EXT-7.a/REPORT.md:37). Every claim verified: commit SHA, author greps, notices untouched, "no docs guard pins PLUGINS.md prose" (grep tests/ for PLUGINS: zero hits), and the two gate figures below reproduced EXACT.

## Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS)
- `vendor/bin/phpunit --filter 'Hooks|Bundle'` → Tests: 1246, Assertions: 4357, PHPUnit Deprecations: 2 — OK; EXACT r1/fixer figure (rename is identifier-only, as expected).
- five-guard filter (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`) → 133T / 15720A / 2F = ONLY the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 26,128) — unchanged shape from r1; gate owns the re-pin.

Both r1 findings cured, no new findings. Lane welds.
