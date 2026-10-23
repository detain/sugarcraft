# Trust boundary — the fleet security contract

Every SugarCraft library that renders, spawns, or consumes data lives
against the same handful of trust rules. This page is the single
discoverable home for them: per-lib docblocks implement them, tests
pin them, and a finding that violates one of them is a security
finding, not a style nit. Report violations via
[SECURITY.md](../SECURITY.md).

## 1. The terminal is a sink — sanitise before you paint

Anything a program writes to a terminal is executed as *control
semantics*: a raw `ESC` in a filename, a tool output line, an image
byte stream or a markdown payload is an escape-sequence injection
primitive (rename windows, forge hyperlinks, spoof frames, smuggle
OSC 8 targets).

The contract lives in `SugarCraft\Core\Util\Sanitize`
(`sugar-core/src/Util/Sanitize.php`). Pick by destination:

| Sink | Method | What it does |
|------|--------|--------------|
| Plain text into cells, titles, labels | `Sanitize::untrusted()` (:239) | Strips ANSI (`Ansi::strip`) and removes C0 controls except TAB/LF/CR, DEL, and the UTF-8-encoded C1 range — byte-wise, so malformed UTF-8 cannot smuggle a survivor past a `/u` pattern (fail-closed). |
| Text into a user-facing display where bidi/zero-width tricks matter | `Sanitize::untrustedForDisplay()` (:314) | The above, plus CR/CRLF→LF mapping, plus `markInvisibleFormatting()` — invisible directional/zero-width codepoints are replaced by visible `<U+202E>`-style notations instead of being silently eaten. |
| Text into a frame that already carries zone markers or image markers | `Sanitize::untrustedForMarkedFrames()` (:595) | `untrusted()` plus a surgical sweep of the two zone sentinels only (§2), preserving legitimate marker traffic. |
| Raw paste / keyboard input from a terminal | `untrustedForMarkedFrames()` | InputReader's paste path uses it: a pasted blob is attacker-authored *and* frame-marked by the time it lands. |

`untrusted()` deliberately passes the Private Use Area through — the
PUA is our marker arena (§2), not foreign traffic. Cell-level shaping
(`cellValue()` :171, `controlChars()` :148, `visibleControls()` :467)
are separate documented tools; do not confuse them with the untrusted
gate.

## 2. The U+E000–U+F8FF arena

SugarCraft reserves the entire BMP Private Use Area
(`PUA_BMP_FIRST` U+E000 … `PUA_BMP_LAST` U+F8FF, Sanitize.php :74/:77)
as its internal marker space:

- **Zone sentinels U+E000 / U+E001** (`ZONE_SENTINEL_OPEN/CLOSE`,
  :80/:83): `sugar-mouse` `Mark::zone()` / `Scan` wrap clickable
  overlay zones in these zero-width cells. Because they are PUA
  codepoints they pass through `untrusted()` — which is exactly why
  `stripZoneSentinels()` (:554, surgical `str_replace` of the two
  sentinels only) exists and why untrusted text must be swept with
  `untrustedForMarkedFrames()` before it can enter a marked frame:
  forged sentinels could otherwise hijack a zone.
- **Image markers from U+E002 upward** (`ImageOverlay::MARKER_BASE =
  0xE002` (:62), `MAX_IMAGES = 6398` (:89), topping the arena at
  exactly U+F8FF — the class lives at `sugar-core/src/ImageOverlay.php`
  and consumers such as `sugar-gallery`'s `PosterCard` emit its
  markers): the marker range and the
  sentinel range are **disjoint by construction**. Nerd Font / icon
  glyphs live outside the reserved band.
- `stripPrivateUse()` (:573) removes the whole BMP PUA via the
  byte-wise pattern at :96 — the safe direction is over-match, and a
  `/u` pattern would silently match nothing on malformed input, so it
  is not used.

If your code emits or parses marked frames, you own both sides: sweep
untrusted payloads before composition, and never let user data pick
its own marker id.

## 3. Spawning: argv arrays, never shell strings

- `proc_open`/`exec` take an **argv array** whenever the command is
  machine-composed — canonical `sugar-mcp-client/src/StdioMcpServer.php`
  (:28). A shell string turns `/bin/sh` into the parent and leaves the
  real child orphaned and unkillable on teardown; it is also the
  injection door.
- Where a shell string is unavoidable (feature-detection of
  user-supplied options, external CLI flags), **every** interpolation
  goes through `escapeshellarg((string) ($field ?? ''))` — the repo
  law in [AGENTS.md](../AGENTS.md) ("Pass ALL external-CLI flags every
  invocation via escapeshellarg").
- Child lifetime accounting is guarded repo-wide by
  `tools/check-child-lifetimes.php` with a fail-closed roster.

## 4. Configuration is trusted input

This is the doctrine with the most surface: config files *name
programs to run*, so whoever can write the config can execute code
with the application's privileges. The fleet treats config as the
operator's credential surface — analogous to `~/.ssh/config` or
`authorized_keys` — and the security-relevant duty is **loud
documentation and isolation**, not pretending config is data:

- **MCP stdio servers** (`.mcp.json`): a `command` entry is arbitrary
  code execution at startup. `candy-code` mitigates by *not* handing
  the stdio child its inherited credentials —
  `candy-code/src/MCP/McpClient.php` (:333/:363) strips
  credential-shaped environment variables; a server that needs a
  secret declares `${VAR}` in its `env` block (or is named in
  `secretEnvAllowlist`).
- **Git repository configuration**: cloning a hostile repo is not
  executing it, but its config can be — `diff.external` and
  `textconv` drivers name programs that run when Git renders a diff.
  `candy-code` keeps read-only Git paths away from repo-supplied
  drivers (`src/Permissions/ReadOnlyCommands.php` :88/:1215/:1233,
  `EnvironmentBlock` isolation pinned by
  `EnvironmentBlockGitConfigIsolationTest`).
- **SSH hub options** (`sugar-ssh-server`): presented key
  fingerprints are forgeable metadata — see the admin guide,
  [docs/admin/sugar-ssh-server.md](./admin/sugar-ssh-server.md).

A change that lets an *untrusted repo, server, or config drop* reach
these execution paths without the user's visible consent is a
vulnerability.

## 5. stderr echoes are terminal writes too

Writing a caught exception message, a subprocess stderr tail, or an
HTTP response fragment to STDERR is writing to the same escape-semantics
sink as a pane title — the same escape-semantics door. Sanitise (or
route through the launch-notice clip) anything that embeds
attacker-influenced bytes before it reaches the error stream.

## 6. Image and media bytes are untrusted

Poster bytes, sixel/kitty/iterm2 payloads, GIF/WebP dimensions —
parsed by decoders, then rendered as markers, dimensions, or names —
enter the same sinks. They flow through `Sanitize` gates before
titles/labels are composed from them, and dimension/name caps are
fail-closed, not advisory.

## Quick checklist for a new library

1. Every string that can carry foreign bytes and reach a terminal
   sink passes a `Sanitize::untrusted*` variant at the boundary
   (parse-don't-validate: once, at ingress).
2. Marked-frame consumers use `untrustedForMarkedFrames()`.
3. Spawns are argv arrays; shell strings escapeshellarg everything.
4. Anything config-named that executes is documented as a trust
   decision, and credentials are not inherited by it by default.
5. New `proc_open` sites land in the child-lifetimes roster in the
   same commit.
