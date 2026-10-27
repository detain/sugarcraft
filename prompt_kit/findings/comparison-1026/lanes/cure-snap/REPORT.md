# cure-snap — SessionSnapshot artifact-refs red (C-WEB-1.d carrier)

**Commit:** `dd1aab17334f79ded5652a08e0cb01f64c22d1a7` on `cc/cure-snap` (worktree
`/home/sites/cc-cmp-cure-snap`, base `75ba82ddb`, re-read from git log; NEVER pushed).
Author/committer Joe Huss <detain@interserver.net> verified via separate `%ae`/`%ce`
greps (1/1), zero Co-Authored trailers. One file:
`candy-code/tests/Host/SessionSnapshotTest.php` (+15/−11).

## Judgement: TEST at fault, code correct

Red: `SessionSnapshotTest::testAFinishedCallNamingAnArtifactGainsTheRefsOnItsRow`
(expected the pre-C-WEB-1.d basename-stem projection `artifactId: 'art'` on a
fabricated row `ToolResult(imagePath: '/media/s1/art.png')` carrying NO bytes).

The BRIEF's law is explicit (and the shipped fold matches it sentence for
sentence): "A ref with no bytes projects nothing, so no card ever renders that
cannot load" — `TranscriptProjector::adoptedPath()` drops an outside-store path
with `$bytes === null`, and `SessionSnapshot::mediaBearingRow()` folds through
the same store per R16(a). The old expectation pinned exactly the broken
behavior C-WEB-1.d cured: a ref whose artifact id `media.fetch` can never serve.

Code-over-detection check (real rows): NOT mis-detected.
- Store-owned paths project as-is (realpath-inside arm) — unchanged from before.
- Real persisted rows carry `imageBytesBase64` in `toolResults`
  (`src/Message.php` jsonSerialize) so a live/reload re-fold of a real media
  row has bytes to adopt deterministically — the R16(a) event/snapshot
  agreement for real rows is pinned elsewhere by
  `TranscriptProjectorMediaTest::testTheSnapshotRowCarriesTheEventRefsByteIdentical`.
- The only drop is a no-bytes fabricated path — correct per the adoption law.
- Determinism: `candy-code/tests/bootstrap.php:412` pins HOME suite-wide, so
  `MediaStore::forSession('s')` always opens in the suite sandbox (the fold
  cannot flip polarity via ambient home); no class-level sandbox added.

## Cure shape

Flipped the test to the new truth and renamed it to say so (house precedent
for polarity flips): `testAFinishedCallNamingAnUnloadableArtifactGainsNoRefs`
— asserts `media`/`mediaTruncated` ABSENT, `imagePath` persisted key rides
untouched, and the whole row serialises byte-identical to
`$row->jsonSerialize()` (fold-by-absence). The class's other five tests
untouched. No new test FILES → durations.tsv unaffected; suite-figure.json
untouched.

Mutation proof: reverting the shape() wiring to
`mediaBearingRow($row, null)` (old store-less projection) reddens exactly the
renamed pin (:95, media key reappears); cp-restore md5-verified
(25b706f508a7d726b905bcc077a19c44), filter green again.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)

- `--filter 'SessionSnapshot'` → **OK, 6 tests, 29 assertions** (2 PHPUnit
  deprecations pre-existing, same set as at base; the "Undefined array key
  media" PHP warning the red run carried is gone).
- `--filter 'Session|Chat'` → 3351 tests, **3 failures, none mine**:
  1. `RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix`
     ("the server has no session `77b41d`") — disclosed prefix-carrier red
     riding master at base.
  2. `RouteTraitParityTest::testTheArmFamiliesArePairwiseDisjointAndCoverTheFrozenRoster`
     (`SessionPickerSearchMsg` not in a family) — cure rides P-CTX.d.
  3. `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost`
     (`chat.effort.shape.{none,request,thinking,unset}` catalogue rows with no
     asker in src) — NOT on the brief's expected-reds list; pre-existing at
     base (my diff touches only SessionSnapshotTest; nothing in the media/
     snapshot fold reads Lang), another chain carrier needing its owner's
     cure or key-removal.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`
  → 133 tests, 15,580 assertions, **exactly the 2 staleness-pair reds**
  (pinned 25,537 vs live 25,942 — closeout re-pin duty, untouched here).
