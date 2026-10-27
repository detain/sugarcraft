# C-WEB-1.d — Read-image MediaCards adopt into the session media store

**Commit:** `871f018ff16d48cff211758a566c0bab11985bb9` on `cc/C-WEB-1.d` (base `e4689c28e`, branch tip re-read from git log; never pushed). Author/committer Joe Huss <detain@interserver.net> verified via separate `%ae`/`%ce` greps (1/1), zero Co-Authored trailers.

## What shipped

Root cause cured: `TranscriptProjector::mediaRefs()` projected a Read's workspace `imagePath` under its basename stem, but `MediaMethods::pathForArtifact()`/`containSessionPath()` serve only the session `MediaStore` — web cards answered `media_artifact_not_found` (or, on a basename collision, the wrong image).

- **`src/Support/MediaStore.php`** (+56): new `adopt(string $stem, string $bytes, string $ext): string` — idempotent exact-name law: a regular file already at the target is reused untouched (never rewritten, containment re-checked via realpath); otherwise the bytes are published through the existing `publish()` path (0600 pre-rename chmod, `.partial` refusal, containment re-check). Stem/ext validated against `NAME_ALPHABET`/`normalizeExtension`/`FILENAME_MAX_LENGTH`. No new read sinks → `ReadPathCensusTest` green unchanged.
- **`src/Host/TranscriptProjector.php`** (+117/−11): `mediaRefs()`/`mediaRefsForPaths()` gained optional `?MediaStore $store` (+ `?string $resultId` on the ForPaths form); with a store, a head path OUTSIDE the store that carries bytes is adopted under deterministic `read-<sha256(toolResult id|path) prefix=ADOPT_STEM_HEX_LENGTH(32)>.<ext>`; a ref the store neither owns nor can adopt (no bytes / bad ext / store failure) projects NOTHING — no card renders that cannot load. Inside-store paths (judge by realpath, not basename id — the "wrong image" guard) project as-is. `toolFinishedEvent()` builds the store from its `$sessionId` only when the result carries an `imagePath` (media-less results mint no directory); shared derivation helper `mediaStoreForSession()` (null-on-fail) so all callers fold identically. Class docblock truth-flipped in-step: "PURE" → "PURE-BY-DEFAULT, SAVE ONE FOLD THAT CANNOT BE" (adoption is the one idempotent store write; everything else stays TurnRunner's).
- **`src/Host/SessionSnapshot.php`** (+38/−5): `mediaBearingRow()` takes the same store (`storeForRows()` — built once per shape() only if some row carries an image), so live and reload project the identical adopted artifactId — R16(a) agreement.
- **`docs/MEDIA.md`** (9/3): the `tool.finished` media-refs paragraph (~:182) rewritten to the adoption law (refs name the store; workspace Read adopted under `read-…` deterministically, idempotent; no-bytes → no ref). §1.8 satisfied in the same commit.

## Tests (no new *Test.php files — durations.tsv untouched, nothing to list)

- `tests/Host/TranscriptProjectorMediaTest.php` (+121/−13): HOME sandbox (`HomeSandboxTrait`, per-test uniqid home, media sweep helper `purgeMediaBeneath` — chr(42)-spelled, GlobDialect-safe). Four brief pins: (1) `testAReadImageOutsideTheStoreIsAdoptedAndItsRefNamesTheCopy` (real workspace file, ref path under `store->directoryPath()`, bytes on disk via `read()`, frame carries no base64); (2) `testReProjectingTheSameRowReusesTheOneAdoptedCopy` (same artifactId, `list()` has exactly one entry); (3) snapshot projects the same id — `testTheSnapshotRowCarriesTheEventRefsByteIdentical` flipped to pin `read-<hash>` equality across event vs `SessionSnapshot`; (4) unadoptable path gives no ref — `testAFinishWithoutMediaCarriesNoMediaKeys` flipped (`/only/a/path.png` without bytes → no `media` key at all). `testTheHeadRefNamesTheArtifactWithoutCarryingBytes` re-pinned to the adopted copy (independent re-spelled `adoptedStem()` helper documents the scheme). Store-less static paths (generate.status shape, cap/order/digest tests) byte-unchanged and green.
- `tests/Protocol/MediaMethodsTest.php` (+61): `testAReadImageRefFromToolFinishedFetchesThroughMediaFetch` — end-to-end DoD: Read-shaped host ToolResult → `toolFinishedEvent` (fixture `sessionId`) → ref's artifactId fetched through `media.fetch` byte-exact; the raw workspace path still answers `media_artifact_not_found` (adoption copies bytes, does not open the directory).

## Gates (cwd `candy-code`, `env -u LINES -u COLUMNS`, plain pipe, `-c phpunit.xml`)

- `--filter 'TranscriptProjectorMedia|MediaMethods|MediaStore|ReadPathCensus'` → **OK: 79 tests, 861 assertions** (2 PHPUnit deprecations pre-existing in untouched `MediaStoreTest` — reproduced there in isolation, not from this lane).
- five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'` → **133 tests, 15,417 assertions, exactly the 2 staleness-pair reds** (pinned 25,537 vs live 25,743 — closeout re-pin duty; `suite-figure.json`/`README`/durations never touched by this lane). DocFigure/Glob/ChildWallClock/DuplicatedTestHelper green (MEDIA.md text unpinned by any test needle; no glob-shaped literals added).
- `php -l` clean on all six files.

## Deviations / notes

- Design default taken (§6 carries no C-WEB-1 ruling): head-path-only adoption; non-head refs keep the store-less path projection (generate.status ledger paths ride `mediaRefsForPaths` with null store — unchanged behavior).
- `adopt()` returns the target path (string) rather than a `MediaArtifact` — the projector only needs the containment-guaranteed path to name in the ref; keeps one type per file, no new files.
- Risk from the brief acknowledged in code: projector writes ≤Read-image bytes per image on the TUI update path, one adoption per distinct (id|path) ever after (idempotent reuse pins it); first Read into an old session's snapshot mints the media dir (PrivateDir::ensure, 0700).
- Hunks confined to the media region of `TranscriptProjector.php` per the [hot:] handoff note (C-SEC-2.b `askData` emit region untouched).
