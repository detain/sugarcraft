# C-SES-8.f — JSONL transcript writer + `jsonl` export format

**LANDED.** Commit `25c91d6eb2c6398aeb81216c89dec28548b981bd` on `cc/C-SES-8.f` (base cf0cfd0be). Author+committer `detain@interserver.net` (verified `%ae`/`%ce` separately), zero Co-Authored trailers. Never pushed.

## What shipped
- `Exporter::toJsonl()` — one `toJson()` row per physical line, `shown()` filter shared, single-line encodes (UNESCAPED_SLASHES|UNESCAPED_UNICODE|INVALID_UTF8_SUBSTITUTE); unencodable row answers a framed placeholder so line-count framing (the writer's HWM) never desyncs.
- `ShareSession::FORMAT_JSONL` in SUPPORTED_FORMATS; serializeRaw→toJsonl, contentType `application/x-ndjson`, fileExtension `jsonl`.
- `ExportCommand::parseFormat()` maps `jsonl` (word AND `.jsonl` extension via formatFromExtension). Protocol untouched (MethodSchemas enum literal never edited — no diff).
- `JsonlTranscriptWriter` (src/Share/): append-only journal `<root>/<project-slug>/<session-id>.jsonl` under `~/.candy-code/transcripts` (dir 0700, file 0600, symlink-refusing, umask-0077, flock, best-effort never-throws, mirrors SubAgentTranscriptLog idiom); HWM replayed from the file — marker `{"type":"rewind","to":N}` requires role-absence + type=rewind + int `to`; message rows provably carry `role` and no top-level `type` (toWire + all four Messages\*::toArray verified). Seams for .g: `enabled()/setEnabled()` process flag (default OFF), `pinDefaultRoot()`, `pathFor($sessionId,$root,$baseDir)` naming without creating, ctor `(?baseDir, ?projectRoot)`.
- `/share jsonl` exclusion PINNED in-step: ShareCommand's own hardcoded parseFormat still refuses (cmd.share.bad-format wording untouched, COMMANDS.md:663 prose stays 4-format).
- lang/en.php: `cmd.export.bad-format`/`hint`/`usage` values gain jsonl (value edits only; keyset unchanged → locale parity untouched). COMMANDS.md regen: exactly the 1 generated hint line.
- ReadPathCensusTest rows (in-step, §1.8): `Share/JsonlTranscriptWriter.php|fopen` SELF_LOCATED, `|file_get_contents` OWNED_HOME (HomeDirectory::owned() reference proven).

## Tests/gates (cwd candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe)
- NEW: `candy-code/tests/Share/JsonlTranscriptWriterTest.php` — 9T/53A (append-only, idempotent byte-stable re-save, rewind marker + re-set growth, role-not-marker framing, 0600/0700 modes, empty-save no-op, pathFor determinism/containment, enabled roundtrip). **Needs durations.tsv row (gate).**
- ExportCommandTest +1T: `/export out/session.jsonl` raw rows + `/export jsonl out/named` + `/share jsonl` refusal with no file written.
- Gate 1 `--filter 'JsonlTranscriptWriter|ExportCommand|ShareCommand|ShareStore|Exporter|CommandDocsDrift|CommandsTableTakesColumn|ReadPathCensus'`: OK, 151T/1591A.
- Gate 2 `--filter 'DuplicatedTestHelperDrift|TreeWideGuardRoster|GlobFigureDrift|RegistryTranslation|DocFigureProseDrift|Share|Export|ProtocolSchema'`: OK, 649T/17861A.
- Mutation M1 (drop marker-honour from scanHighWater): exactly the rewind pin red; cp+md5 restore green.
- Baseline inherited reds (NOT this lane, reproduced with my diff stashed at cf0cfd0be): ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost (chat.startup.personal et al. unasked), CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers (cli.bundles.marketplace ordering); plus pre-existing 2 PHPUnit deprecations from tests/Config/AccessorDeprecationTest private providers.

## Not committed
suite-figure.json, durations.tsv, dist/, protocol files (untouched anyway). No files outside the step's list + §1.8-owed ReadPathCensusTest rows were touched.

## FIX ROUND 1 (review-1, tip f9f079205d55ff9e973adc339e32f41152adb7b6 on cc/C-SES-8.f)

- MINOR-1 FIXED: `candy-code/src/Share/JsonlTranscriptWriter.php:17-19` docblock lie
  post-rewind removed — now reads "Markers re-set the mark exactly as
  {@see self::scanHighWater()} does; the journal keeps every row ever appended,
  including rewound branches." Pinned in-step inside the existing rewind test
  (`testRewindAppendsAMarkerLineInsteadOfRewritingTheFile`, +0T/+2A): reflection
  docblock assertStringNotContainsString('replays as the export') +
  assertStringContainsString('including rewound branches'). Mutation (old lie
  restored verbatim) → exactly 1 failure; cp+md5 restore clean
  (4c1dd7a21172463c62400ef7030d9ed8).
- MINOR-2 FIXED (gate actuals, re-measured this round, cwd candy-code,
  env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe, filter exactly as the
  gate1 line above):
  - PRE-FIX at 25c91d6eb: **161T / 1639A** — reviewer's correction CONFIRMED
    exact (lane's 151T/1591A claim was stale; +10T/+48A = the lane's own 9+1
    new tests + 1 derived getcwd-census dataset row for the new src file).
  - POST-FIX at f9f079205: **161T / 1641A** (+2A = the docblock pin).
  - 2 PHPUnit deprecations = the pre-existing AccessorDeprecationTest pair.
  - SymbolCitationDrift also re-run green (9T/17233A) — docblock cites
    self::scanHighWater(), production-symbol shape unguarded by design.
- MINOR-3 OUT-OF-SCOPE → **DOC-ROW for weld/closeout**: candy-code/README.md:621
  `/export [md|html|json|text]` row lacks `jsonl`. No guard owns it
  (ReadmeRosterDrift green). Not edited by this lane.
- Author: %ae and %ce verified separately == detain@interserver.net, zero
  Co-Authored-By. suite-figure.json / durations.tsv / dist untouched. Not pushed.
