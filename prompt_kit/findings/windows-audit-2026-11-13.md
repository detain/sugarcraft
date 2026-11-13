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

## REMAINING (in order)
### A. candy-code product loops still on select+fread (Windows hangs)
Use the same pattern (runningProbe + readAvailable; stdin known up front → `WindowsPipes::stageStdin`):
`Providers/{ClaudeCodeInvocation:~306, ClaudeCodeProvider:~383, CodexInvocation:~252, CodexProvider:~301}`, `ClaudeCodeMcpClient.php` readFrame/sendMessage/drainStderr, `LSP/LspConnection.php` (drainStderr ~1574, reads), `MCP/McpClient.php probe() ~659`, `Backend/CommandBackend.php ~183` (NULL-timeout select over 3 pipes), `Agents/ProcessExecutor.php ~242, ~426` (+ stderr ~715 never drained), `Cli/Subcommands.php ~5420`, `Cli/NonInteractive.php ~2542` (stdin first-byte grace: `-p` hangs on a silent open stdin; for a console stdin use sugar-core `WindowsConsoleInput`/WaitForSingleObject), `Cli/Acp.php:116` (React `ReadableResourceStream(STDIN)` throws on Windows → `acp` dies at startup; needs a polling stdin reader on Windows).
After candy-code src edits: add tests (Windows legs skip elsewhere), add new test files to `scripts/parallel-tests-durations.tsv`, keep `suite-figure.json` + README headline in sync, and add new Windows-only skips to `SuiteSkipRoster` (see `candy-code/tests/Support/SuiteSkipRoster.php`).
### B. Crash/broken items
- sugar-input `src/Driver/StreamInputDriver.php:83-90`: the ctor throws on Windows (stream_set_blocking false), which breaks sugar-readline `Readline::runLoop :375`. Use sugar-core `WindowsConsoleInput` for a console stdin, and fstat reads for a pipe.
- candy-scriptui: P2 `bin/candy-scriptui`, where symfony `Terminal::getWidth()` runs `mode CON`, which eats a file-redirected stdin; putenv COLUMNS/LINES before `$app->run()`. P3 `src/Runtime/ColourGate.php:148-153`: unset TERM is not "dumb" on Windows; add a `sapi_windows_vt100_support(STDOUT)` rung. `IntakeReader.php:50-60`: `--timeout` doesn't bound piped stdin (fstat polling).
- candy-files `src/Manager.php:2050` absolute-path test (`$path[0]==='/'`), and `bin/candy-files:87` `--print-cd` /dev/tty.
- candy-git `src/Platform.php:35-50`: reuse candy-code's MSYS group kill (`WindowsTaskKill`).
- candy-code `Support/SystemClipboard.php:161-162`: /dev/null → NUL, plus add `clip.exe`. sugar-input `SignalResizeDriver:370` `tput 2>/dev/null`.
- candy-ansi-inspect `src/Exec/ExecRunner.php:132`: POSIX `Pty::open()` → `PtySystemFactory`.
### C. Windows test-only failures (pre-existing and new; the user wants them fixed too)
Measured on Windows (full suites):
- candy-top: 113 (shell-script fixtures in AsyncCommandTest, IPMI `posix_*`, DRM fdinfo, BinTest, ConfigFile HOME).
- sugar-dashboard: GoldenSnapshotTest ×250 (check golden CRLF: probably needs `.gitattributes eol=lf` for golden fixtures); ExternalModule `.sh` fixtures; Discovery executable/0777 legs; GenericModule.
- sugar-mcp-client: ExchangeLock 0600 assertions, Cwd `/` vs `\`, `/bin/sh` + `/usr/bin/env` fixtures.
- candy-git-server: BinRepoCommands/BinServeRefusal (bin invocation), ChildProcessLifetimeCensus (`/proc`), Config mode bits, GitDaemonAsync.
- sugar-core tests/Util: 39 (AtomicJsonFile modes, Open, Clipboard, LockFile, LocatorTest extension-less fixtures + `/` paths, BoundedShutdown).
- sugar-video: `SyntheticTest::testPlayPhpNoArgsEmitsNoWarnings` (shell string path).
- sugar-image ChafaRendererChildTest (fake chafa shell scripts).
- candy-scriptui (bootstrap TERM pin, LoopPinTest, STREAM_PF_UNIX, timeout.exe, control-byte dirs, rm -rf teardowns, SignalHonestyTest latch); sugar-forms FilePickerTest:439-441; candy-git (RecursiveDirCleanup chmod, `2>/dev/null`, `we"ird.txt`, shims, `/` vs `\`, `/bin:/usr/bin`); candy-files; candy-vcr leftovers are done (green).
Patterns: `PHP_BINARY -r` instead of /bin/sh|echo|sleep fixtures; `NUL`; `DIRECTORY_SEPARATOR`/`realpath()` in expectations; POSIX-mode and symlink legs gated on what the host can do (see candy-vcr `tests/Support/HostFilesystem.php`).
### D. Upstream (not this lane)
On Linux: sugar-image has 6 failing GIF-corpus tests (ImageSourceDims/GifFlipWalkParity/GifRound4Security), and candy-top `LangPackParityTest` is missing `lang/it.php`.
### E. Cleanup when done
Delete `~/sc-wintest` on the server and `F:\dev\php84-wintest` + `F:\dev\sc-tmp` on the Windows box.
