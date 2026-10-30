# C-WEB-2.g — `/review` and `/security-review` host body — WORK IN PROGRESS

Branch cc/C-WEB-2.g @ aa5ddf638 (worktree /home/sites/cc-cmp-C-WEB-2.g).

## Probe anchors (verified on master tree)
- InitHostCommand shape: `src/Host/Commands/InitHostCommand.php` — readOnly refuse (`host.command.read_only`), `CommandResult::new(Message::user($text)->withUiOnly())->withEffect(CommandEffect::submitPrompt(...))`.
- `ReviewCommand::prompt(string $ref = '', bool $security = false, ?int $pr = null): string` — `src/Commands/ReviewCommand.php:117`.
- `ReviewCommand::refError(string $ref, string $root): ?string` — the C-AGT-2 validator (isDiffRef round trip + bounded `rev-parse --verify <side>^{tree}`, ranges side-by-side); answers `Lang::t('cmd.review.bad-ref', ['ref' => $ref])` (key at lang/en.php:1635).
- `ContextMentions::isDiffRef` at src/Attachments/ContextMentions.php:142. Bare `@diff` = working tree vs HEAD (staged+unstaged); `@diff:A..B` range since C-AGT-6.b.
- SessionHost dispatch: `dispatchCommand()` instantiates via `instantiateHostCommand()`; SubmitPrompt arm collects `$queuedPrompt` -> `$this->submit($queuedPrompt)` -> admitPrompt with resolveMentions=true (SubmitOptions::new default). CommandMethods.php:108-117 returns the refusal reason to the client; refused results are NOT applied (no turn billed).
- `CommandText::tokens($text)[0]` = normalized leading `/name` (colon spelling handled).
- Guard: `tests/Commands/BuiltInCommandsTest::testEveryHostCommandIsAHostCommandBuiltWithNoArguments` (a non-null hostCommand must instantiate with no args).
- `tests/Host/WebTwoHostCommandsTest.php` = the served-session test pattern (host(), drain(), reflection helpers).

## Design adherence notes
- `--pr <n>` form NOT carried by the host body (design block pins `prompt($ref, $security)`, 2 args): `/review --pr 5` on a served session refuses via refError (not a tree-ish). Seam reported, not creeped.
- One class distinguishes the spellings from the draft's command word (`/security-review` => security).

## BUILD CLOSED (commit dfdac7512367f92aca898e5d37ddea515d7793eb, branch cc/C-WEB-2.g)
- src/Host/Commands/ReviewHostCommand.php: readOnly refuse (Lang host.command.read_only naming the typed
  spelling) → ReviewCommand::refError(ref, projectRoot) [C-AGT-2] → refused cmd.review.bad-ref / else
  echo row + CommandEffect::submitPrompt(ReviewCommand::prompt($ref, $name==='/security-review')). InitHostCommand shape.
- builtin-commands 2840/2845: ->withHostCommand(ReviewHostCommand::class). No Lang key added (bad-ref exists).
- tests/Host/Commands/ReviewHostCommandTest.php: 10 tests / 61 assertions. Median 2.122s (runs 4.121/2.122/1.225 —
  durations.tsv row candidate: 10 tests, ~2.1s).
- Gates (worktree cwd=candy-code, plain pipe): new file OK 10/61; tests/Host/Commands dir OK 198/2496;
  --filter 'BuiltInCommands|Host\\Commands|WebTwoHostCommands|InitHostCommand|PrHostCommand' OK 232/3158 (2 deprecations
  = PRE-EXISTING AccessorDeprecationTest private-provider, not this lane); review/host-adjacent pin classes
  (FastCommand|GenerateCommand|OutputStyle|CommandMethodsMcpPrompts|AttachRemoteCommands|Conformance|NoRawAnsiInTranscript)
  OK 99/543; gen-protocol-schema --check: up to date (runsIn derives at runtime — zero schema drift, regen pair NOT needed).
- Mutation: stripping withHostCommand from 2840 reddens exactly 4 of 10 (spelling-row, both served /review, echo);
  restored md5-verified, green re-run.
- Seam disclosed: `/review --pr N` on serve refuses via the validator (bad-ref, no turn) — design pins the 2-arg
  prompt shape; --pr stays terminal-dispatch (noted in class docblock).
- Not touched: suite-figure.json, durations.tsv, dist, Chat/, web client. Branch never pushed.
