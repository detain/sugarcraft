# candy-pty integration tests

End-to-end tests that exercise the POSIX PTY against **real** interactive
shells (`bash`, `dash`, `zsh`, `fish`) and the **Python REPL**. No mocks,
no fakes — every test forks a real binary, drives it through a real
`/dev/ptmx`, and asserts the round-trip via the master end.

## Layout

| File                                | Binary path        | Notes |
| ----------------------------------- | ------------------ | ----- |
| `InteractiveShellTestCase.php`      | _(abstract base)_  | Shared skip-guards + spawn-then-drain helper. Not auto-discovered (no `Test` suffix). |
| `BashInteractiveTest.php`           | `/usr/bin/bash`    | `bash -i` echo round-trip. |
| `DashTest.php`                      | `/usr/bin/dash`    | Strict-POSIX baseline. |
| `ZshTest.php`                       | `/usr/bin/zsh`     | Skips when zsh is absent. |
| `FishTest.php`                      | `/usr/bin/fish`    | Marker round-trip only — fish prompt shape is out of scope. |
| `PythonReplTest.php`                | `/usr/bin/python3` (fallback `/usr/bin/python`) | Single-line `print()` + plan-mandated multi-line `for`-loop continuation. |

## Conventions

- **Auto-discovered** by `vendor/bin/phpunit` via the default
  `tests/` testsuite in `candy-pty/phpunit.xml`. No extra config.
- Each test method calls `requirePtySyscalls()` (POSIX-only, `ext-ffi`,
  `ext-pcntl`, `/dev/ptmx` readable/writable) **first**, then a
  per-binary `requireBinary()` that skips cleanly when the shell isn't
  installed.
- **5 s wallclock budget per test** (`PythonReplTest`'s multi-line case
  bumps to 7 s — the REPL takes longer to drain through `...` prompts).
- Every spawn is wrapped in `try/finally` that calls
  `kill(SIGKILL) → wait() → master->close()` so no test leaks a
  zombie child or open fd, even on assertion failure.
- `controllingTerminal: true` on every spawn — Ctrl+C-style signals
  must reach the real pgroup just like a human-driven session.
