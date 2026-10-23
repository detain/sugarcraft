# CandySshLauncher Caliber Learnings

## SSH Config Parsing

### First-match-wins semantics




OpenSSH applies the first matching `Host` pattern for each option. CandySshLauncher mirrors this: when parsing, each `Host` block is stored in order and processed sequentially, and for every option the FIRST obtained value across the matching blocks wins (2026-10-23 measured rewrite — the earlier text here claimed `Host *` defaults were applied first and per-host options overrode them; that is not what the parser does, and not what ssh_config(5) says).

Do not reorder host blocks during parsing — the order is semantically significant.

### `Host *` is not a defaults layer




There is no `$globalOptions` bag and no "host-specific wins" merge. `Host *` is an ordinary block evaluated in FILE ORDER through the same positional first-obtained rule: written BEFORE a specific block it supplies the winning value for every host (pinned: `testWildcardFirstWinsUsesGlobal`); written LAST — the convention — it acts as a fallback because specific blocks got there first. It never mints an Endpoint itself. The pre-2026-10-23 text here described a defaults-merge that does not exist in the parser; ssh_config(5) describes neither.

### `~` path expansion




IdentityFile paths starting with `~` must be expanded to the user's home directory. As of 2026-10-23 `SshConfigParser::homeDirectory()` walks: `$HOME` → `posix_getpwuid(posix_geteuid())['dir']` (when ext-posix is loaded) → throws `config.home_unset`. It never falls back to `/root`: the earlier note here described a posix fallback that did NOT exist at the time — the code was `getenv('HOME') ?: '/root'`, which quietly built `/root/.ssh/...` identity paths for any non-root user running with `$HOME` unset. `~user` targets resolve via `posix_getpwnam` when available and stay literal otherwise.

### Port coercion




Port defaults to `22` when not specified. Junk is REFUSED, not zeroed: `Endpoint::parsePort()` accepts digit-only values in 1..65535 and throws `endpoint.port_invalid` naming the host otherwise. The earlier "(int) cast to normalize" advice on this page was an invitation to silently turn `Port ssh` into port 0 — the code never did that, and must not start (2026-10-23).

### Ignored SSH config keywords




The following SSH config keywords are intentionally ignored (no Endpoint field maps to them): `Match`, `Include`, `Set`, `SendEnv`, `ForwardAgent`, `ServerAliveCountMax`, `ServerAliveInterval`, `StrictHostKeyChecking`, `UserKnownHostsFile`, etc. Only endpoints that affect connection targets are mapped.

## Wishlist Picker Lifecycle

### `pcntl_exec` replacement




The final `Launcher::dispatch()` call uses `pcntl_exec` to replace the PHP process with `ssh`. This is a one-way door — after `dispatch()` is called, PHP is gone. There is no return. File descriptors, environment, and controlling TTY are inherited by the new process.

### Config file precedence




The binary checks for config in this order (2026-10-23 rewrite — the list here had tiers 2–5 inverted):

1. `--config <path>` CLI flag
2. `wishlist.{yml,yaml,json}` in the CURRENT directory — first that EXISTS <!-- @rename-keep -->
3. `~/.config/wishlist.{yml,yaml,json}` — first that exists, only when `$HOME` is set

Both tiers are existence-gated. The HOME tier previously appended candidates unconditionally, so a missing `~/.config/wishlist.yml` shadowed a valid `wishlist.yaml` sibling and the run died on a "not found" for a path the user never named.

## Testing SSH Config Parsing

### Snapshot tests




When snapshot-testing `SshConfigParser::parse()`, include real OpenSSH config snippets with comments, blank lines, and indentation variations. The parser should strip comments (`# ...`) and ignore blank lines.

### Coercion tests




Test edge cases: missing `HostName` (pattern becomes host), missing `Port` (defaults to 22), `IdentityFile` with `~` prefix (expands), empty config (returns empty array), and `Host *` only (no endpoints emitted).

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

- **[pattern:raw-mode]** `Picker::setRawMode()` no longer shells out to `stty` directly — it delegates to `SugarCraft\Core\Util\RawMode::enable()` / `RawMode::disable()` (sugar-core, already a required dep). RawMode is the portable controlling-terminal toggle and is a safe no-op on non-tty streams, so PickerTest can drive in-memory streams without overriding `setRawMode()` (the override is now belt-and-suspenders).

- **[anti-pattern:single-quoted-escape]** Single-quoted PHP strings do NOT interpret `\x1b` — `'\x1b[2m'` is literal text, not an ESC sequence. Use `Ansi::sgr(...)`/`Ansi::reset()` (or double-quoted `"\x1b..."`) for terminal escapes. Picker.php:135 once shipped the single-quoted form and printed the literal `\x1b[2m…` instead of dimming the description.

### 2026-05-31 — Use sugar-fuzzy for scored filter matching



Pattern: When a lib needs type-to-filter with ranked results, adopt `sugarcraft/sugar-fuzzy` and use `SmithWatermanMatcher::matchAll()` — it returns scored `MatchResult` objects with grapheme-aligned highlight indices wired into the renderer (ANSI bold+cyan on matched clusters).
Anti-pattern: Ad-hoc `str_contains()` or `stripos()` boolean filtering; it gives no ranking signal and no match-position data for highlighting.
Source: step-33 ai/filter-consumers
