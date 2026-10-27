### C-AGT-2.a — `/review` refuses refs the scanner cannot attach
- item: C-AGT-2 (sources: GIT-1-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause: `ContextMentions::isDiffRef()` checks only `REF_PATTERN` (`src/Attachments/ContextMentions.php:93-96`). The scanner then strips trailing `}` (`trimmed()` :522-527, `TRAILING_PUNCTUATION` :79), and `diff()` runs `diff-index <ref>`, which takes ONE tree-ish (:234-245). `Chat::reviewTurn()` (`src/Chat.php:11784-11800`) submits on `isDiffRef` alone, so `base..HEAD`, `HEAD@{1}` and `@{u}` bill a review turn with no diff. Fix:
  - `trimmed()` keeps a trailing `}` while the token holds an unmatched `@{`, so `HEAD@{1}` and `@{u}` attach;
  - `isDiffRef()` becomes the scanner's full round trip (`trimmed('diff:'.$ref) === 'diff:'.$ref`) and refuses `..` (C-AGT-6.b re-admits ranges properly);
  - add a pure `ReviewCommand::refError(string $ref, string $root): ?string` that also pre-resolves with a bounded `git rev-parse --verify <ref>^{tree}` through `GitRunner`. `reviewTurn()` calls it and refuses loudly before submitting.
  - C-WEB-2's future `ReviewHostCommand` reuses this helper.
- files.create: none
- files.modify: `candy-code/src/Attachments/ContextMentions.php` (`isDiffRef`, `trimmed`), `candy-code/src/Commands/ReviewCommand.php` (`refError`, docblock :18-20), `candy-code/src/Chat.php` (`reviewTurn`), `candy-code/lang/en.php` (`cmd.review.bad-ref` drops the false `main..HEAD` example; `cmd.review.hint`)
- tests: modify `candy-code/tests/Chat/ReviewCommandTest.php` (flip :138 and the :98-102 `main..HEAD` submit arm to "no turn + bad-ref row"; new arms: `/review HEAD@{1}` attaches, `/review @{u}` in a temp repo with an upstream attaches, `/review base..HEAD` starts no turn), `candy-code/tests/Attachments/ContextMentionsTest.php` (`@diff:HEAD@{1}` attaches)
- docs/guards: none (the `/review` hint has no argument roster in the docs beyond `[<ref>]`)
- serialized: [chat:reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.review.*`]
- deps: none
- phase: normal
- ruling: none
- risks: until C-AGT-6.b lands, range forms are refused, never silently empty. The docs must not advertise ranges in between.

