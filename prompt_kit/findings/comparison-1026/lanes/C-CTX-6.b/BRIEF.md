# BRIEF C-CTX-6.b — PDF page check and an aggregate document budget

area: prv-ctx  item: C-CTX-6 (sources: CTX-2-P1)  kind: fix · MINOR · effort M  wave:   serialized: [lang:chat.attach.*] [docs:MEDIA.md] [hot:candy-code/src/Backend/EngineBackend.php]
deps: none

## approach

- Root cause: PDFs have only a per-file 20 MiB cap (`FileMentions.php:58-64`). `AttachmentEncoding.php:87-95` re-encodes every earlier document on every request, so a 15 MB + 10 MB pair puts every later request over 32 MiB and each turn 400s or 413s.
- At attach time, `FileMentions` estimates pages (count `/Type /Page` objects) and refuses above 100 pages with a notice. Compressed object streams that hide the count are allowed through as unknown.
- `EngineBackend::typedUserMessage()` (:5961) keeps a cumulative base64 budget. Older document blocks over it are withheld and replaced by a one-line placeholder ("document <name> withheld; re-attach to resend"), with one notice per turn.

## files.create
`candy-code/tests/Backend/DocumentAggregateBudgetTest.php`

## files.modify
`candy-code/src/Attachments/FileMentions.php` (page estimate, refusal), `candy-code/src/Backend/EngineBackend.php` (`typedUserMessage()`)

## tests
create DocumentAggregateBudgetTest: with two PDFs the older one is withheld and the newest kept; modify `candy-code/tests/Attachments/FileMentionsTest.php`: a >100-page refusal, and unknown counts pass

## docs/guards
`candy-code/docs/MEDIA.md` (attachment limits); lang `chat.attach.pdf_pages`, `chat.attach.document_withheld`

## risks
the page heuristic is approximate. It refuses only on a positive count over 100.

## ruling
none
