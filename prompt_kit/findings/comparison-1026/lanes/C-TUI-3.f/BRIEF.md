### C-TUI-3.f — Recap core (`RecapHostCommand`)
- item: C-TUI-3 (sources: GAP-UI-N4)
- kind: gap · effort S
- approach: `/btw` already does a tool-less side call on the title backend as an async effect, with a server row mapper (`src/Host/Commands/BtwHostCommand.php:44-111`, `CommandEffect::async($call, self::answerRowMessage(...))`, `SideQuestionAnsweredMsg`).
  - `RecapHostCommand` reuses that seam with a fixed prompt: "in one or two sentences, where did this session leave off and what is next". It goes over `TranscriptDigest`.
  - It needs no new Msg class, no Chat arm (the `SideQuestionAnsweredMsg` fold in `HandlesTurnCompletion.php:363` lands it) and no TitleService change.
  - The call is the same title-backend request, so no total timeout is added (E646).
  - `BtwHostCommand`'s `call()` is opened up to take a prompt, if it is private today.
- files.create: `candy-code/src/Host/Commands/RecapHostCommand.php`
- files.modify: `candy-code/src/Host/Commands/BtwHostCommand.php` (`call()`/`request()` take the framing prompt); `candy-code/lang/en.php` (`host.recap.*`)
- tests: create `candy-code/tests/Host/Commands/RecapHostCommandTest.php` (no title backend gives an error row; the async effect shape; the served row mapper)
- docs/guards: none (the command row lands in C-TUI-3.g)
- serialized: [lang:host.recap.]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

