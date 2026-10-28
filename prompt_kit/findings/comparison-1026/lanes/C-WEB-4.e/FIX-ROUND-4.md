# C-WEB-4.e FIX ROUND 4 — adjudication of the r3 step-local MINORs

Lane tip under repair: 47d19563f (parent 5a89b0b20, master-welded batch-6 step 2).
Contract re-verification was ordered ("these are the r3 reviewer's own cure recipes,
re-verify against code"). All three findings cite code that does not exist in this
lane's tree, in this step's brief, or anywhere in the repo at either lane tip, at
master, or in any in-flight sibling worktree. Verdict: 0/3 curable, 0 code changes.
This record carries the evidence so the next reviewer can close the loop.

Scope of C-WEB-4.e (per BRIEF): `session.send` `documents[]` — `TurnMethods::decodeDocuments`
(server), `MethodSchemas`, `paste.ts`, `Composer.vue`, `session.ts`, `SessionView.vue`,
`composerDocuments.spec.ts`, `SessionSendDocumentsTest.php`, protocol trio + SERVER.md.
The r3 findings name: `ContextSources.vue`, `sourcesWithDocuments`, `context_documents`,
`ServerContext.php:577`, `sessionSend.ts` — none is a C-WEB-4.e file.

## MINOR-1 "DEAD LEG: ContextSources.vue ~:176-180 / sourcesWithDocuments / ServerContext.php:577" — WRONG

- `candy-code-web/src-web/components/ContextSources.vue` does not exist (web has
  `components/panels/ContextPanel.vue` + `stores/contextSources.ts`).
- `git grep sourcesWithDocuments 47d19563f` → 0 hits; `git grep context_documents 47d19563f` →
  0 hits (same at `master`, at the pre-rebase tips b0ea554b4/76527aec1, and by grep across
  every /home/sites/cc-cmp-* worktree).
- `candy-code/src/Protocol/ServerContext.php` is 510 lines — `:577` is past EOF, and the file
  carries no documents/snapshot key at all.
- The surface the reviewer described is the WEB-3a/C-CTX context-sources family: the real
  `ContextSources` type (generated.ts:57-64) has `instructionFiles: ({path: string; bytes:
  number; tier: …} | null)[]`, and the named-but-nonexistent "{bytes, name}" entry shape is a
  garble of exactly that. Even there the alleged dead leg is already driven:
  `components/panels/__tests__/contextPanel.spec.ts:131-137` answers `context.sources` with
  `instructionFiles: null` and asserts the "not measured" fallback — so the finding is false
  on its own terms, in the other family's file. If the orchestrator wants that leg re-checked,
  it belongs to a C-CTX/WEB-3a step, not to C-WEB-4.e (plan §1: "A step-loop fix stays inside
  the step's declared files").

## MINOR-2 "the wire key 'documents' … rides no generated constant and no test; grep 'documents_with_command' hits no pin" — WRONG

- `sessionSend.ts` does not exist; the send body is `stores/session.ts:164-196`
  (`...(documents.length > 0 ? { documents } : {})` at :194).
- The key name and element shape ride the GENERATED params type: `connection.request<M>` is
  `request<M extends MethodName>(method: M, params: MethodParams<M>)`
  (`stores/connection.ts:222`), and `generated.ts` types `session.send` params as
  `documents?: { name?: string; mime: "application/pdf"; data: string }[]`. Mutation proof M2:
  renaming `PastedDocument.data`→`bytes` in `paste.ts` reddens `vue-tsc --build --force` at
  `session.ts(191,47)` with TS2345 quoting that generated shape (+2 errors vs the 20-line base
  set; restore verified, delta empty again). A plaintext-bytes caller cannot typecheck.
- The pin the finding says is missing exists four times over:
  `candy-code/tests/Protocol/SessionSendDocumentsTest.php:146` (`assertSame
  'documents_while_busy'`) and `:161` (`assertSame 'documents_with_command'`) — the mirror of
  `tests/Server/SendImagesTest.php:142/:157`; plus the key name itself is driven over the real
  dispatcher in that file at :48/:70/:110/:122/:145/:161/:173; plus
  `tests/Config/ServerProtocolDocumentationDriftTest.php:118-119` rosters all four refusal
  names across docs↔handler. Mutation proof M1: respelling the handler's literal to
  `documents_with_commandX` reddens exactly 1 test (`Tests: 7 … Failures: 1`); restored
  byte-clean (md5 verified).
- Store-level leg: `stores/__tests__/session.spec.ts` pins `ui_only` (:123) and the
  `session.send` params literals (:125/:127/:135) but never pin images either (0 `images`
  hits in that spec) — the lane mirrors the sibling guard shape exactly, which is what the
  recipe asked for.

## MINOR-3 "sessionSend.documents typed {bytes:string;name:string}" — WRONG

- `paste.ts:157-161` is `interface PastedDocument { name: string; mime: PastedDocumentMime;
  data: string }` — "Strict base64 of the file's bytes, no data-URL prefix" (:159-160), and
  `PASTED_DOCUMENT_MIMES = ['application/pdf']` mirrors `TurnMethods::PASTED_DOCUMENT_MIMES`.
- That is the real wire shape: `MethodSchemas` declares `documents[]` as
  `{name ≤256?, mime: enum, data: MAX_DOCUMENT_B64_CHARS}` with required `['mime','data']`.
  There is no `size` field on the wire (size is enforced by the schema's `data` maxLength +
  the shared decoded budget), and no `bytes` field to mistake for plaintext.
- The claimed `{bytes: string; name: string}` type exists nowhere in the tree; "mirror images
  leg" is already literally true (`PastedImage` :17-24 and `PastedDocument` :157-161 are the
  same envelope).

## Gates re-run at this tip (all green, tree otherwise clean)

- cwd `candy-code-web`: `env -u LINES -u COLUMNS npx vitest run --no-file-parallelism
  contextSources composerDocuments` → 2 files / 14 tests passed.
- cwd `candy-code-web`: `… npx vitest run --no-file-parallelism session` → 7 files / 38 tests
  passed. (The contract's `wire/sessionSend` filter matches no spec — no such file exists.)
- cwd `candy-code`: `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter
  SessionSendDocumentsTest` → Tests: 7, Assertions: 29, OK (2 PHPUnit Deprecations are the
  pre-existing SendImagesTest family REVIEW-1 recorded, not lane-side).
- `npx vue-tsc --build --force` → 20-line sorted error set, delta vs
  `/tmp/opencode/vue-tsc-cweb4c-base-e14.txt` EMPTY; log
  `/tmp/opencode/vue-tsc-cweb4e-r4-tip.txt`, M2 re-check `_tip2.txt` byte-identical.

## Files touched by this round

- `prompt_kit/findings/comparison-1026/lanes/C-WEB-4.e/FIX-ROUND-4.md` (this record) — only.
  Committed under this distinct name because the builder's REPORT.md/REVIEW-*.md/WELD.md are
  untracked working files in the main checkout; a lane commit at `REPORT.md` would collide at
  pick time. Append the FIX ROUND 4 verdict there from this file if the orchestrator wants the
  single-report shape. No source, test, dist, `suite-figure.json`, or durations change; no
  behavior change; nothing to re-weld beyond this record.
