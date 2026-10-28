# C-WEB-3.f — DONE · tip 06463ea765212deacb10ffd29cc1769502b6c7fb (branch cc/C-WEB-3.f, base master 581ac6ede, ONE commit, never pushed)

`generate.submit` gains `kind: image|video` (default image); the video half rides `GenerateVideo::new()->withStoreSession($sessionId)` inside the existing forked `$run` and consent gate (per-kind tool name through consent, per-kind key set forwarded whole); the Generate panel now shows Image/Video tabs gated on `canGenerateImage`/`canGenerateVideo` — wiring the previously-dormant `canGenerateVideo`.

## Shipped (9 files, 609+/49−)
- `candy-code/src/Protocol/Methods/MediaMethods.php` — kind dial read before intersect, `VIDEO_ARG_KEYS` (13 keys in GenerateVideo inputSchema order), `MediaJob::new($kind)`, consent per-kind tool name, production factory picks the kind's tool with the store seam; image leg byte-identical.
- `candy-code/src/Protocol/Schema/MethodSchemas.php` — `generate.submit` region only: `kind` enum + 7 video keys.
- `candy-code/src/Tools/BuiltIn/GenerateVideo.php` — promoted `?string $storeSession` (9th ctor param), threaded through all four with-carriers, new `withStoreSession()` mirroring GenerateImage; collect() prefers it over engine session.
- `candy-code/tests/Protocol/GenerateVideoSubmitTest.php` — **NEW** (5T/33A): video submit settles video ledger + store mp4 + exact forwarded keys; consent consults the video tool name (image-Allow gate refuses video, admits image — discriminating); videoless launch (config mediaKinds:[image]) fails in-fork with 'do not include video', zero dials, zero artifacts; image submit keeps pre-step wire shape (stray video key dropped, job.kind image); non-enum kind refused at the schema door.
- `candy-code/tests/Protocol/MediaMethodsTest.php` — one-vocabulary pin renamed `testTheToolSchemasAndTheRpcParamsShareOneVocabulary` + widened (see deviations).
- `candy-code/docs/MEDIA.md` — :174 "video is TUI-side only in v1" row flipped to kind semantics.
- `candy-code-web/src-web/stores/generate.ts` — docblock truth-flip (kind IS a wire dial); submit pass-through, `MethodParams` auto-widens from regen.
- `candy-code-web/src-web/components/panels/GeneratePanel.vue` — Image/Video tabs (`generate-tabs`/`generate-tab-image`/`generate-tab-video`, aria-selected), offered = image||video, auto-correct watch, video submit dials `kind:'video'` (no batch_size), image submit unchanged byte-shape.
- `candy-code-web/src-web/components/panels/__tests__/generatePanel.spec.ts` — tab-gating + kind:video submit + MediaCard video badge + image-shape-stable + video-only-launch pins (14T).

## Protocol procedure (per §1)
Regenerated trio (`gen-protocol-schema --write` + `npm run gen:protocol`), ran guards against it, then RESTORED schema.json/SERVER.md/generated.ts before committing — tree at tip carries the committed (old) trio; drift is welder-judged on the welded tree. dist/ untouched.

## Gates (all green, under regenerated trio, plain pipe, cwd noted)
- phpunit candy-code, 7 files (new test + MediaMethodsTest + ProtocolSchemaDriftTest + ServerProtocolDocumentationDriftTest + HelloMediaFlagsTest + GenerateVideoTest + BuiltIn/GenerateImageTest): `OK (76 tests, 726 assertions)` 3.99s.
- vitest --no-file-parallelism 6 files (generated.spec, protocol.spec, connectionMedia.spec, generatePanel.spec, media.spec, MediaCard.spec): `6 files / 63 tests passed` 3.49s.
- `npx vue-tsc --noEmit`: rc 0, zero errors.
- drift guards GlobDialectDifferentialTest + DuplicatedTestHelperDriftTest: `OK (27 tests, 560 assertions)`.

## For the gate/welder
- NEW test file `candy-code/tests/Protocol/GenerateVideoSubmitTest.php` (5T) → needs durations.tsv row + suite-figure live-enum +5T re-pin; MediaMethodsTest rename changes no count.
- Author/committer verified Joe Huss <detain@interserver.net> (%ae/%ce separate greps =1), zero trailers; single commit 06463ea76; never pushed.

## Deviations
1. `MediaMethodsTest` is not in the brief's file list but its one-vocabulary equality pin breaks on any widening — renamed + widened in-step (image equality kept byte-true; added video-equality + video-extra-keys legs). In-step guard law; reviewer should verify the four legs.
2. `App.vue:103` still gates the generate *button* on `canGenerateImage` only — a video-only server cannot open the panel (offered-fix inside the panel is shipped; the button is out of declared files). Seam for weld/owner glance.
3. Spec capability test 2 flipped `{image:false,video:true}` → `{image:false,video:false}`: video:true now OFFERS the form — that reversal IS the contract this step delivers; the not-enabled line is re-pinned with both flags off.
4. Consent-proof leg reuses the video stub factory for the admitted image submit (only jobId + tally asserted — the pin's subject is the gate's tool name, not the stub).
5. The garbage-kind pin asserts the schema-door refusal (`InvalidParams`, `data.errors` names kind); the handler's `MediaKind::tryFrom` guard is defense-in-depth, unreachable over the wire by construction.
