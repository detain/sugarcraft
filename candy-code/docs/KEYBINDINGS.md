# Keyboard shortcuts

This page is the full key map of the interactive TUI, grouped by the part of the
screen that answers each key. The in-app reference (`?` on an empty input line,
or `/keys`) shows the same rows. A drift test checks that every row on the
reference is on this page, so when a key changes, both places change together.

Keys are listed the way you press them. Mouse gestures are listed too, but they
only work when your terminal reports mouse events.

## How to read the tables

- **Id** is the stable name of the binding. The `keybindings` setting uses it to
  remap a key; see [Rebinding keys](#rebinding-keys).
- **Keys** is the chord. `↑ / ↓` means either arrow, and `Enter / Space` means
  either key. A bare `any text` means the rest of the printable keys.
- `Esc Esc` and `Ctrl+X c` are two presses, one after the other. `Esc Esc` means
  twice, quickly; the description says so when the timing matters.
- A `(or k / j)` aside names the alternate for the same binding, in the same
  position as the label. In `↑ / ↓ (or k / j)`, `k` is the alternate for `↑` and
  `j` for `↓`.
- The same chord can mean different things in different sections, for example
  `Enter` sends in the chat box and opens a row in a list. The section names the
  part of the screen that owns the key while it is focused.
- The **Panes & windows** section applies when the pane shell started by
  `bin/candy-code` is running. It answers from whichever docked pane has focus.

## Rebinding keys

Put a `keybindings` object in one of your own user files: `~/.candy-code/settings.json`,
`~/.candy-code/config.json`, or the file named by `--config`. A `keybindings` object in a
project's `.candy-code/settings.json` is ignored, even in a trusted project (see
[SETTINGS.md](SETTINGS.md)). Each key names a binding id from the tables below, and its value is either a
replacement chord or `null`:

```json
{
  "keybindings": {
    "chat.palette": "Ctrl+Y",
    "chat.agents": null
  }
}
```

- A replacement chord **adds** a second way to press the binding. The shipped
  chord keeps working. Use `null` to remove a binding and its shipped chord.
- A replacement chord must be one pressable chord: `Ctrl+<key>`, `Alt+<key>`,
  `Shift+<key>`, a modifier with a named key such as `Ctrl+Space`, or a function
  key such as `F10`. Bare printable keys are refused, so a remap cannot take
  ordinary typing. Sequences such as `Esc Esc` are refused too.
- The binding being remapped must have a single chord to answer to. A row whose
  shipped keys are a sequence, such as `Esc Esc` or `Ctrl+X b`, cannot take a
  replacement chord.
- An id that is not in the tables is refused. Saving the settings shows the
  refusal. A value that is invalid when the app starts stops the launch, so a
  typo cannot silently drop a key.
- The setting applies at launch. Run `/keys reload` to re-read the saved file
  in a running session.

A `command:<name>` id binds a chord to a slash command instead of a built-in row.
The name is the command without its slash, and the chord follows the same rules:

```json
{
  "keybindings": {
    "command:compact": "Ctrl+Y"
  }
}
```

The name may use letters, digits, `-` and `_`, with `/` between segments. It may
open with one namespace and a colon: a bundle's name for one of its commands
(`reviewkit:review`), or `mcp:` for an MCP server's command. A second colon is
refused. A `command:` binding runs the command itself, so it does not stand in
for another row's chord.

## Key map by section


### Chat

| Id | Keys | What it does |
|---|---|---|
| `chat.send` | `Enter` | Send, or accept the highlighted `/` command |
| `chat.steer` | `Enter` | Mid-turn: steer the agent at its next step |
| `chat.queue` | `Tab` | Mid-turn: queue the draft for after this turn |
| `chat.newline` | `Alt+Enter` | Insert a newline instead of sending |
| `chat.slash-menu` | `↑ / ↓` | Move through the `/` command popup |
| `chat.slash-complete` | `Tab` | Complete the highlighted `/` command |
| `chat.mention-complete` | `Tab` | Complete the @file or $skill name at the cursor |
| `chat.recall` | `↑` | Walk back through past prompts (empty box) |
| `chat.recall-next` | `↓` | Walk forward again, then back to your draft |
| `chat.accept-suggestion` | `→` | Take the greyed suggestion (empty input box) |
| `chat.backspace` | `Backspace` | Delete the previous character |
| `chat.delete-forward` | `Delete` | Delete the character under the cursor |
| `chat.cursor` | `← / →` | Move the cursor one character |
| `chat.word-motion` | `Alt+← / Alt+→` | Move one word (or Ctrl+← / Ctrl+→) |
| `chat.line-ends` | `Home / End` | Jump to the first or last column |
| `chat.draft-rows` | `↑ / ↓` | Move between the rows of a multi-line draft |
| `chat.word-delete` | `Ctrl+W` | Delete the previous word (or Alt+Backspace) |
| `chat.word-delete-back` | `Ctrl+Backspace` | Delete the previous word |
| `chat.word-delete-forward` | `Ctrl+Delete` | Delete the word after the cursor |
| `chat.space` | `Ctrl+Space` | Insert a blank character (modifier ignored) |
| `chat.page` | `PgUp / PgDn` | Scroll the transcript by a screenful |
| `chat.palette` | `Ctrl+P` | Open the command palette |
| `chat.tool-output` | `Ctrl+O` | Expand or collapse focused tool output/thought |
| `chat.verbose-toggle` | `Alt+O` | Toggle verbose tool output for this session only |
| `chat.tool-row` | `Ctrl+F` | Move the transcript row cursor one row older |
| `chat.repaint` | `Ctrl+L` | Clear the screen and repaint the frame |
| `chat.paste-image` | `Ctrl+V` | Attach the clipboard image as an @ mention |
| `chat.session-picker` | `Ctrl+R` | Open the session picker |
| `chat.history-search` | `Ctrl+G` | Search past prompts — press again to cycle |
| `chat.agents` | `Ctrl+A` | List the active agents (runs /agents) |
| `chat.agent-cycle` | `Alt+A` | Cycle the primary agent this session runs as (between turns) |
| `chat.session-cycle` | `Ctrl+Tab` | Switch to the next session — even while one is streaming |
| `chat.session-cycle-prev` | `Ctrl+Shift+Tab` | Switch to the previous session — even while one is streaming |
| `chat.keys` | `?` | Show this reference (empty input box) |
| `chat.stop` | `Esc` | Stop the running tool, then the turn |
| `chat.cancel` | `Esc Esc` | Cancel the turn in flight — twice, quickly |
| `chat.message-picker` | `Esc Esc` | Open the message picker — twice, while idle |
| `chat.agents-strip` | `Alt+↓` | Focus the live agents strip |
| `chat.plan-mode` | `Alt+M` | Toggle plan mode (between turns) |
| `chat.voice` | `Alt+V` | Toggle a voice take (press again to transcribe) |
| `chat.quit` | `Ctrl+C` | Quit CandyCode |

### Panes & windows

| Id | Keys | What it does |
|---|---|---|
| `shell.pane-next` | `Tab` | Focus the next docked pane |
| `shell.pane-prev` | `Shift+Tab` | Focus the previous docked pane |
| `shell.menu` | `F10` | Open the menu bar |
| `shell.pane-chat` | `Esc` | Leave the pane, back to the chat |
| `shell.pane-palette` | `Enter` | Open the palette from a list pane (empty draft) |
| `shell.settings-open` | `Enter` | Open settings view from its pane (empty draft) |
| `shell.new-session` | `Ctrl+N` | Start a fresh session, keeping this one open |
| `shell.palette` | `Ctrl+K` | Open the command palette |
| `shell.skills` | `Ctrl+S` | Open the skill picker |
| `shell.settings` | `Ctrl+,` | Focus the settings pane; again to open the view |
| `shell.group-input` | `Ctrl+G` | Message every running agent at once |
| `sessions.toggle` | `Alt+S` | Show or hide the Sessions pane |

### Command palette

| Id | Keys | What it does |
|---|---|---|
| `palette.move` | `↑ / ↓` | Move the highlighted row |
| `palette.run` | `Enter` | Run the highlighted command |
| `palette.filter` | `any text` | Filter the list as you type |
| `palette.erase` | `Backspace` | Erase the last character of the filter |
| `palette.close` | `Esc` | Close the palette (or Ctrl+P) |

### Session picker

| Id | Keys | What it does |
|---|---|---|
| `picker.move` | `↑ / ↓` | Move the highlighted session (or k / j) |
| `picker.resume` | `Enter` | Resume the highlighted session |
| `picker.preview` | `Space` | Preview the last messages of the session |
| `picker.filter` | `/` | Filter the sessions as you type |
| `picker.rename` | `r` | Rename the session in place (or Ctrl+E) |
| `picker.delete` | `d` | Delete the session, pressed twice (or Ctrl+D) |
| `picker.delete-children` | `D` | Confirm a delete, its branch sessions too |
| `picker.pin` | `p` | Pin or unpin the session (or Ctrl+F) |
| `picker.fork` | `f` | Fork the session and switch to the copy |
| `picker.archive` | `x` | Archive the session |
| `picker.unarchive` | `u` | Bring an archived session back |
| `picker.archived` | `a` | Show or hide archived sessions |
| `picker.children` | `Tab` | Show or hide sub-agent sessions |
| `picker.branch` | `Ctrl+B` | Filter to the current git branch, or all |
| `picker.close` | `Esc` | Close the picker, or clear the filter first |

### Folder picker

| Id | Keys | What it does |
|---|---|---|
| `dirpicker.move` | `↑ / ↓` | Move the highlight |
| `dirpicker.enter` | `Enter` | Open the directory, or on ▶ start the session |
| `dirpicker.open` | `→` | Open the highlighted directory |
| `dirpicker.up` | `Backspace` | Go to the parent directory |
| `dirpicker.parent` | `←` | Go to the parent directory |
| `dirpicker.hidden` | `.` | Show or hide hidden (dot) directories |
| `dirpicker.path` | `/` | Type or paste a path to go to |
| `dirpicker.start` | `s` | Start the session in the directory on screen |
| `dirpicker.close` | `Esc` | Cancel — nothing starts |

### Permission prompt

| Id | Keys | What it does |
|---|---|---|
| `permission.once` | `y` | Allow this one call |
| `permission.always` | `a` | Allow calls like this one for the session |
| `permission.persist` | `p` | Allow calls like this one, and save the rule |
| `permission.edit` | `e` | Edit what `always` allows, then allow |
| `permission.deny` | `n` | Refuse the call (or Esc) |
| `permission.note` | `r` | Refuse with a note the agent reads |
| `permission.stop` | `x` | Refuse the call and stop the turn |
| `permission.rearm` | `Enter` | Make the answer keys live again |
| `permission.choice` | `1…6` | Pick that choice when the agent asks a question |

### Agent view

| Id | Keys | What it does |
|---|---|---|
| `agents.move` | `↑ / ↓` | Move the selection (or k / j) |
| `agents.peek` | `Enter` | Look at the selected agent (or Space) |
| `agents.attach` | `Enter` | Open that agent's transcript (in the peek) |
| `agents.slot` | `Alt+1…9` | Jump to that numbered dashboard row |
| `agents.back` | `Esc` | Drop the selection, then leave the view |
| `agents.quit` | `q` | Leave the view and any open agent transcript |
| `agents.cancel` | `c` | Cancel the selected agent — again to stop now |
| `agents.resume` | `r` | Resume the selected agent, or continue it |
| `agents.stop-all` | `s` | Stop every running agent |

### Agents strip

| Id | Keys | What it does |
|---|---|---|
| `strip.move` | `← / →` | Move along the strip (or ↑ / ↓) |
| `strip.open` | `Enter` | Open the focused agent's transcript |
| `strip.cancel` | `c` | Stop the focused agent |
| `strip.dismiss` | `x` | Dismiss if finished, else stop it |
| `strip.back` | `Esc` | Back to the input box (or Alt+↑) |

### Agent transcript

| Id | Keys | What it does |
|---|---|---|
| `agentview.back` | `Esc` | Back to the main transcript (or Alt+↑) |
| `agentview.send` | `Enter` | Send the draft to the agent on screen |
| `agentview.cancel` | `Ctrl+X c` | Cancel this agent — again to stop it now |
| `agentview.pause` | `Ctrl+X p` | Pause this agent, or let it go on |
| `agentview.stop-all` | `Ctrl+X s` | Stop every running agent |
| `agentview.open-session` | `Ctrl+X o` | Open this agent as a session |
| `agentview.background` | `Ctrl+X b` | Send this agent to the background |
| `agentview.interrupt` | `Ctrl+X i` | Send the draft now, skipping the step's rest |
| `agentview.next` | `Alt+N` | Open the next agent of the same batch |
| `agentview.prev` | `Alt+P` | Open the previous agent of the same batch |

### Skill picker

| Id | Keys | What it does |
|---|---|---|
| `skills.move` | `↑ / ↓` | Move the highlighted skill (or k / j) |
| `skills.select` | `Enter` | Enable the highlighted skill |
| `skills.close` | `Esc` | Dismiss the picker |

### Context pane

| Id | Keys | What it does |
|---|---|---|
| `contextPane.expand` | `Enter / Space` | Show the per-category rows |
| `contextPane.drill` | `Enter / Space` | Open the Messages sub-rows |
| `contextPane.back` | `Esc / ←` | Undrill, or collapse the box |

### Settings view

| Id | Keys | What it does |
|---|---|---|
| `settings.move` | `↑ / ↓` | Move between settings (or k / j) |
| `settings.category` | `← / →` | Switch category (or h / l) |
| `settings.search` | `/` | Search every category as you type |
| `settings.search-keep` | `Enter` | Stop typing the search, keep its matches |
| `settings.erase` | `Backspace` | Erase the last character of the search |
| `settings.close` | `Esc` | Clear the search, then close the view |
| `settings.edit` | `Enter` | Edit the highlighted setting |
| `settings.stage` | `Enter` | Stage the value being edited |
| `settings.cancel-edit` | `Esc` | Drop the value being edited |
| `settings.reset` | `r` | Stage a reset to the default |
| `settings.tier` | `t` | Switch the file a save writes |
| `settings.save` | `s` | Preview the save of what is staged |
| `settings.confirm` | `y` | Save the previewed changes (or Enter) |
| `settings.back` | `n` | Leave the preview unsaved (or Esc) |
| `settings.trust` | `y` | Confirm a project trust grant |
| `settings.discard` | `d` | Discard unsaved changes and close |
| `settings.details` | `i` | Details in the list's place (narrow view) |
| `settings.preview-scroll` | `↑ / ↓` | Scroll the save preview (or k / j) |
| `settings.open-file` | `e` | Open the target file or Files row in $EDITOR |
| `settings.export` | `x` | Export a settings profile to a file you name |
| `settings.import` | `p` | Import a settings profile as staged changes |
| `settings.profile-go` | `Enter` | Export or import at the profile path typed |

### Media form

| Id | Keys | What it does |
|---|---|---|
| `media.open_form` | `Ctrl+D` | Open the image generation form |

### Diff review

| Id | Keys | What it does |
|---|---|---|
| `diffreview.file-move` | `↑ / ↓` | Move between files (or k / j) |
| `diffreview.body-page` | `PgDn / PgUp` | Scroll the patch by one page |
| `diffreview.body-ends` | `Home / End` | Jump to the top of the patch, or the bottom |
| `diffreview.base-back` | `-` | Diff against one checkpoint further back |
| `diffreview.base-forward` | `+` | Move the base back toward HEAD (or =) |
| `diffreview.turn` | `t` | Toggle the this-turn-only view |
| `diffreview.close` | `Esc` | Close the review |

### Message picker

| Id | Keys | What it does |
|---|---|---|
| `msgpicker.move` | `↑ / ↓` | Move between prompts |
| `msgpicker.accept` | `Enter` | Rewind to the highlighted prompt |
| `msgpicker.close` | `Esc` | Close the picker |
| `msgpicker.erase` | `Backspace` | Delete one character of the filter |

### First-run wizard

| Id | Keys | What it does |
|---|---|---|
| `wizard.provider-move` | `← / →` | Move the highlighted provider (or h / l) |
| `wizard.provider-pick` | `Enter` | Continue with the highlighted provider |
| `wizard.skip` | `s` | Skip setup entirely and leave |
| `wizard.store-key` | `Enter` | Store the typed key and switch to the provider |
| `wizard.login-later` | `Tab` | Leave empty and run `candy-code login` later |
| `wizard.cancel` | `Esc` | Cancel the wizard |

### Menu bar

| Id | Keys | What it does |
|---|---|---|
| `menu.switch` | `← / →` | Switch menu (or h / l) |
| `menu.move` | `↑ / ↓` | Move the highlighted row (or k / j) |
| `menu.submenu` | `→ / ←` | Open the highlighted submenu, or close it again |
| `menu.run` | `Enter` | Run the row, or open its submenu (or o) |
| `menu.close` | `Esc` | Close the submenu, then the menu (or q) |

### Mouse

| Id | Keys | What it does |
|---|---|---|
| `mouse.wheel` | `Wheel` | Scroll the transcript, or the pane under it |
| `mouse.tab` | `Click tab` | Switch to that session |
| `mouse.pane` | `Click pane` | Open the pane menu (palette) |
| `mouse.tool-call` | `Click tool` | Expand or collapse that call's output |
| `mouse.side-row` | `Click side row` | Expand or collapse that Tools, Agents or Context pane row |
| `mouse.palette-row` | `Click row` | Run that palette row |
| `mouse.session-action` | `Click ✎ ★ ✕` | Rename, pin or delete the picker session |
| `mouse.agent` | `Click agent` | Open that agent's transcript (a Task line) |
| `mouse.context-meter` | `Click meter` | Dock or undock the Context pane (status-bar meter) |

## See also

- [COMMANDS.md](COMMANDS.md): every slash command, including `/keys` and `/keys reload`.
- [SETTINGS.md](SETTINGS.md): the `keybindings` row and its apply mode.
- [ACCESSIBILITY.md](ACCESSIBILITY.md): screen-reader and reduced-motion settings that change how keys are reported.
- [THEMES.md](THEMES.md): the other per-screen setting you are likely to change first.
