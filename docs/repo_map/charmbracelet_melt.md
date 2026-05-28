# charmbracelet/melt

## Metadata
- **URL**: https://github.com/charmbracelet/melt
- **Language**: Go
- **Stars**: ~700-900 (GitHub API unavailable, based on repo age and charmbracelet ecosystem positioning)
- **License**: MIT (Copyright 2022-2023 Charmbracelet, Inc.)
- **Description**: Backup and restore SSH private keys using memorizable BIP39 seed phrases. Converts Ed25519 private keys to a 24-word mnemonic phrase that can be used to reconstruct the exact same key later.

## Feature List
- **Backup**: Convert any Ed25519 SSH private key to a BIP39 mnemonic seed phrase
- **Restore**: Reconstruct a Ed25519 SSH key pair from a seed phrase
- **Stdin/Stdout Piping**: Full Unix pipeline support (`cat key | melt`, `melt restore - < seed`)
- **File Output**: Write restored keys directly to files with `.pub` extension
- **Password-Protected Keys**: Handles both encrypted and unencrypted SSH private keys via passphrase prompt
- **New Passphrase on Restore**: Optionally set a new passphrase when restoring a key
- **Multilingual BIP39 Wordlists**: 12 languages supported (English, Chinese Simplified/Traditional, Czech, French, Italian, Japanese, Korean, Spanish)
- **Man Page Generation**: `melt man` command generates ROFF man pages
- **Beautiful Terminal Output**: lipgloss-styled output with adaptive colors (light/dark mode)
- **Type-Safe Core Library**: Clean separation between CLI (`cmd/melt`) and library (`melt.go`)

## Key Classes and Methods

### Core Library (`melt` package — melt.go)
- `ToMnemonic(key *ed25519.PrivateKey) (string, error)` — Extracts the 32-byte seed from an Ed25519 private key and encodes it as a BIP39 mnemonic phrase
- `FromMnemonic(mnemonic string) (ed25519.PrivateKey, error)` — Decodes a BIP39 mnemonic phrase back to a 32-byte seed and reconstructs an Ed25519 private key

### CLI (`cmd/melt/main.go`)
- `backup(path string, pass []byte) (string, error)` — Reads an SSH key from file or stdin, prompts for passphrase if encrypted, delegates to `melt.ToMnemonic()`
- `restore(mnemonic string, passFn func() ([]byte, error), outFn func(pem, pub []byte) error) error` — Uses `melt.FromMnemonic()`, then `ssh.MarshalPrivateKey[WithPassphrase]()`, writes PEM and authorized_pubkey via output strategy
- `parsePrivateKey(bts, pass []byte) (interface{}, error)` — Wraps `ssh.ParseRawPrivateKey[WithPassphrase]`; detects `*ssh.PassphraseMissingError` to trigger passphrase prompt loop
- `marshallPrivateKey(key ed25519.PrivateKey, pass []byte) (*pem.Block, error)` — Wraps `ssh.MarshalPrivateKey[WithPassphrase]`
- `restoreToWriter(w io.Writer) func(pem, pub []byte) error` — Strategy for outputting key to arbitrary `io.Writer` (e.g., stdout)
- `restoreToFiles(path string) func(pem, pub []byte) error` — Strategy for writing `.pub` and private key files with `0o600` permissions
- `setLanguage(language string) error` — Maps CLI language flag to a `golang.org/x/text/language.Tag` and configures BIP39 global wordlist via `bip39.SetWordList()`
- `getWordlist(language string) []string` — Resolves language string (including aliases like `zh`, `en-gb`, `ja`) to the correct BIP39 wordlist slice
- `getWidth(maxw int) int` — Queries terminal width via `term.GetSize`, falls back to `maxWidth=72`
- `renderBlock(w io.Writer, s lipgloss.Style, width int, str string)` — Applies lipgloss style and renders wrapped text block

### CLI Commands (Cobra)
- `rootCmd` — Default `melt` command; backs up a key to a seed phrase; accepts optional key path arg
- `restoreCmd` — `melt restore` / `melt res` / `melt r`; restores a key from a seed phrase; accepts destination path; flags: `--seed/-s`, `--language/-l`
- `manCmd` — Hidden `melt man`; generates man page via `mango-cobra`

### Tests (`cmd/melt/main_test.go`)
- `TestBackupRestoreKnownKey` — Full round-trip test with known mnemonic, SHA256 fingerprint, and SHA256 public key fingerprint assertions
- `TestBackupRestoreKnownKeyInJapanse` — Verifies i18n round-trip with Japanese wordlist
- `TestGetWordlist` — Table-driven test covering 20+ language alias variations

## Notable Algorithms / Named Patterns

- **BIP39 Mnemonic Encoding**: The core algorithm. A 32-byte (256-bit) uniformly random seed is encoded as a multiple-of-32-bit entropy value, which is then encoded as 12 or 24 words from a fixed 2048-wordlist. The checksum is the first `entropy/32` bits of the SHA256 hash of the entropy.
- **Ed25519 Key Structure**: Ed25519 private keys are derived from a 32-byte "seed" which is hashed via SHA512 to produce the scalar and prefix. Since the 32-byte seed is the deterministic input, extracting and re-using it enables perfect key reconstruction via BIP39.
- **Strategy Pattern**: `restore()` takes an `outFn func(pem, pub []byte) error` argument, allowing `restoreToWriter` (stdout) or `restoreToFiles` (disk) to be injected.
- **Recursive Retry with Passphrase**: `backup()` calls itself recursively with the prompted passphrase on `PassphraseMissingError`.
- **Language Tag Resolution**: `getWordlist()` uses `golang.org/x/text/language` to match user-supplied language strings (including aliases) to BCP47 tags, then falls back to the base language if a specific variant has no dedicated wordlist.

## Strengths
- **Cryptographically Clean**: Directly leverages the Ed25519 seed property that the 32-byte seed fully determines the key, enabling lossless backup/restore verified by matching SHA256 public key fingerprints.
- **Unix-Native**: Full stdin/stdout/stderr and pipe semantics, works cleanly in shell pipelines, supports `/dev/stdin` style paths.
- **Excellent UX**: Beautiful lipgloss-styled output with terminal width awareness and adaptive light/dark colors.
- **Well-Tested**: Both round-trip tests with known values and i18n-specific tests with fingerprint verification.
- **Small Surface Area**: Single-responsibility library (`melt.go`) with a clean public API; only two exported functions.
- **i18n Coverage**: 12 languages with alias resolution covering most real-world locales.
- **Secure File Permissions**: Restored private keys written with `0o600` (owner read/write only).
- **Cascading Linter Configuration**: golangci-lint with strict settings including `gosec`, `goimports`, `rowserrcheck`, `sqlclosecheck`, `tparallel`.

## Weaknesses
- **Ed25519 Only**: Does not support RSA, ECDSA, or any other SSH key types. README explicitly notes this limitation.
- **Memo/Lossy Metadata**: Public key comments (`user@host`) are not preserved through the backup/restore cycle. Users must re-apply them manually via `ssh-keygen -c`.
- **BIP39 Entropy Limitations**: BIP39 was designed for Bitcoin HD wallets; using it for SSH keys is an unconventional but technically sound approach since the Ed25519 seed IS the 32-byte entropy.
- **No Verification Before Restore**: The tool does not verify that a restored key matches a known fingerprint before writing files.
- **Passphrase Prompt TTY Coupling**: The passphrase reading uses `tty.Open()` directly, making it untestable on Windows in CI (tests skip password-protected key backup on Windows).
- **Global BIP39 Wordlist State**: `bip39.SetWordList()` modifies package-level global state, creating potential race conditions in concurrent use.

## SugarCraft Mapping

melt is **not a TUI library** — it is a focused CLI cryptographic tool. It does not map to any existing SugarCraft library, as SugarCraft ports are TUI component libraries (tree, list, spinner, etc.) and melt has no terminal-rendered UI widgets beyond styled text output. However, if a future SugarCraft lib were to wrap cryptographic primitives:

| melt feature | Potential SugarCraft lib | Notes |
|---|---|---|
| BIP39 mnemonic encoding/decoding | `honey-bip39` (does not exist) | Math/crypto lib slot; no current honey-* lib covers mnemonic encoding |
| Ed25519 key handling | `candy-crypto` (does not exist) | Foundation slot; no current candy-* lib covers crypto primitives |
| Seed phrase ↔ key conversion | `honey-seed` (does not exist) | Potential future honey-* math lib |
| Beautiful CLI output (lipgloss-style) | `sugar-bits`, `sugar-prompt` | These are TUI renderers; melt's output is minimal styled text, not a TUI widget |

**Conclusion**: No direct SugarCraft mapping exists. melt is a single-purpose CLI tool for a specific cryptographic task (SSH key backup via BIP39), while SugarCraft is a monorepo of TUI component library ports from the Charmbracelet ecosystem. The conceptual overlap is minimal — SugarCraft has no equivalent for cryptographic seed phrase utilities.

## Analysis

charmbracelet/melt is a remarkably focused tool that solves one real problem: converting an Ed25519 SSH private key into a memorable 24-word seed phrase and back again, enabling paper or manual backups of SSH keys. The implementation is cryptographically straightforward — Ed25519 private keys are derived deterministically from a 32-byte seed, and BIP39 is simply a different encoding format for that 32-byte entropy. The round-trip fidelity is verifiable: the SHA256 public key fingerprint of a restored key exactly matches the original.

The architecture cleanly separates concerns: `melt.go` is a tiny, testable library with two functions (`ToMnemonic`, `FromMnemonic`) that handle the cryptographic conversion, while `cmd/melt/main.go` handles all CLI concerns — argument parsing, TTY interaction for passphrase prompts, file I/O, output styling with lipgloss, and i18n wordlist management. This separation makes the core library unit-testable in isolation (as shown by `melt_test.go`) and the CLI testable via strategy injection for the output path.

The main limitation is scope: it handles only Ed25519 keys, and even those lose their public key comments during restore. The i18n support for BIP39 wordlists is solid with 12 languages and many alias variants, but it relies on global BIP39 wordlist state which could cause issues in concurrent scenarios. The tool is well-tested with known-value round-trip tests and platform-conditional test skipping on Windows for TTY-dependent paths.

In the broader Charmbracelet ecosystem, melt occupies a unique niche — not a TUI component like bubbles or lipgloss, but a focused application tool that happens to use lipgloss for output styling. For SugarCraft, there is no equivalent port because the project specializes in TUI component libraries rather than cryptographic CLI utilities.
