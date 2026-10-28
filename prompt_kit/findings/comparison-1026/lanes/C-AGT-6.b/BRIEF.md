# C-AGT-6.b — `/review` ranges (`A..B`, `A...B`, `@{u}`)
- area: {st.get('area','')} | kind: {st.get('kind','')} | item: {st.get('item','')}
- deps: {st.get('deps','')}
- serialized: {st.get('serialized','')}
- ruling: {st.get('ruling','')}
- risks: {st.get('risks','')}

## Files — create
none

## Files — modify
`candy-code/src/Attachments/ContextMentions.php` (`diff`, `isDiffRef`), `candy-code/src/Commands/ReviewCommand.php` (`refError`), `candy-code/lang/en.php` (`cmd.review.hint`, `cmd.review.bad-ref`), `candy-code/docs/COMMANDS.md` (regenerated if the hint changes)

## Tests
modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@diff:base..HEAD` and `@diff:base...HEAD` attach the right patch in a temp repo), `candy-code/tests/Chat/ReviewCommandTest.php` (the 2.a refusal arms for ranges flip back to "attached")

## Docs/guards
`candy-code/tests/Commands/CommandDocsDriftTest.php` (hint)

## Approach
`ContextMentions::diff()` (:234) learns ranges, through the same bounded `GitRunner` and safety flags:
- `A..B` → `git diff --no-ext-diff --no-textconv --no-color -M --patch A B`;
- `A...B` → `git diff --merge-base A B`, falling back to `merge-base` + `diff` on an old git.
- `isDiffRef()` and `ReviewCommand::refError()` re-admit `..`/`...` only in those two shapes. `refError()` pre-resolves each side.
- `cmd.review.hint` and `bad-ref` list the accepted forms again.
- Untracked files stay out, as documented.