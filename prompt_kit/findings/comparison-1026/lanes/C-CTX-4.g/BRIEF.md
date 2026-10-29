### C-CTX-4.g — Headless `--append-system-prompt`
- item: C-CTX-4 (sources: GAP-PRM-09)
- kind: gap · effort S
- approach:
  - New flag `--append-system-prompt <text|@file>`, valid with `-p` only (refused on a TUI launch, like the AUT-4-P1 lesson).
  - The text is spliced as one `<user-rules>`-fenced section at the end of the system prompt: never an override, escaped like every fence, bounded at `MAX_DOCUMENT_BYTES`.
- files.create: `candy-code/tests/Cli/AppendSystemPromptTest.php`
- files.modify: `candy-code/src/Cli/ArgvParser.php`, `candy-code/src/Cli/ParsedArgs.php`, `candy-code/src/Cli/NonInteractive.php`, `candy-code/bin/candy-code`, `candy-code/src/Runtime.php` (`systemPromptSections()`), `candy-code/README.md` (CLI table), `candy-code/docs/PROMPT_ENGINEERING.md` (the doctrine sentence that says there is deliberately no flag)
- tests: AppendSystemPromptTest: `-p` splice is fenced; a TUI launch is refused; `@file` read is capped; modify `candy-code/tests/Cli/ArgvParserTest.php`
- docs/guards: `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheSubcommandFenceNamesEveryVerbTheParserAccepts` (flag roster); `candy-code/tests/Config/ReadmeJsonErrorContractDriftTest.php`
- serialized: [headless] [runtime:systemPromptSections] [docs:README.md] [docs:PROMPT_ENGINEERING.md]
- deps: none
- phase: normal
- ruling: May `-p` accept `--append-system-prompt` (fenced, never an override) against PROMPT_ENGINEERING.md's documented no-append doctrine?
- risks: none once ruled.

