# BRIEF — C-WEB-3.f: Video tab in the Generate panel (`generate.submit` kind)
wave ? | phase normal | area web | item C-WEB-3 (sources: k3-dorm#4, k3-sum (Generate form image-only)) | kind dormant-wire · M
deps: none
serialized: [protocol] [hot:candy-code-web/src-web/components/panels/GeneratePanel.vue] [docs:MEDIA.md]
ruling: none
source/design: 

## Design block (verbatim fields)
### item
C-WEB-3 (sources: k3-dorm#4, k3-sum (Generate form image-only))
### approach

- **Gap.** `canGenerateVideo` (`candy-code-web/src-web/stores/connection.ts:91`) is read by nothing. `GeneratePanel.vue:34` gates on image only. `generate.submit` (`candy-code/src/Protocol/Methods/MediaMethods.php:166-232`) always runs `GenerateImage` with `MediaJob::new(MediaKind::Image)`.
- **Server.** `generate.submit` gains `kind: image|video` (default image) and the video fields `GenerateVideo` takes (`motion`, `cfg2`, frames/fps as its schema names them). The handler forwards each kind's own key set.
- **Video path.** A video job runs `GenerateVideo::new()->withStoreSession($sessionId)` — a new seam mirroring `GenerateImage` — inside the existing forked `$run` and consent gate.
- **Client.** The panel shows Image and Video tabs gated on the two capability flags. Video artifacts render through MediaCard's video kind.
### files
['candy-code-web/src-web/components/panels/GeneratePanel.vue', 'candy-code-web/src-web/components/panels/__tests__/generatePanel.spec.ts', 'candy-code-web/src-web/stores/generate.ts', 'candy-code/docs/MEDIA.md', 'candy-code/docs/SERVER.md', 'candy-code/src/Protocol/Methods/MediaMethods.php', 'candy-code/src/Protocol/Schema/MethodSchemas.php', 'candy-code/src/Tools/BuiltIn/GenerateVideo.php', 'candy-code/tests/Protocol/GenerateVideoSubmitTest.php', 'candy-code/tests/Server/HelloMediaFlagsTest.php']
### files.create
`candy-code/tests/Protocol/GenerateVideoSubmitTest.php`
### files.modify
`candy-code/src/Protocol/Methods/MediaMethods.php` (`submit`), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`generate.submit`), `candy-code/src/Tools/BuiltIn/GenerateVideo.php` (`withStoreSession`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/stores/generate.ts` (`submit` kind), `candy-code-web/src-web/components/panels/GeneratePanel.vue` (tabs), `candy-code/docs/MEDIA.md` (:173, "video is TUI-side only in v1")
### tests

- Create `candy-code/tests/Protocol/GenerateVideoSubmitTest.php`. It pins:
- `kind: video` writes a video ledger and stores into the session store;
- a server without `mediaKinds` video refuses;
- an image submit is unchanged.
- Modify `candy-code-web/src-web/components/panels/__tests__/generatePanel.spec.ts`: the video tab appears only with `canGenerateVideo` and submits `kind: video`.
### docs/guards
the protocol gates; `docs/MEDIA.md`; `candy-code/tests/Server/HelloMediaFlagsTest.php` stays green; no new fork site (rides `TurnController::forkPayload`), so no child-lifetime row.
### risks
P-IMAGE-MENU (pre-pick) may touch the same GeneratePanel. Also, video renders are minutes long inside the fork. Keep no total timeout (E646 spirit); only the existing poll budget applies.
