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

## The fine print

The long claims behind the rows of the key tables, in full. The [project README](../README.md#keys) Keys table points here for each row that says *see the fine print*.

**`?` on a blank draft.** Show the in-app keyboard reference. Blank means `trim()`-empty, i.e. empty or made only of the six bytes `trim()` strips — space, tab, newline, carriage return, `NUL` and vertical tab. Not the same as "whitespace-only", in both directions: a line of non-breaking spaces (`U+00A0`), ideographic spaces (`U+3000`) or a form feed is *not* blank, so `?` types a character there, while `NUL` **is** blank without being whitespace. Only one of those six bytes can be typed — space (the input decoder drops `NUL`, so `Ctrl`+`Space` types nothing). A newline draft is *composed*, with `Alt`+`Enter` (or `Shift`+`Enter` / `Ctrl`+`Enter`, on terminals that report those distinguishably). The other four (`NUL`, tab, carriage return, vertical tab) have no key at all, and no route you can exercise on purpose: sending a message never puts one in your history either, because `Enter` trims the draft before it is sent. They reach the input box exactly one way, and not in the shipped binary — **`/rewind` to a checkpoint whose transcript contains a tool result, in a `Chat` embedded without a prompt-history file.** Restoring a checkpoint revives every non-`assistant` row as a `user` message with its content unchanged, and a tool row's output is full of tabs; with no prompt file wired, `↑` recalls from the transcript's user rows and so returns that revived message verbatim. `bin/candy-code` always wires `~/.candy-code/prompt_history.jsonl`, whose entries are prompts you typed and sent (trimmed), so there `↑` never produces such a draft. Same for the form feed in the non-blank list above — the chord a legacy terminal sends as `U+000C` is claimed by `Ctrl`+`L` below (registry row `chat.repaint`), so it types neither the letter `l` nor a form feed; and no dropped or delegated ctrl chord types its letter at all — `Ctrl`+`E`/`U`/`K`/`X` edit the draft readline-style, the rest type nothing. The draft is left untouched behind the overlay. `Esc`/`Enter`/`q` close it, and so does a second `?` (see *Two `?` make one letter* below); `↑`/`↓`, `PgUp`/`PgDn` and the wheel scroll it (and the transcript behind it is left alone)

**Two `?` make one letter.** Type a literal `?`. The second `?` closes the reference **and** puts the character in the input box, which is how a message that starts with `?` gets typed. The box does have cursor movement — one character on `←`/`→` (registry row `chat.cursor`), the line ends on `Home`/`End` (`chat.line-ends`), whole words on `Alt`+`←`/`Alt`+`→` (`chat.word-motion`) — but no motion helps on a blank line: the first `?` is claimed by the overlay before any character reaches the draft, wherever the cursor would be. Works after leading whitespace too: `␣??` leaves `␣?` (here and in the `?` rows, `␣` stands for a literal space)

**`/keys` is not an escape hatch.** The same reference, by **name**: typing `/k` surfaces it in the `/` popup, which is where you find it if you do not already know about `?`. (`/help` was a second spelling of this and is now the **slash-command list** instead.) It is *not* an escape hatch for a half-typed draft — the command is matched against the whole trimmed input, so with `why` already in the box, `why/keys` + `Enter` is sent to the model as a prompt. Typing `/keys` onto a draft opens the reference exactly when `?` on that draft would — which is the sense in which it is not a hatch. It is *not* interchangeable with `?` more generally: a draft that **is** the command modulo surrounding whitespace (`␣/keys`, `/keys␣`) opens the reference on `Enter`, where `?` would type a character, and on a blank line `?` opens it while `Enter` sends nothing. Submitting `/keys` also clears the input line and `?` does not. Clear the line and either route works

**Steering, and `queueMode`.** **Steer** the running turn: the agent reads the message at its next step, and the step's tool calls that have not started yet are skipped (`Skipped to process an incoming message.`) so it reads it before doing more. If the turn ends before its next step, the message is sent as the next prompt instead — never lost. The `queueMode` setting changes what Enter does here: `followup` queues it like `Tab`, `interrupt` stops the turn at its next step (and the call running now) and then sends it

**The settings view.** Focus the Settings pane; press it again there (or `Enter` on an empty draft, or type `/settings` — alias `/config` — or pick **View settings** from `Ctrl+P` or the `F10` App menu) to open the **settings view**: every setting, by category, with the value this launch runs with, where it came from (default, a project file, your `settings.json` or `config.json`, the environment, a flag), whether an environment variable or flag locks it, and when a change would apply (live, next turn, restart, next launch). `/` searches every category (`/settings compaction` opens with the search filled in), `←`/`→` switch category, `↑`/`↓` move, and `Esc` clears the search, then closes the view. The last tab, **Files**, lists the settings files and whether this launch reads them — including a project's own `.candy-code/config.json`, which is *not* a settings layer. It also edits: `Enter` opens the highlighted setting in a field (`Enter` stages the value, `Esc` drops it), `r` stages a reset to the default, `t` cycles the file a save writes — **You** (your `config.json`), **This project (local)**, **This project (shared)** (the project's committed `.candy-code/settings.json`; the preview warns it is committed) and **This session only** (memory, until exit) — `i` swaps the list for the highlighted setting's details in a narrow terminal, `s` shows the save preview — the target file, a diff of it and when each change applies — `↑`/`↓` scroll a long one, and `y` (or `Enter`) writes it, `n` (or `Esc`) goes back with everything still staged. `Enter` on one of the `trustedProject*` lists asks whether to trust this project for it and `y` adds the project to that list in your `config.json` (from the next launch). `Esc` with changes staged asks first: `d` discards them, `k` keeps editing, `s` previews the save; `d` on the list arms that same discard question — a second `d` discards, and with nothing staged it closes directly. `e` opens the file the chosen tier saves to (or, on the **Files** tab, the highlighted file) in `$VISUAL`/`$EDITOR` and re-reads it when the editor exits, and `x` / `p` export or import a settings profile (a path prompt; an import only stages). `permissionRules` is edited there too, as one JSON list the launch's own parser must accept whole. Nothing is written without that preview, so the view opens mid-turn too (except as a typed `/settings`, refused mid-turn like every slash command but `/btw`). Many terminals cannot send `Ctrl+,`; the slash command, the palette row and the menu row always work

**`Esc` against a running turn.** On an engine turn that reports its steps: stop the running tool, then the turn after the current step. The running call is cancelled, not waited for: a parallel member's process is killed, and a lone `Task` stops at its sub-agent's next tool or step and stays resumable. A sequential tool such as `Bash` still runs to its end. The cancelled call reads `Cancelled by the user (Esc) while it was running.` and the turn's other results are kept. The status bar then reads `stopping after step N · Esc to cancel now`, and any later `Esc` cancels hard

**The double-`Esc` window.** Cancel the in-flight turn — press **twice** within 0.6s (the `doubleEscSeconds` setting). On a turn that reports no steps a single `Esc` only arms the cancel, which is why the status bar reads `Esc Esc to cancel` while thinking. The same two presses, quickly, while **idle** — no turn in flight, an empty composer, no modal up — answer the other way: they open the **message picker** over the transcript, one row per turn you can rewind to (see [Checkpoints](../README.md#checkpoints-undo-redo-rewind-and-diff)). A press during the turn and a press after it ends pair with nothing: both presses must be idle

**The `/new` folder picker.** **Folder picker** for a new session, opened on this project with **▶ Start session here** highlighted (so `/new` `Enter` is a plain new session). `↑`/`↓` move, `Enter` or `→` opens the highlighted directory, `Backspace` or `←` goes to the parent (the directory you left stays highlighted; there is no upper limit — it is your machine), `.` shows hidden directories, `/` types or pastes a path (`Enter` goes, `Esc` stops typing), `s` (or `Enter` on **▶ Start session here**) chooses the directory on screen, `Esc` cancels. Directories only, with a `[project]` badge on those holding `.git`, `composer.json`, `package.json` or `.candy-code`; one you cannot read is refused rather than entered. Another directory asks first — *This restarts CandyCode there* — because one process serves one project: `y`/`Enter` restarts in it with your `--config`/`--model`/`--permission-mode` and a new session, `n`/`Esc` stays. `/new <dir>` skips the browsing. The palette has both **New session** (here, at once) and **New session…** (the picker), and so does the **Session** menu

**`Ctrl+O` and the row cursor.** Expand/collapse the tool output and thought at the row cursor — the newest by default. Press again within 3 s and each further press walks one transcript row older, cycling back to the newest when the transcript runs out; a burst that times out restarts at the newest. Once the cursor is settled (`Ctrl+F`, or a click on a Tools row) the chord answers exactly the focused row instead. With a palette, picker or overlay open the walk disarms and the key reaches the modal as before

**`Ctrl+F`, the keyboard half of a click.** Move the transcript row cursor one row older, cycling, WITHOUT toggling anything — the keyboard half of clicking a side row (`Ctrl+O` then expands exactly that focused row; the settled cursor tracks its row even when newer rows arrive above it, and gives up only when the row leaves the transcript). Inside the session picker's filter it keeps rename/delete/pin duties — the cursor only moves with no modal up

**`Ctrl+V`, the clipboard image.** Attach the **image** on the system clipboard (a screenshot): it is read through `pngpaste` (macOS), `wl-paste` (Wayland) or `xclip` (X11), saved to a private temp directory and inserted into the draft as an `@` mention, so it is attached when you send. Text pastes with your terminal's own paste key as before. No image (or no tool) leaves a notice and the draft untouched. See [Attachments](../README.md#attachments)

**The session picker in detail.** Session picker (persisted across turns; `/sessions <query>` opens it already filtered). Rows are grouped Pinned / Today / Yesterday / by date and show the title, when it was last used, its turns, provider/model and a badge (`⠋ live`, `⧗ bg`, `+N ag` sub-agents, `⑂` branch); the footer names where it was opened, its branch and the last prompt. With the picker up the wheel browses, a click selects, and `Enter` resumes; browsing onto the last loaded row fetches the next page. `/` filters (fuzzy, over title, last prompt, agent, id and branch — `k`/`j` keep moving outside it; `Enter` on a filter word that reads like a slash command is refused with a notice routing you back to the input, so a command is never mistaken for a filter), `Space` previews the last messages, `r` renames in place, `d` deletes after a second `d` (sub-agent sessions go with it, branches are kept unless you confirm with `D`), `p` pins, `f` forks and switches to the copy, `x` archives, `a` shows archived sessions and `u` brings one back, `Tab` shows sub-agent sessions under their parent, and `Ctrl+B` keeps the current git branch's sessions. Inside the filter `Ctrl+E` / `Ctrl+D` / `Ctrl+F` rename, delete and pin. The highlighted row also carries a clickable `✎ ★ ✕` (rename, pin, delete). The session on screen cannot be deleted or archived

**The generation form.** Open the image generation form: prompt, params and seed sections with `g` to generate (same door as the **Generate image** palette row, a bare `/generate`, and the `F10` **Tools ▸ Generate image…** row; dead while another modal is up. img2img, inpaint, hires, refiner and scripts rows are live; video controls — frame ladder, fps, resolution class, CFG and a first-frame slot — live on the form's video tab)

**The live agents strip.** Focus the **live agents strip**, the one row above the input box that lists the delegated runs that are live, plus finished ones for 30 s: `agents: ⠋ explore · ✗ reviewer   (alt+↓)`. Then `←`/`→` (or `↑`/`↓`) move, `Enter` opens the run in the **Agent View** (its own transcript in place of the main one — see *What you see while a turn runs*), `c` stops it (only that run's `Task` call; the turn goes on), `x` dismisses a finished run or stops a running one, and `Esc` or `Alt+↑` gives the keyboard back. Any other key gives it back too, and lands in the input box. A click on a run on the strip opens it the same way. While the strip shows, it replaces the agent list below the input

**`Alt+M` and plan mode.** Toggle **plan mode** between turns: into `plan` from any mode, and back to the mode you left. In `plan` the agent reads and explores but every change is refused except a Markdown plan in `.candy-code/plans/`, and the prompt tells it so. The agent is told about each switch once — a switch you undo before sending anything is never sent — and the status bar shows any mode other than `default` (`plan mode (Alt+M to leave)`). `Shift+Tab` stays the backward pane cycle. See [`docs/PERMISSIONS.md`](PERMISSIONS.md#setting-the-mode)

**`Ctrl+X`, Agent View controls.** Control the agent on screen: `c` **cancels** it at its next tool or step (it stays resumable; press again within 3 s to stop it at once), `p` **pauses** it at its next step boundary — `⏸ paused` on its line, for 10 minutes at most, then it goes on by itself so the parent turn never hangs — and `p` again lets it go on, `s` cancels **every** running agent, `o` **opens it as a session**: once it has finished, its stored sub-agent session becomes the session on screen, a normal one to type into (not while a turn runs), and `b` **sends it to the background**: at its next tool or step it is saved and a background session continues the same conversation, its `Task` call returns at once (`⧗ moved to the background` on its line) so the parent turn goes on, and the result is announced into the chat when the session finishes, like any background agent. Only a run the main conversation delegated can go — a nested run, or one on a launch that cannot start background sessions, says so and keeps running

**`Ctrl+G`, broadcast.** **Broadcast**: the composer sends to every running agent of the batch at once (an empty box reads `message all 3 agents…`); it opens the Agent View on the newest running agent when none is open. `Ctrl+G` again goes back to the one on screen. Reachable while the agent dashboard, a skill picker, the `F10` menu, the settings editor or the media form owns the keyboard; from an ordinary chat pane the chord opens the history search instead (below)

**`Enter` in the Agent View.** Send the draft **to the agent on screen** — the input box is its composer while the view is open (an empty box says `message <agent>…`). A running agent reads it at its next step; a finished one is continued by a follow-up run of the same conversation. Idle or mid-turn alike, and never to the main model. A `/command` or `!command` still runs as one

**`Tab`, cycling and completion.** Cycle focus over the **docked** panes — left column first top-to-bottom, then the right column, chat always first. While chat itself holds focus with the `/` popup open, `Tab` completes the highlighted command instead, and with the cursor at the end of an `@path` mention it completes the path the way a shell does (a unique file gets a trailing space, a directory its `/`, several matches their common prefix; no match leaves the draft as it is) — and at the end of a `$name` mention, the name of a skill you can invoke (`$sec` → `$security-audit `; a `$` word no skill name starts with, such as `$HOME`, leaves `Tab` alone): completion answers to chat's focus, so a `Tab` from a docked pane cycles even with the popup open

**Answering a permission prompt.** Answer a permission prompt: once / refuse / allow calls like this one for the session (the `a` row names the scope — `Bash(git status *)`, or one grant per part of a pipeline or chain such as `Bash(npm test *), Bash(tail *)`, which then covers any later line made only of those commands and read-only ones — and a transcript row says what was allowed; `/permissions` lists and revokes, part by part) / **save** that scope for good (`p` — `a`'s answer plus its patterns written to `permissionRules` in `config.json`, so later sessions allow it without asking; only offered where a configured rule reproduces the grant — a per-part pipeline scope and an exact-call grant have no rule form — and saved rules land after any you wrote by hand, so your `deny` still wins) / **edit** that scope first (the draft box is prefilled with it — a comma-separated list for a per-part grant; `Enter` saves it and allows the call, refused unless it covers this call; `Esc` goes back) / refuse **with a note** — type why (it goes into the draft box and the modal shows it), `Enter` sends the refusal with your words for the agent to read, `Esc` goes back to the question / refuse **and stop** the turn after the current step. **While a prompt is up it owns the keyboard** — nothing reaches the input box — but it only answers to a letter while it is *armed*, and the first key you press that is not an answer disarms it. So typing a slash command at a live prompt now does nothing at all: measured, `/keys`, `/init`, `/agents`, `/branch main`, `/compact` and `/new` are all swallowed whole. `Enter` re-arms (and answers nothing), `Esc` refuses in any state, and the modal says which state it is in. The one thing that still answers on the first keystroke is a message that *begins* with an unmodified `y`, `n`, `a` or `p` — those are the answers; a `Char` press carrying `Ctrl` or `Alt` is a keybinding chord, never an answer, and it disarms like any other first key. A question that cannot be remembered (one of your hooks asked it, or `auto` flagged a security finding) offers no `a` and says why; where `p` has no rule form to save (a per-part pipeline scope, an exact-call grant) its row is left out too. When the agent asks you a question itself (`AskUser`, `PlanExit`) the modal is titled for it and words the same keys as answers: `1…6` picks one of `AskUser`'s choices, `y` takes the first (or approves the plan), `r` is your answer in your own words (or what should change in the plan), `n` declines, and `a` is not offered

## See also

- [COMMANDS.md](COMMANDS.md): every slash command, including `/keys` and `/keys reload`.
- [SETTINGS.md](SETTINGS.md): the `keybindings` row and its apply mode.
- [ACCESSIBILITY.md](ACCESSIBILITY.md): screen-reader and reduced-motion settings that change how keys are reported.
- [THEMES.md](THEMES.md): the other per-screen setting you are likely to change first.
