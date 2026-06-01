# SugarWishlist Caliber Learnings

## SSH Config Parsing

### First-match-wins semantics

OpenSSH applies the first matching `Host` pattern for each option. SugarWishlist mirrors this: when parsing, each `Host` block is stored in order and processed sequentially. Global defaults from `Host *` are applied first, then per-host options override them.

Do not reorder host blocks during parsing — the order is semantically significant.

### `Host *` global defaults

`Host *` blocks set defaults that apply to all subsequent hosts. When parsing:

1. Store `Host *` options in `$globalOptions`
2. When a named host block is encountered, merge `$globalOptions` with host-specific options (host-specific wins)
3. Do not emit an Endpoint for `Host *` itself

### `~` path expansion

IdentityFile paths starting with `~` must be expanded to the user's home directory. Use `getenv('HOME')` with a fallback to `posix_getpwuid(posix_geteuid())['dir']` or `/root`.

### Port coercion

Port defaults to `22` when not specified. The SSH config file may contain port as an unquoted integer string — cast with `(int)` to normalize.

### Ignored SSH config keywords

The following SSH config keywords are intentionally ignored (no Endpoint field maps to them): `Match`, `Include`, `Set`, `SendEnv`, `ForwardAgent`, `ServerAliveCountMax`, `ServerAliveInterval`, `StrictHostKeyChecking`, `UserKnownHostsFile`, etc. Only endpoints that affect connection targets are mapped.

## Wishlist Picker Lifecycle

### `pcntl_exec` replacement

The final `Launcher::dispatch()` call uses `pcntl_exec` to replace the PHP process with `ssh`. This is a one-way door — after `dispatch()` is called, PHP is gone. There is no return. File descriptors, environment, and controlling TTY are inherited by the new process.

### Config file precedence

The binary checks for config in this order:
1. `--config <path>` CLI flag
2. `~/.config/wishlist.yml`
3. `~/.config/wishlist.yaml`
4. `~/.config/wishlist.json`
5. `wishlist.{yml,yaml,json}` in current directory

## Testing SSH Config Parsing

### Snapshot tests

When snapshot-testing `SshConfigParser::parse()`, include real OpenSSH config snippets with comments, blank lines, and indentation variations. The parser should strip comments (`# ...`) and ignore blank lines.

### Coercion tests

Test edge cases: missing `HostName` (pattern becomes host), missing `Port` (defaults to 22), `IdentityFile` with `~` prefix (expands), empty config (returns empty array), and `Host *` only (no endpoints emitted).

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

- **[pattern:raw-mode]** `Picker::setRawMode()` no longer shells out to `stty` directly — it delegates to `SugarCraft\Core\Util\RawMode::enable()` / `RawMode::disable()` (candy-core, already a required dep). RawMode is the portable controlling-terminal toggle and is a safe no-op on non-tty streams, so PickerTest can drive in-memory streams without overriding `setRawMode()` (the override is now belt-and-suspenders).

- **[anti-pattern:single-quoted-escape]** Single-quoted PHP strings do NOT interpret `\x1b` — `'\x1b[2m'` is literal text, not an ESC sequence. Use `Ansi::sgr(...)`/`Ansi::reset()` (or double-quoted `"\x1b..."`) for terminal escapes. Picker.php:135 once shipped the single-quoted form and printed the literal `\x1b[2m…` instead of dimming the description.

### 2026-05-31 — Use candy-fuzzy for scored filter matching
Pattern: When a lib needs type-to-filter with ranked results, adopt `sugarcraft/candy-fuzzy` and use `SmithWatermanMatcher::matchAll()` — it returns scored `MatchResult` objects with grapheme-aligned highlight indices wired into the renderer (ANSI bold+cyan on matched clusters).
Anti-pattern: Ad-hoc `str_contains()` or `stripos()` boolean filtering; it gives no ranking signal and no match-position data for highlighting.
Source: step-33 ai/filter-consumers
