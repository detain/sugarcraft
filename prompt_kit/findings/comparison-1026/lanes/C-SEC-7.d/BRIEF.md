# BRIEF — C-SEC-7.d (wave 16)

## Model-judged review for writes, with a chosen reviewer model
- area: sec · kind: gap · effort M · effort:  · phase: normal
- item: C-SEC-7 (sources: GAP-PER-07, CC-new#8)
- deps: none. It reuses `AskDiffPreview` if C-SEC-2.b landed first; otherwise it renders args.
- serialized: [bootstrap:permissionGate] [hot:candy-code/src/Permissions/PermissionGate.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/Definitions/PermissionSettings.php] [lang:settings.autoReview] [docs:SETTINGS.md] [docs:README.md] [docs:PERMISSIONS.md]
- ruling: none

## Files to create
`candy-code/tests/Permissions/AutoReviewScopeTest.php`

## Files to modify

- `candy-code/src/Permissions/PermissionGate.php` (`autoReviewsUnflaggedShell` → scoped)
- `candy-code/src/Permissions/ExecReviewer.php` (non-shell prompt)
- `candy-code/src/Config/Settings/Definitions/PermissionSettings.php` (two definitions)
- `candy-code/src/Config/LayeredSettings.php` (generated rosters)
- `candy-code/src/Cli/Bootstrap.php` (`permissionGate` reviewer build)
- `candy-code/lang/en.php` (`settings.autoReviewScope.*`, `settings.autoReviewModel.*`, `cli.warn.auto_review_model_*`)
- `candy-code/docs/SETTINGS.md` (generated)
- `candy-code/README.md` (user-tier-only roster sentence :1255)
- `candy-code/docs/PERMISSIONS.md` (Auto paragraph)

## Tests
the new test covers scope `shell` (unchanged), `writes` (an Edit is reviewed), the model override building its own backend, and a bad model warning once. Modify `candy-code/tests/Permissions/UnflaggedShellReviewTest.php` and `candy-code/tests/Permissions/ExecReviewerTest.php`.

## Docs / guards
`tools/gen-settings-doc.php --check`; `TrustKeyDocumentationDriftTest` (README user-tier-only roster and word number); `DocFigureProseDriftTest` (SETTINGS.md count)

## Approach

- `PermissionGate::autoReviewsUnflaggedShell()` (:998-1003) sends only shell calls to `ExecReviewer`. The reviewer is always built on the title backend (`Bootstrap.php:6849-6862`).
- Generalise it to `autoReviewsUnflagged()`, driven by a new `autoReviewScope: shell|writes|all` key (default `shell`, byte-identical today). `writes` adds `Edit`, `Write`, `ApplyPatch` and `WebFetch`.
- Add `autoReviewModel` (`provider/model`, empty means the title backend), built through the same factory as `titleBackend()` (:9397).
- `ExecReviewer` gains an args renderer for non-shell tools (the path and a capped diff from `AskDiffPreview` if 2.b landed; otherwise the args).
- Both keys are user-tier only, because each review is a paid model call.

## Risks
in Auto mode, `writes` multiplies paid review calls. The doc states the cost, and the default stays `shell`.
