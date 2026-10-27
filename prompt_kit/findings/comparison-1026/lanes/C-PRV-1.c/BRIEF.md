### C-PRV-1.c — Safe `candy-code login`: merge, refuse a type clash, no echo
- item: C-PRV-1 (sources: PRV-4-P1, PRV-4-P2)
- kind: fix · MAJOR · effort S
- approach:
  - Root cause 1: `Subcommands::loginStoreKey()` replaces the provider block outright with `$providers[$type] = ['type' => $type, 'apiKey' => $key]` (`candy-code/src/Cli/Subcommands.php:973`). Rotating a key wipes `model`, `thinkingBudget`, `modelPrices` and, worst of all, a gateway `baseUrl`.
  - Root cause 2: the key is read with `fgets(\STDIN)` with TTY echo on (`:942`).
  - Fix: merge `apiKey` (and `type`) into the existing block, and refuse without writing when the existing block's `type` differs.
  - When STDIN is a TTY, read the key with echo off through sugar-core's `SugarCraft\Core\Util\Tty`: raw mode, minimal Enter/Backspace/Ctrl-C handling, restored in `finally`.
  - No new child process. The existing `$loginKeyRead` seam keeps the suite TTY-free; add a `$loginTty` seam for the echo-off branch.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`loginStoreKey()`, new private key reader)
- tests: modify `candy-code/tests/Cli/LoginSubcommandTest.php`. `model`, `baseUrl` and `thinkingBudget` survive a key rotation; a mismatched type refuses and leaves the file byte-identical; the tty seam is restored after a thrown read.
- docs/guards: lang `cli.sub.login.type_mismatch`; no doc row changes
- serialized: [headless] [lang:cli.sub.login.*]
- deps: none
- phase: normal
- ruling: none
- risks: the raw-mode reader must restore the terminal on every exit path, including a SIGINT during the read.

