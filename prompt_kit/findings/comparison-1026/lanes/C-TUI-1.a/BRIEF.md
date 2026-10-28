# BRIEF — C-TUI-1.a: Vim text objects, `c` and counts in ComposerVim

- area: tui | src: design-tui.md | item: C-TUI-1 (sources: GAP-UI-07, k3-dorm#2, k3-sum §6.6 vim, p2-miss: TUI-3 counts/`cw`/text objects) | kind: dormant-wire · effort M | phase: normal | ruling: none
- deps: none
- serialized (claims): [lang:settings.vimMode.] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php (TUI-3A vim arms)]

## Files to create
none

## Files to modify

- `candy-code/src/Tui/ComposerVim.php`: state fields; `CLAIMED_RUNES`→`claimsKey()`; `resolve()`; `start()`; the "Deliberately NOT implemented" docblock.
- `candy-code/src/Chat/Handlers/HandlesKeys.php`, TUI-3A arm (b) ~L343: `pending !== null` → `isArmed()`.
- The same file, claim arm ~L1047-1055: `ComposerVim::claims()` → `$this->composerVim->claimsKey()`.
- `candy-code/lang/en.php`: `settings.vimMode.help`.

## Declared regions
{"candy-code/src/Tui/ComposerVim.php": ["?"], "candy-code/src/Chat/Handlers/HandlesKeys.php": ["?"], "candy-code/lang/en.php": ["?"]}

## Tests

- Create `candy-code/tests/Tui/ComposerVimTextObjectTest.php`: `ci"`, `da(`, `diw` and `yi{` on, before and outside pairs; the empty object; a beep cancels.
- Create `candy-code/tests/Tui/ComposerVimCountTest.php`: `3dd`; `2yy` then `p`; `d2w`; `3x`; `0` after a count vs bare `0` (line start); a count then Esc or Tab re-routes.
- Modify `candy-code/tests/Tui/ComposerVimTest.php`: the claims roster.
- Modify `candy-code/tests/Chat/ComposerVimModeTest.php`: digits are claimed only in NORMAL with no popup; `c` lands in INSERT; a forgotten count does not hold Tab.

## Docs/guards
the `settings.vimMode.help` text only. Vim keys are a mode, not KeyBindingRegistry rows, so README Keys does not move. LangParityTest.

## Approach (verbatim)

The sugar-forms text-object machinery is never called from the composer:
- `VimKeyHandler::handleTextObject()` (`sugar-forms/src/Vim/VimKeyHandler.php:289-304`)
- `TextObject::isTarget()`/`resolve()` (`sugar-forms/src/Vim/TextObject.php:62,78`)
- `VimOperator::fromKey()` (`sugar-forms/src/Vim/VimOperator.php:31`)
- `TextObjectScope::fromKey()` (`sugar-forms/src/Vim/TextObjectScope.php:27`)
- `VimAction::Delete/Change/YankTextObject` (`sugar-forms/src/Vim/VimAction.php:65-71`)
Correction to k3-dorm#2: `sugar-readline/src/Mode/ViMode.php:347-386` already consumes them; the composer path does not.
Extend ComposerVim's pending grammar:
- After `d`/`y`/`c`, an `i`/`a` arms a `pendingScope`. The target key resolves through `handleTextObject(op, scope, key, $input->lines[$input->row], $input->col)` into the existing `applySpan()`.
- `c` is the `d` kill, then Insert by feeding the widget `i` (the `openLine()` precedent). Also `cc`, `C`, and `cw` (≡ `ce`, vim's rule).
- A `count` field accumulates `1-9`, then `0-9`. It multiplies `dd/yy/dj/dk/x/p/P` and repeats delegated motions (`3w`, `d2w`).
- New `isArmed()`, plus an instance `claimsKey()` that claims digits, `c` and `C`, and claims `0` and the lib motions only while a count is armed.
sugar-forms is consumed unchanged in this step. What the user sees: with `vimMode` on, `ci"`, `da(`, `diw`, `yi{`, `cw`, `3dd` and `2yy` work in the draft box.

## Risks
`0` is both line-start and a count digit, so the claim must key on the armed count or `d0` breaks.
