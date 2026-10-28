# C-WEB-4.e — PDF upload from the browser (session.send documents[])

STATUS: DONE — committed, all gates green on the final tree.

## Commit

- `b0ea554b4` on `cc/C-WEB-4.e` (worktree `/home/sites/cc-cmp-C-WEB-4.e`, base master `24d746501`, never pushed).
- Message: `C-WEB-4.e: PDF upload from the browser (session.send documents[])`
- 18 files changed, +859 / −69. Author + committer `detain@interserver.net` (`%ae`/`%ce` separate greps ==1), zero Co-Authored-By trailers.
- NOT committed (welder duty, verified zero diff): `candy-code/tests/Config/Support/suite-figure.json`, `scripts/parallel-tests-durations.tsv`.

## What landed

Server (`candy-code/`):
- `src/Protocol/Methods/TurnMethods.php` — `decodeDocuments()`: strict base64, `FileMentions::sniffDocument()` is the authority over the declared mime, `Attachment(AttachmentType::Document)`; `PASTED_DOCUMENT_MIMES`, `MAX_DOCUMENTS=2`, `MAX_ATTACHMENT_TOTAL_BYTES=11 MiB` (shared decoded budget with images), `MAX_DOCUMENT_B64_CHARS` derived; `send()` merges images+documents, shared-budget check, `documents_while_busy` (Conflict; interrupt admits) and `documents_with_command` guards mirroring the image laws; class docblock names both kinds + the CTX-2-P1 resend blast radius.
- `src/Protocol/Schema/MethodSchemas.php` — `session.send` gains `documents` (arrayOf {name?, mime enum, data}, MAX_DOCUMENTS).
- `docs/SERVER.md` — attachments paragraph under "Sending, queueing, steering, cancelling": four images / two PDFs, per-image 5 MiB / images 10 MiB, shared 11 MiB, sniff-is-authority, busy/command laws, CTX-2-P1 note (documents re-sent every later turn until that fix lands; @path route for bigger files).
- Protocol trio regenerated with repo generators (see below).
- Tests: NEW `tests/Protocol/SessionSendDocumentsTest.php` (7 methods: ride-the-turn, sniffed-bytes-authority incl. reflection-pinned `document_mime_mismatch`, count-and-budget, busy law, command law, images+docs ordered same prompt, session.get reload pin); `tests/Config/ServerProtocolDocumentationDriftTest.php` +1 arm re-deriving the paragraph's figures and a bidirectional eight-kind refusal roster from code.

Web (`candy-code-web/`):
- `src-web/lib/paste.ts` — `PASTED_DOCUMENT_MIMES`, `PastedDocument`, `MAX_PASTED_DOCUMENTS=2`, `readDroppedDocuments()`, picker halves `imagesFromFiles()`/`documentsFromFiles()`.
- `src-web/components/Composer.vue` — document strip (`document-chips`/`document-chip-remove`), Attach button + hidden `input[type=file] multiple accept="application/pdf,image/*"`, paste and drop feed both strips, `blockedReason` documents sentences (images-message precedence), draft-empty clears both, emit `send` gains 4th `documents` arg.
- `src-web/stores/session.ts` — `send(text, delivery, images, documents)`; builtin path mirrors `documents_with_command` throw; payload `...(documents.length>0?{documents}:{})`.
- `src-web/views/SessionView.vue` — 2-line `send` wrapper forwards `documents` (DEVIATION, disclosed: mechanical rider of the signature change; file unclaimed by live lanes).
- NEW `src-web/components/__tests__/composerDocuments.spec.ts` (11 tests).
- In-step flips: `components.spec.ts` 3 exact send-emit pins widened to the 4th slot (only such pins tree-wide, grep-confirmed).
- `src-web/protocol/generated.ts` + dist delta regenerated and committed per spawn override.

## Refusals vocabulary decision

Lowercase snake kinds mirroring the existing `images_*` wire vocabulary, English wire constants (en.php untouched — protocol refusals ride the wire, byte-convention of WEB-5b): `documents_not_a_list`, `document_not_base64`, `document_not_decodable`, `document_mime_mismatch`, `attachment_budget_exceeded`, `documents_while_busy`, `documents_with_command`. Schema-cap breaches (3rd document, oversized data, off-enum mime) refuse at ingress with -32602 from the generated schema.

## Gates (final tree, post-commit content)

- PHP targeted (cwd candy-code, `env -u LINES -u COLUMNS`, `-c phpunit.xml`, plain pipe): `vendor/bin/phpunit --filter 'SessionSendDocuments|SendImages|ServerProtocolDocumentationDrift|ProtocolSchemaDrift'` → OK: 27 tests, 421 assertions. 2 PHPUnit Deprecations pre-existing (bare `SendImagesTest` at base reproduces them — proven not-lane).
- vitest serial (`npx vitest run --no-file-parallelism`, batch: composerDocuments, composerDropAndChips, composerFilesImages, composerDictation, composerHistory, composerSuggestion, components, stores/session, lib/paste): Test Files 9 passed, Tests 107 passed. `sessionView.spec.ts` 2/2 passed.
- `npx vue-tsc --noEmit` rc=0. `npm run build` rc=0 (dist delta staged).
- Trio regen (deterministic, from final sources; welder re-derive must land byte-identical): cwd `candy-code`: `php scripts/gen-protocol-schema.php --write` rc=0; `php scripts/gen-protocol-schema.php --check` rc=0 ("up to date"); cwd `candy-code-web`: `npm run gen:protocol` rc=0. No hand-editing of trio files.

## Welder duties flagged

1. `scripts/parallel-tests-durations.tsv` — NEW row for `candy-code/tests/Protocol/SessionSendDocumentsTest.php`.
2. `candy-code/tests/Config/Support/suite-figure.json` + README pair re-pin: +8 tests / +? assertions from this lane (7 SessionSendDocumentsTest methods + 1 drift arm).
3. Protocol trio re-derive on merged tree (C-TOOL-3.e precedent) — my trio commit must land byte-identical.
4. dist rebuild rides the merged tree if other web lanes also touched src-web.

## Deviations

- `SessionView.vue` rider (not in files.modify; mechanical necessity of the session.ts signature change, 2 lines + import).
- `document_mime_mismatch` is latent-via-wire (schema enum is single-kind `application/pdf`); kept as defense-in-depth for `sniffDocument()` gaining kinds, pinned via reflection in the test.
- No per-doc `DOCUMENT_MAX_BYTES` (20 MiB) handler check: the wire cap (11 MiB shared, schema-b64 derived) supersedes it; the @-mention reader keeps the 20 MiB ceiling (comment says so).
- `MAX_ATTACHMENT_TOTAL_BYTES`=11 MiB is stricter than the 20 MiB per-doc file ceiling; 11 MiB decoded = 15,379,116-char b64 + 1 MiB text rides one 16 MiB frame for the LARGEST SINGLE doc (per-entry law, docblock wording corrected).
- `typelessPasteNotice` wording left images-only (pinned text, out of scope).
- Trio + dist committed per spawn override.
- PHPUnit targeted gate ran under the lane's linked vendor; figures cited from the final tree after the trio/build regen (post-commit content).

## FIX ROUND 1 (weld stop)

Commit 76527aec1 on cc/C-WEB-4.e (follow-up to b0ea554b4, never amended, never pushed):
cures the TS7053 at composerDocuments.spec.ts:146 per STOP-1 cure recipe. Chose the
declare-the-tuple leg: `type SendEmit = [text: string, delivery: Delivery, images:
PastedImage[], documents: PastedDocument[]]` mirrors Composer.vue's declared emit
signature, read via VTU's own `wrapper.emitted<SendEmit>('send')` generic. Root cause
is VTU's `emitted<T = unknown[]>` default (a leg index into `unknown` = index into
`{}`), NOT an untyped emit — Composer.vue untouched. Assertion lines byte-identical,
runtime meaning unchanged (documents still read as 4th emit arg).

Gates (real, this tree):
- `npx vue-tsc --build --force` rc=2 (pre-existing residual) — sorted error-line delta
  vs /tmp/opencode/vue-tsc-cweb4c-base-e14.txt: EMPTY (TS7053 gone, 20-line generated-chain
  residual byte-stayed). Log /tmp/opencode/vue-tsc-cweb4e-fix.txt.
- `npx vitest run --no-file-parallelism composerDocuments components` → 304 passed /
  0 failed (spec semantics untouched; house neighborhood re-run as collateral).
- git status: single spec file, no dist paths, no Composer.vue change → no rebuild owed.
Author law: %ae/%ce grep-c detain = 1/1, zero Co-Authored trailers.
