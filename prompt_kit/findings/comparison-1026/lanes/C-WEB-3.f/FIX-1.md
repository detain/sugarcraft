# C-WEB-3.f FIX-1

## round 2 — real land (2026-10-28, fixer lane C-WEB-3.f round 2)

Prior-round note: a previous fixer claimed cure commit `a0141a99c` — that object does
NOT exist (lead-cleared phantom; branch was unmodified at 06463ea76 when this round
started). This round is disk-verified.

Seam-1 (video-only launch had no Generate-panel entry) — root cause located by disk
truth, not by the cure text's line cite:
- GeneratePanel.vue tab visibility was ALREADY correct at tip: Image tab `v-if`
  `connection.canGenerateImage` (:107), Video tab `v-if` `connection.canGenerateVideo`
  (:117), the both-off `offered` gate (:43), and the dead-tab watch (:52-59) — and the
  panel spec already pins video-only ("a video-only launch lands on the video tab with
  no dead image choice", generatePanel.spec.ts:290). The reviewer's "~:443-455" cite
  matches no line of the 257-line file. No gratuitous panel edit was made.
- The actual missing entry was the TOP-BAR BUTTON: App.vue:103 gated on
  `canGenerateImage` only, and topbarGenerate.spec.ts pinned that defect
  ("is hidden when the hello explicitly refuses image generation" with
  media {image:false, video:true} asserted exists()===false).

Cure (amended onto the lane commit, single-commit branch kept):
- candy-code-web/src-web/App.vue:103 — gate widened to
  `connection.canGenerateImage || connection.canGenerateVideo` (the panel's own
  offered-idiom; when the media block is absent both flags are false, so the
  no-media pin stays green).
- candy-code-web/src-web/components/__tests__/topbarGenerate.spec.ts — docblock
  truth-flip; in-step polarity flip of the defect-pinning test to
  "appears when the server refuses image generation but can generate video"
  (asserts exists()===true); NEW pin "shows the video tab entry for
  video-only capabilities" (lead's requested test, file naming idiom) proving the
  full seam-1 chain: video-only hello → button visible → click opens the generate
  panel through the panels store.
- Nothing else touched: dictation/voice hunks, GeneratePanel.vue, stores, docs,
  protocol trio, dist, suite-figure.json, durations.tsv all byte-identical.

New tip: 6174f871f8682218782e0b88fa66c52f417c4c39 (amended from
06463ea765212deacb10ffd29cc1769502b6c7fb; message kept + "(round 2: video-only
tab visibility cure)" line). 11 files, 626+/52−. Author/committer
Joe Huss <detain@interserver.net> verified via separate %ae/%ce greps (1/1), zero
Co-Authored trailers. Porcelain 0.

Gates (real figures): vitest --no-file-parallelism on the two touched specs +
app.spec.ts = 24/24 green (the lead's "34/34" matched no file-count combination on
disk; the pair alone is 19, trio 24, builder's 6-file list was 63). Broadened media
sweep (+ media.spec + MediaCard.spec) = 51/51 green. Mutation: App.vue gate reverted
via /tmp cp harness → exactly the flipped pin + new pin red (2F/3P in the topbar
file), md5-verified restore.
