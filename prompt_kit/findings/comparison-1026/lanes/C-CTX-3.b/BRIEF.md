### C-CTX-3.b — `#` quick-add to personal instructions (TUI)
- item: C-CTX-3 (sources: GAP-INS-16 (`#` quick-add))
- kind: gap · effort S
- approach:
  - A single-line submit starting with `#` followed by a non-`#` character becomes a quick-add, not a turn.
  - A uiOnly confirm row asks: Enter appends `- <text>` to personal `~/.candy-code/AGENTS.md` (created 0600 if absent, `ContainedPath`-checked), Esc cancels and restores the draft.
  - Multi-line input and `##…` are never captured, so pasted markdown headings still go to the model.
  - The note reaches the model via the tracker (C-CTX-1.a).
- files.create: `candy-code/tests/Chat/InstructionQuickAddTest.php`
- files.modify: `candy-code/src/Chat.php` (`submit()` prefix arm and confirm handling)
- tests: InstructionQuickAddTest: confirm appends; Esc writes nothing; multi-line and `##` pass through as prompts
- docs/guards: `candy-code/docs/MEMORY.md` (quick-add). No key binding, so `KeyBindingDriftTest` is untouched.
- serialized: [chat:submit,ctor,key dispatch] [lang:chat.instructions.quick_add*] [docs:MEMORY.md]
- deps: C-CTX-1.a
- phase: normal
- ruling: none
- risks: a user who types a one-line prompt starting with `#` meets the confirm. Esc restores the draft, so nothing is lost.

