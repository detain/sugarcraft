# charmbracelet/pop

## Metadata
- **URL:** https://github.com/charmbracelet/pop
- **Language:** Go
- **Stars:** ~2.8k
- **License:** MIT
- **Description:** Send emails from your terminal. A TUI email client built on Bubble Tea with support for both the Resend API and SMTP delivery.

## Feature List
- **Text-based User Interface (TUI):** Full terminal UI for composing emails with real-time preview, built on Bubble Tea (charmbracelet/bubbletea)
- **Command-line Interface (CLI):** Pipe email content directly with flags for `--from`, `--to`, `--subject`, `--attach`, etc.
- **Markdown-to-HTML Rendering:** Body text written in Markdown is converted to HTML via goldmark before sending; supports tables, strikethrough, linkify (with `--unsafe` flag)
- **Dual Delivery Methods:**
  - **Resend:** Sends via `resend.com` API (`RESEND_API_KEY` env var)
  - **SMTP:** Sends via any SMTP server with configurable host, port, username, password, and encryption (`POP_SMTP_*` env vars)
- **File Attachments:** Interactive filepicker (Bubble Tea `filepicker` bubble) for selecting attachments; supports multiple files
- **Email Fields:** From, To, Cc, Bcc, Subject, Body, Attachments
- **Gmail Smart Defaults:** Auto-detects `@gmail.com` addresses and pre-fills `smtp.gmail.com:587`
- **Pre-fill Defaults:** `POP_FROM` and `POP_SIGNATURE` environment variables for boilerplate
- **Keyboard-driven Navigation:** Tab/Shift+Tab between fields; Enter to send or attach; `ctrl+d` to send; `ctrl+c` to quit; `x` to remove attachment
- **Integration Examples:** Pipelines with `gum` (interactive choosers), `mods` (AI-generated content), and `invoice` (PDF generation)
- **Man Page Generation:** `pop man` command generates ROFF man pages via `muesli/mango-cobra`
- **Error Recovery:** Failed emails are saved to a dated temp file if sending fails

## Key Classes and Methods

### `main.go`
- **`rootCmd`** (`*cobra.Command`) — Root CLI command; dispatches either TUI or direct-send based on whether all required fields are present
- **`NewModel(resend.SendEmailRequest, DeliveryMethod)`** — Bootstraps the TUI `Model` (delegated to `model.go`)
- **`hasStdin()`** — Checks if stdin has data (for CLI pipe mode)
- **`DeliveryMethod` selection logic** — Resolved at startup from env vars; error级ly handled when both SMTP and Resend credentials are present

### `model.go`
- **`State`** enum — Finite state machine states: `editingFrom`, `editingTo`, `editingCc`, `editingBcc`, `editingSubject`, `editingBody`, `editingAttachments`, `hoveringSendButton`, `pickingFile`, `sendingEmail`
- **`DeliveryMethod`** enum — `None`, `Resend`, `SMTP`, `Unknown`
- **`Model` struct** — Holds all Bubble Tea component models (`From`, `To`, `Cc`, `Bcc`, `Subject`, `Body` textinput/textarea, `Attachments` list, `filepicker`, `loadingSpinner`, `help`, `keymap`) plus `state`, `DeliveryMethod`, `err`, `quitting`, `abort`
- **`NewModel()`** — Constructor; initializes all sub-models, sets initial focus based on which fields are empty
- **`Init()`** — Bubble Tea init; starts cursor blink command
- **`Update(tea.Msg)`** — Main update loop; handles `sendEmailSuccessMsg`, `sendEmailFailureMsg`, `clearErrMsg`, `tea.KeyMsg` (all keybindings), delegates to sub-model updates
- **`View()`** — Renders the full TUI based on current `state`; shows filepicker when `pickingFile`, spinner when `sendingEmail`, all form fields otherwise
- **`focusActiveInput()`** — Applies `activeLabelStyle`/`activeTextStyle` and calls `.Focus()` on the currently-editing field
- **`blurInputs()`** — Removes focus from all inputs, resets prompt/text styles to inactive
- **`canSend()`** — Returns true when From, To, Subject, and Body are all non-empty

### `email.go`
- **`sendEmailCmd()`** — Returns a `tea.Cmd` closure that calls `sendSMTPEmail` or `sendResendEmail`, dispatches `sendEmailSuccessMsg` or `sendEmailFailureMsg`
- **`sendSMTPEmail(to, cc, bcc, from, subject, body, attachments)`** — Creates SMTP client via `xhit/go-simple-mail/v2`, sets TLS config, uses `goldmark` to convert body Markdown → HTML (falls back to plain text on error), attaches files, sends
- **`sendResendEmail(to, cc, bcc, from, subject, body, attachments)`** — Creates Resend client, uses `goldmark` with optional unsafe extensions for HTML conversion, builds `resend.SendEmailRequest`, calls `client.Emails.Send`
- **`makeAttachments(paths)`** — Reads files from disk, returns `[]resend.Attachment` for Resend API
- **`saveTmp(s)`** — Writes failed email body to a `pop-YYYY-MM-DD-*.txt` temp file as error recovery
- **`gmailSuffix` / `gmailSMTPHost` / `gmailSMTPPort`** — Constants for Gmail smart defaults

### `keymap.go`
- **`KeyMap` struct** — Fields: `NextInput`, `PrevInput`, `Send`, `Attach`, `Unattach`, `Back`, `Quit` (each a `key.Binding`)
- **`DefaultKeybinds()`** — Returns keybindings: `tab`=next, `shift+tab`=prev, `ctrl+d`/`enter`=send (disabled until hover), `enter`=attach (disabled until attachments state), `x`=unattach, `esc`=back, `ctrl+c`=quit
- **`updateKeymap()`** — Enables/disables bindings based on current `state`; also updates `filepicker.KeyMap` sub-bindings
- **`canSend()`** — (duplicated in keymap.go) predicate checking all required fields

### `style.go`
- **Lipgloss styles** — `activeTextStyle`, `textStyle`, `activeLabelStyle`, `labelStyle`, `placeholderStyle`, `cursorStyle`, `paddedStyle`, `errorHeaderStyle`, `errorStyle`, `commentStyle`, `sendButtonActiveStyle`, `sendButtonInactiveStyle`, `sendButtonStyle`, `inlineCodeStyle`, `linkStyle`
- **Color constants** — `accentColor` (99), `yellowColor` (#ECFD66), `whiteColor` (255), `grayColor` (241), `darkGrayColor` (236), `lightGrayColor` (247)
- **`emailSummary(to, subject)`** — Formats the success message shown after send

### `attachments.go`
- **`attachment`** type — `string` alias representing a file path; `FilterValue()` returns the path string
- **`attachmentDelegate` struct** — Implements Bubble Tea `list.ItemDelegate`; `Render()` draws each attachment with `•` prefix when focused, spaces when unfocused; `Height()` returns 1
- **`Update()`** — No-op; required by interface

## Notable Algorithms / Named Patterns

- **Bubble Tea Finite State Machine:** `State` enum drives all navigation. `Tab` advances through the form (from→to→cc→bcc→subject→body→attachments→send button→back to from). `Shift+Tab` reverses. `Esc` jumps back to attachments. State gates which sub-models receive focus and which keybindings are active.
- **Dual-delivery dispatch:** `DeliveryMethod` is resolved at startup from environment variables. `sendEmailCmd()` dispatches to `sendSMTPEmail()` or `sendResendEmail()` at runtime based on this field — not at compile time.
- **Gmail Smart Defaults:** `sendSMTPEmail()` auto-fills `smtp.gmail.com:587` when the SMTP username ends in `@gmail.com`, avoiding manual configuration for the most common case.
- **Markdown-to-HTML pipeline:** Uses `yuin/goldmark` with configurable extensions. Resend path supports `extension.Strikethrough`, `extension.Table`, `extension.Linkify` under `--unsafe` flag. SMTP path uses plain conversion. Both fall back to plain-text if rendering fails.
- **Attachment recovery on failure:** On send failure, `saveTmp()` writes the email body to a timestamped temp file so the user doesn't lose their work.
- **Keyboard-navigable form:** Combination of Bubble Tea's `textinput` and `textarea` bubbles with manual `focusActiveInput()`/`blurInputs()` style management for visual feedback (active label turns accent-colored).

## Strengths
- Clean separation between TUI and CLI modes — works interactively and in pipelines
- Two production-quality delivery backends (Resend API + SMTP) with a clean dispatch pattern
- Gmail auto-detection reduces friction for the most common SMTP case
- Full Bubble Tea ecosystem: filepicker, spinner, help, textarea, textinput all composed cleanly
- Rich keyboard navigation matching common TUI email client conventions (Tab to advance, Shift+Tab to go back)
- Markdown authoring with live HTML conversion is a genuine workflow improvement over plain text
- `lipgloss` for all styling ensures consistent, themable terminal rendering
- MIT licensed, part of the well-regarded charmbracelet ecosystem (~2.8k stars)

## Weaknesses
- No HTML template support — body is either plain text or single-pass Markdown→HTML
- No email preview (rendered HTML) before sending in the default (non-preview) flow
- Bcc/Cc fields are hidden by default (only shown when non-empty) — not discoverable
- Attachment content is read entirely into memory (`os.ReadFile`) — could be problematic for large files
- No unit test coverage visible in the repository structure (no `*_test.go` files in the clone)
- The TUI is tightly coupled to email composition — the Bubble Tea `Model` is not designed for reuse/extraction
- `--unsafe` flag enables extra Markdown extensions but the implications are not clearly documented
- `go.mod` requires Go 1.25.9 (very recent), limiting compatibility with older Go versions

## SugarCraft Mapping

SugarCraft does not have a direct email-sending library. Pop maps to several SugarCraft components at the UI/UX layer:

| pop feature | SugarCraft lib | Notes |
|---|---|---|
| TUI form with sequential field navigation (`textinput`, `textarea`, `list`) | `sugar-prompt` | The `Prompt` model with its multi-step keybinding flow and `with*()` state building is the closest structural match |
| Bubble Tea `Model` with `State` enum and `Update`/`View` | `sugar-bits` / `candy-core` | The Model/init/update/view pattern mirrors SugarCraft's `Model` TUI contract |
| Lipgloss styling (`activeLabelStyle`, `textStyle`, etc.) | `sugar-shine` | Both use lipgloss for terminal color/style; `Shine` is the styling utility lib |
| File picker for attachments | `sugar-bits` | If `sugar-bits` has filepicker-like `List`/`Delegate` patterns, those would be relevant |
| Markdown → ANSI rendering (goldmark → SGR bytes) | `sugar-bits` (via `candy-shine` ANSI rendering) | Snapshot-testing the raw SGR output matches the `view()` testing approach in `candy-core` tests |
| Keymap struct with `key.Binding` fields | `sugar-prompt` or `candy-core` | Keybinding patterns with `WithKeys`, `WithHelp`, `SetEnabled` are directly reusable |
| CLI flag parsing (Cobra) | N/A | SugarCraft libs don't expose CLI layer; this is app-level only |
| Email sending (SMTP/Resend API) | N/A | No SugarCraft lib for network/email; would be net-new |

**Summary:** Pop is primarily an application, not a library. The most useful SugarCraft ports would be:
- **`sugar-prompt`** — for its sequential form-input TUI pattern
- **`sugar-bits`** — for the `textinput`/`textarea`/`list` Bubble Tea component wrappers and snapshot testing
- **`sugar-shine`** — if it wraps `lipgloss` for reusable styling patterns

There is no SugarCraft equivalent for the actual email-sending logic (SMTP, Resend API client, markdown-to-HTML pipeline).

---

## Analysis

**charmbracelet/pop** is a well-crafted terminal email client that serves as both a standalone tool and a showcase for the charmbracelet/Bubble Tea ecosystem. Its most impressive technical achievement is the seamless dual-mode operation: running `pop` with no arguments launches a full Bubble Tea TUI with sequential field editing (From → To → Cc/Bcc → Subject → Body → Attachments → Send), while `pop < message.md --from ... --to ... --subject ...` bypasses the TUI entirely and sends directly. Both paths share the same email-building and delivery logic in `email.go`, with the TUI path using `sendEmailCmd()` as an async Bubble Tea command.

The state machine in `model.go` is the operational core. The `State` enum (`editingFrom` through `sendingEmail`) drives every aspect of input focus, keybinding availability, and view rendering. This is a textbook Bubble Tea pattern: the `Update` method pattern-matches on `tea.KeyMsg` to advance or regress the state, delegates to sub-model `Update` calls, and the `View` method renders completely different layouts based on the current state (e.g., the filepicker view when `state == pickingFile`). The `updateKeymap()` method dynamically enables/disables bindings as state changes, creating a context-sensitive interface where only relevant keys do anything — a polished touch that prevents user errors.

The delivery abstraction is clean and extensible. `DeliveryMethod` is a simple enum with four values, and `sendEmailCmd()` dispatches to either `sendSMTPEmail()` or `sendResendEmail()` at runtime. The Gmail smart-defaults in `sendSMTPEmail()` (auto-filling `smtp.gmail.com:587` when the username ends in `@gmail.com`) show thoughtful UX engineering — the most common SMTP configuration is zero-configuration for Gmail users. The `goldmark` Markdown→HTML conversion is a nice touch that makes the TUI body textarea feel natural to use; users can write Markdown and the email is sent as formatted HTML with a plain-text fallback.

From a SugarCraft perspective, pop's TUI architecture maps well to the `sugar-prompt` and `sugar-bits` components. The sequential-field navigation with `Tab`/`Shift+Tab` is structurally similar to how `Prompt` walks through steps. The `lipgloss` styling approach is directly parallel to `sugar-shine`. However, pop's core value proposition — actual email delivery via SMTP and the Resend API — has no SugarCraft equivalent and would require a net-new `sugar-mail` or similar library. The Bubble Tea patterns themselves (state machine, sub-model composition, keybinding context-sensitivity) are the most portable part and would translate cleanly to PHP-FFI-wrapped Bubble Tea or a native PHP TUI implementation following the SugarCraft immutable + fluent model pattern.
