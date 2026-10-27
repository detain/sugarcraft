# C-SEC-2.b — server-computed diff on permission.asked

LANDED: commit 9b6c8c19d on cc/C-SEC-2.b (base 4f657c755), 13 files +885/−7, author/committer detain@interserver.net verified separately, zero trailers. Tree clean; not pushed.

## What shipped
- NEW `candy-code/src/Permissions/AskDiffPreview.php` (brief path): `for(tool, args, root): ?string` composes the tools' OWN `previewDiff` for Edit/Write/ApplyPatch behind a per-path containment gate (`PathJail::confine`), caps at MAX_DIFF_BYTES=32768 past which the wire carries an omitted note with real +/− counts (genre of BuildsUnifiedDiff::omittedDiffNote). ApplyPatch: parse-fail falls back to raw patch text (Renderer's idiom); a parsed patch with ANY escaping operation yields nothing (never a partial consent surface).
- `src/Tools/PathJail.php`: NEW `confine(root, path): ?string` — resolve()/resolveForCreate() composed with an inside-root predicate. Necessary composition inside the lane's declared dependency (no third containment algorithm); it is NOT in the brief file list — welder attention. Fail-closed: refuses the spill allow-list exception, so a symlinked spill never previews.
- Wiring: `PendingAsk` ctor `?string $diff = null` (13th param); `describe()` attaches `diff` ONLY when the confined preview exists (key absent otherwise — never a null value on the wire); `fromFrame()` drops non-string/empty/oversize peer values (hostile-shape gate); `EngineBackend::completeAsyncBlocking` now passes `$this->root` (captured for the static closure) as `$projectRoot`; `TranscriptProjector::askData` and `SessionFeed::askSummary` pass `$ask->diff` through; `Definitions::PENDING_ASK` declares `diff` optional (required list unchanged).
- Web: `lineDiff.ts` `diffCounts(text)` (headers skipped); `PermissionCard.vue` preview computed prefers `ask.diff` — `(server diff)` label, real `@@` positions, counts read off the served text — and falls back to the client snippet-mint when absent/empty/whitespace. Raw-arguments `<details>` untouched (C-SEC-2 law). Spec +3 arms.

## NEW *Test.php files (gate must add durations.tsv rows)
- candy-code/tests/Permissions/AskDiffPreviewTest.php (12T)
- candy-code/tests/Permissions/PendingAskDiffTest.php (6T)
- candy-code/tests/Host/PermissionAskDiffEventTest.php (2T)

## Gates (all verbatim, cwd candy-code, plain pipe)
- targeted: `--filter 'AskDiffPreviewTest|PendingAskDiffTest|PermissionAskDiffEventTest|PendingAskTest|ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest'` → OK, 45 tests / 454 assertions, 0F (2 PHPUnit deprecations are pre-existing AccessorDeprecationTest non-public providers matched by data-set name — file untouched by this lane).
- five-guard filter → 133T / 15341A, failures EXACTLY the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 25,585, +48 incl. 20 new methods) — weld re-pin duty; GlobDialect / DocFigure / ChildWallClock / DuplicatedTestHelperDrift green (scratch helpers byte-identical across files, the drift-safe shape).
- protocol: `php scripts/gen-protocol-schema.php --write` + `(cd candy-code-web && npm run gen:protocol)` in-lane, guards green WITH regenerated files, `--check` rc0, then committed tree restored via `git checkout --` (schema.json + generated.ts; SERVER.md never moved). Welder regenerates; ProtocolSchemaDriftTest/ServerProtocolDocumentationDriftTest/generated.spec.ts are judged on the welded tree.
- vitest serial: permissionCardDiff.spec.ts 6/6 (also green post-restore — no dependency on the regenerated generated.ts); src-web/protocol/__tests__/generated.spec.ts 3/3 with regenerated file.
- mutation proofs: neuter describe()'s diff tail → exactly the 2 wire arms red (frame key absent), class-direct suite green by design; force the client fallback in PermissionCard.vue → the real-counts spec arm red. Restores md5-verified (aa08ff2d / 09590507).
- fixture lesson discovered: BuildsUnifiedDiff MERGES hunks whose 3-line contexts touch — a 9-line file edited at 2 and 8 is ONE `@@ -1,9 +1,9 @@` hunk; multi-hunk arms use a 20-line file at 2/18 (probed).

## Deviations & residuals
- `PathJail.confine` addition (above).
- TUI Renderer NOT rewired: the desktop modal's own preview path is the pre-existing sibling; this lane serves the ask frame + web card (brief scope). AskDiffPreview deliberately imports nothing Renderer-specific.
- dist/ not rebuilt/committed per lane law — welder's `npm run build` carries it.
- Cap note for an oversize fallback patch reports `+0 -0` when the raw text has no diff grammar (honest: nothing countable); previews keep their real counts.
- suite-figure.json / durations.tsv untouched by law; staleness pair red at tip is the expected hand-off.
