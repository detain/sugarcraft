# Windows audit + fix lane: handoff (updated 2026-11-13, end of session 2)

Owner rule for this lane: commit straight to **master** (no PR), `git pull --rebase`, then `git push origin master`.
Run each phpunit suite ONCE with output to a file, grep that file, delete it afterwards.

## Resuming from the Linux server (myadmin1)
- `/home/sites/sugarcraft` is a live working copy that other sessions use. Pull with `git pull --rebase --autostash origin master`, and leave other sessions' uncommitted work alone. The usual conflict is `candy-code/tests/Config/Support/suite-figure.json` and the README headline: resolve to the HIGHER counts (merged = origin figure + the other side's delta).
- A throwaway test copy lives at `~/sc-wintest` (path repos injected, most libs have vendor). Delete it when the lane is done.
- Windows-only verification needs a Windows box with PHP 8.4 (the dev box had `F:\dev\php84-wintest\php.exe`; delete it when done). Linux can verify only that the POSIX paths stay green.

## Core facts (measured, Windows 11 / PHP 8.4.27)
- proc_open **pipes** on Windows: `stream_set_blocking(false)` fails; `stream_select()` reports them ready while empty; `fread()` then parks until the child writes or exits; a write over the **4096-byte** buffer blocks until the child reads (and fails with every byte lost if the child dies).
- Fix pattern: `SugarCraft\Core\Util\Proc\WindowsPipes` (sugar-core): `readAvailable()` (fstat = PeekNamedPipe sized reads), `runningProbe($proc)`, `drainUntilExit()`, `stageStdin()` (stdin bytes known up front go in as a temp FILE descriptor, never through a pipe), `PIPE_BUFFER_BYTES`. EOF is the child's exit, sampled BEFORE the reads, plus an empty pass. sugar-async `WindowsPipes` and candy-code `Support\WindowsPipes` delegate to it. `ChildPump::drain(running:)` has the poll arm.
- **Do NOT use proc_open `['socket']` stdio on Windows.** node, python, cmd.exe and MSYS tools (sh, bash, cat) lose their output or fail on it, and PHP's Windows socketpair is an unauthenticated loopback TCP connection another local process can race into (security review). That approach was tried and removed (`sugar-async ChildStdio` was deleted).
- PHP `is_executable()` is false for `.cmd`/`.bat` on Windows: use sugar-core `Locator::isExecutable()` (PATHEXT).
- MSYS path conversion: when testing from Git Bash, `MSYS_NO_PATHCONV=1` (otherwise `/c` becomes `C:\`).
- Never let a bash heredoc carry `'\\'`: it collapses to `'\'`. Write PHP patch scripts with the Write tool.

## Shipped on master this lane
- `123902a99` sugar-pty ConPTY live spawn (the 6 h CI hang). `fd53d59da` + `baaf17081` candy-code Bash capture deadlines, MSYS group kill, CI caps.
- `cd1beeee2` sugar-core Kernel32 (segfault, openTty, INPUT_RECORD, UTF-16) + PosixBackend; sugar-pty `Libc::cdefFor`.
- `368850c3d` sugar-async ChildPump Windows poll arm; candy-code WindowsTaskKill/Keychain/ProcessContainment probes.
- `0c2ea0d87` candy-vcr: `record` and `Set Shell` exec mode WORK on Windows (`Cli\WindowsRecordLoop`, sugar-core `WindowsConsoleInput` + `Kernel32` key fields, `Support\HostShell`), ArgvInput, AF_INET socket pairs, `\` paths; Windows suite 1464T green.
- `69b32c4e4`, `48f2c327b`, `b9054a071`: sugar-core WindowsPipes + `Kernel32::processAlive`; candy-git-server; candy-top AsyncCommand/Help (1 s timeout now 1.03 s, Windows absolute path gate, SignalExitTest rc 255); sugar-mcp-client ExchangeLock no mode check on Windows + ProcessLiveness via kernel32; sugar-dashboard Discovery/Locator; sugar-image/sugar-video/sugar-colorprofile Windows drains, NUL, `Synthetic::defaultPath()`.
- Final commit of session 2 (this file's commit): sockets removed again (pipes + polling everywhere); candy-git-server StatelessRpc (stdin via staged file on Windows), BoundedCommand, StdioPipe Windows arms; sugar-mcp-client StdioMcpServer readLine/writeLine/absorbStderr Windows arms (greedy 64 KiB reads keep the 64 MiB frame-cap test fail-fast); sugar-dashboard ExternalModule/BoundedCommand arms; candy-code `DrainsStreamedChild::walk(running:)`, ScriptHook + StatusLineCommand (stdin staged as a file on Windows), GitCommandHandlers::drain arm; ChafaRenderer `devNull()` with the 0700 check kept (security review).
- Known Windows limitation (documented in StdioPipe): a stdin pipe write to a child that stops reading cannot be bounded; git always reads. `StdioPipeBacklogCapTest` skips on Windows.

## Session 3 (Linux, POSIX-regression only; Windows legs UNVERIFIED, re-run them on the Windows box)
Pushed to master: candy-code providers (7cf1a5834) + the rest of A (cbf0fe8ee: CommandBackend, McpClient::probe, ClaudeCodeMcpClient, LspConnection, ProcessExecutor line reads + stderr drain, Subcommands bundle clone, NonInteractive stdin grace, Acp polling stdin); B all done (sugar-input StreamInputDriver + tput NUL, candy-scriptui P2/P3/IntakeReader, candy-files, candy-git Platform MSYS kill, candy-code SystemClipboard 498356955, candy-ansi-inspect ExecRunner via PtySystemFactory); C: sugar-dashboard golden harness (PHP_BINARY + temp-path normalizer) and POSIX gating, sugar-video SyntheticTest.
Known soft spots to check on Windows: ProcessExecutor `stdoutAtEof` (worker exit + empty pipe = EOF); Acp treats a failed fstat on stdin as the editor closing; candy-scriptui IntakeReader treats 0.3 s of silence after data as the writer's EOF (a Windows pipe shows none); LspConnection/ClaudeCodeMcpClient writes go in 4 KiB pieces and can still block on a child that stops reading.
Pre-existing Linux failures cleared on the way: Support/WindowsPipesTest proc_open spec (stderr-capture guard).

## REMAINING (in order)
### C. Windows test-only failures still open (need the Windows box to see them)
candy-top (113), sugar-mcp-client (ExchangeLock 0600, Cwd, /bin/sh fixtures), candy-git-server (Bin*, census /proc, mode bits), sugar-core tests/Util (39), sugar-image ChafaRendererChildTest, candy-scriptui tests, sugar-forms FilePickerTest:439-441, candy-git tests, candy-files tests, sugar-dashboard residue (re-measure). Patterns: PHP_BINARY -r fixtures, NUL, DIRECTORY_SEPARATOR/realpath, gate POSIX-mode legs on host capability (candy-vcr tests/Support/HostFilesystem.php).
### D. Upstream (not this lane)
On Linux: sugar-image has 6 failing GIF-corpus tests (ImageSourceDims/GifFlipWalkParity/GifRound4Security), and candy-top `LangPackParityTest` is missing `lang/it.php`.
### E. Cleanup when done
Delete `~/sc-wintest` on the server and `F:\dev\php84-wintest` + `F:\dev\sc-tmp` on the Windows box.
