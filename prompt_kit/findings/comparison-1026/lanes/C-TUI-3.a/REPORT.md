# C-TUI-3.a — `/copy [n]` in the TUI (W26, gap, effort M)

Status: IN PROGRESS (research closed, implementation starting).

## Ground found by research (at cut base cb2601f5d)

- Drag-copy precedent lives at `src/Chat.php:6568-6574` (design cited 6336-6343 — drift):
  `Cmd::batch($this->relayWidgetCmd(Cmd::setClipboard($text)), static fn() => SystemClipboard::copy($text))`.
- Host-side `ui_only` refusal is automatic: `SessionHost::dispatchCommand()` maps a spec with
  no `withHostCommand()` to `CommandResult::clientOnly()` (`src/Host/SessionHost.php:1840-1842`,
  `src/Host/Commands/CommandResult.php:102`). No Host file edits needed.
- Registration = spec file `builtin-commands/1910-copy.php` + private `Chat::handleCopyCommand()`
  named by `withHandler()`. `CommandArguments` defaults to `Text`, so `/copy 3` dispatches.
- README roster + COMMANDS.md table are GENERATED (`tools/gen-command-docs.php --write`,
  fences `<!-- commands:roster:begin/end -->`). Subcommands fence pins `ParsedArgs::SUBCOMMANDS`
  (CLI verbs) — untouched by a slash row.
- `docs/ARCHITECTURE.md:155` "dispatch arms for 64" is LIVE-COUNTED by
  `DocFigureProseDriftTest:713-718` vs `CommandRegistry::slashCommands()`. Base count verified
  64; my row makes 65 → ARCHITECTURE.md numeral flips in-step (declared-region widening forced
  by the §1.8 in-step guard law; disclosed).
- `lang/en.php` sorted slots: `cmd.copy.*` between `cmd.context.*` and `cmd.custom.*`;
  `chat.copy.*` between `chat.compact.*` and `chat.docs.*`. Only en.php ships (no locale parity
  duty — C-TUI-6.h not landed).
- READ_ONLY_COMMANDS pin mirror: `HooksCommandTest::testHooksIsAReadOnlySafeCommand` shape
  (ReflectionClass(Chat)->getReflectionConstant + assertContains).
- `Message::notice()` is uiOnly by construction (`src/Message.php:331-334`).
- Handler return shape `[Chat, ?\Closure]`; editor precedent clears its own draft
  (`withInputBuf('')`).

## Commit ledger
(none yet)
