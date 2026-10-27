### C-WEB-1.d — Read-image MediaCards adopt into the session media store
- item: C-WEB-1 (sources: GAP-TL-19 web half, k2-sum `media.fetch` live defect, k2-dorm broken Read-image path)
- kind: fix · MAJOR · M
- approach:
  - **Root cause.** `TranscriptProjector::mediaRefs()` (`candy-code/src/Host/TranscriptProjector.php:301-303`, `mediaRef()` :351-364) projects Read's workspace `imagePath` (set at `src/Tools/BuiltIn/Read.php:745`) as an artifact named by its basename stem. `MediaMethods::pathForArtifact()`/`containSessionPath()` (`src/Protocol/Methods/MediaMethods.php:499-530`) serve only the session MediaStore. The card therefore answers `media_artifact_not_found`, or shows the wrong image when a stored artifact shares the stem.
  - **New store method.** `mediaRefs()`/`mediaRefsForPaths()` gain an optional `?MediaStore`. A head path outside the store that carries bytes is adopted through a new `MediaStore::adopt(string $stem, string $bytes, string $ext)`. Bytes ride live and are persisted as `imageBytesBase64` (`src/Message.php:1148`).
  - **Naming.** `adopt()` is idempotent: the name is deterministic, `read-<sha256(toolResult id|path) prefix>.<ext>`, and an existing copy is reused. The ref then names the copy. A ref with no bytes projects nothing, so no card ever renders that cannot load.
  - **Wiring.** `toolFinishedEvent()` builds the store from its `$sessionId`. `SessionSnapshot::mediaBearingRow()` (`src/Host/SessionSnapshot.php:161-180`) passes the same store, so the live view and a reload agree (R16(a)).
  - **What stays the same.** There is no new file read (bytes come from the ToolResult), and the TUI still paints from `imagePath`.
- files.create: none
- files.modify: `candy-code/src/Host/TranscriptProjector.php` (`mediaRefs`, `mediaRefsForPaths`, `toolFinishedEvent`), `candy-code/src/Host/SessionSnapshot.php` (`mediaBearingRow`), `candy-code/src/Support/MediaStore.php` (new `adopt()`), `candy-code/docs/MEDIA.md` (the `tool.finished` media-refs paragraph, ~:182-186)
- tests: modify `candy-code/tests/Host/TranscriptProjectorMediaTest.php`, which pins four cases:
  - a Read image is adopted, and its ref sits under the store dir;
  - a second projection reuses the same artifactId;
  - the snapshot projects the same id;
  - an unadoptable path gives no ref.

  Modify `candy-code/tests/Protocol/MediaMethodsTest.php`: a Read-image ref taken from `tool.finished` fetches through `media.fetch`.
- docs/guards: `docs/MEDIA.md`. `tests/Support/ReadPathCensusTest.php` is unaffected (no new read sink); confirm it stays green.
- serialized: [hot:candy-code/src/Host/TranscriptProjector.php] [docs:MEDIA.md]
- deps: none
- phase: normal
- ruling: none
- risks:
  - The projector now writes up to Read's image cap per Read image on the TUI update path.
  - A snapshot of an old session creates its media dir on first read.

## C-WEB-2 — Served command parity

