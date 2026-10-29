### C-WEB-2.b — Typed `/skill-name` on served sessions (shared rewrite)
- item: C-WEB-2 (sources: GAP-SKL-06, p1-miss: VIS-5 parity)
- kind: gap · M
- approach:
  - **Gap.** Only `Chat::submit()` rewrites `/name args` into the `$name` mention channel (`candy-code/src/Chat.php:9586`, `skillInvocationDraft()` :11318). Served sessions send it as prose.
  - **Lift.** Move the body into a pure `Skills\SkillSlashInvocation::draft(string $text, ?SkillRegistry $registry): ?string`. It keeps the built-in-spelling guard and the colon longest-first walk. `Chat::skillInvocationDraft()` becomes a one-line delegate, so the TUI is byte-identical.
  - **Serve.** `SessionHost::admit()` (:892-932) calls it after `$this->command($text)` returns null and before `admitPrompt()`, using `$this->workspace->skills`. Precedence matches the TUI: file commands, then built-ins, then skills.
- files.create: `candy-code/src/Skills/SkillSlashInvocation.php`, `candy-code/tests/Skills/SkillSlashInvocationTest.php`, `candy-code/tests/Host/ServedSkillSlashTest.php`
- files.modify: `candy-code/src/Chat.php` (`skillInvocationDraft` body → delegate), `candy-code/src/Host/SessionHost.php` (`admit`), `candy-code/docs/SKILLS.md` (§"`$name` in a prompt", ~:578-584: the rewrite runs on served sessions too)
- tests:
  - Create `candy-code/tests/Skills/SkillSlashInvocationTest.php`. Pure pins: a built-in spelling stays prose; `ns:sub` is walked longest-first; a disabled or non-invocable skill gives null.
  - Create `candy-code/tests/Host/ServedSkillSlashTest.php`: `session.send "/my-skill x"` attaches the skill and raises the `✨ Loaded skill:` notice.
  - The existing `candy-code/tests/Skills/SlashSkillInvocationTest.php` must stay green unedited (TUI parity).
- docs/guards: `docs/SKILLS.md`.
- serialized: [chat:skillInvocationDraft] [hot:candy-code/src/Host/SessionHost.php] [docs:SKILLS.md]
- deps: none
- phase: normal
- ruling: none
- risks: The refusal notice's skill half (`Chat.php:11409`) still reads the old method name in a docblock. Keep the delegate's name.

