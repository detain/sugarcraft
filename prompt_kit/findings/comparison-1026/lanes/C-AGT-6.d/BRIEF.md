# BRIEF — C-AGT-6.d
## `/pr` canned turn + `/review --pr <n>`
 
wave 15 (plan §3) | area agt | kind gap · effort M | effort  | phase normal
deps: C-AGT-6.b
serialized: [chat:handlePrCommand (new), reviewTurn] [hot:candy-code/src/Attachments/ContextMentions.php] [lang: `cmd.pr.*`, `cmd.review.*`] [docs:COMMANDS.md] [docs:README.md]. `builtin-commands/2860-pr.php` is a new per-command file.
ruling: R13 (remote security). May candy-code shell out to `gh` (the user's GitHub credentials) for `/review --pr` and drive `gh pr create` from `/pr`?

## Item
C-AGT-6 (sources: GAP-GIT-04, p2-miss: GIT-10 review scope parity (`--pr`))
## Approach

- `/pr [focus]` is a canned turn (a `PrCommand::PROMPT` const, shaped like `ReviewCommand`) telling the agent to push the branch and open a PR with `gh pr create`, honouring `attribution.pr`. Its host body `PrHostCommand` returns `submitPrompt`.
- `/review --pr <n>` closes on a new `@pr:<n>` mention that `ContextMentions` resolves through a new `Workspace\GhRunner`: a bounded `gh pr diff <n> --color never` on the `CapturesProcessOutput::runCaptured` path GitRunner already uses, so no new `proc_open` site. A missing or unauthenticated `gh` becomes a notice.
- Gated on the R13 ruling.
## Files to create
- `candy-code/builtin-commands/2860-pr.php`
- `candy-code/src/Commands/PrCommand.php`
- `candy-code/src/Host/Commands/PrHostCommand.php`
- `candy-code/src/Workspace/GhRunner.php`
## Files to modify
- `candy-code/src/Attachments/ContextMentions.php` (`@pr:` keyword)
- `candy-code/src/Chat.php` (new `handlePrCommand`; `reviewTurn` `--pr` parse)
- `candy-code/src/Commands/ReviewCommand.php` (`prompt` with a PR mention)
- `candy-code/lang/en.php` (`cmd.pr.*`
- `cmd.review.hint`)
- `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated)
## Tests
create `candy-code/tests/Host/Commands/PrHostCommandTest.php`, `candy-code/tests/Workspace/GhRunnerTest.php` (a fake `gh` on PATH: diff captured and bounded; absent `gh` gives a notice); modify `candy-code/tests/Attachments/ContextMentionsTest.php` (`@pr:12`)
## Docs/guards
`php tools/gen-command-docs.php --write` + `CommandDocsDriftTest`, `ReadmeRosterDriftTest`. If the lane must add a fresh spawn instead of `runCaptured`, it goes through `ProcessContainment::spawnSpec` with a `tools/check-child-lifetimes.php` roster row.
## Risks
`gh` inherits the user's token. The runner passes a scrubbed env except `GH_*`/`GITHUB_TOKEN` and never passes model text as argv flags.