# BRIEF C-TOOL-5.c — LSP installer core

area: tool  item: C-TOOL-5 (sources: GAP-EDT-07)  kind: gap · effort M  wave:   serialized: none
deps: C-TOOL-7.c (LspLauncher.php)

## approach
`LspInstaller`: explicit, never automatic, installs into `~/.candy-code/lsp/` (0700).
- Single-binary servers (rust-analyzer, and others the ruling admits) come from a pinned manifest `LspReleases` (language → version, per-OS/arch URL, sha256), via Guzzle with a connect timeout only. The download is checksum-verified before extraction, with an atomic rename into `bin/`.
- npm-distributed servers (intelephense, typescript-language-server, pyright) use `npm install --prefix ~/.candy-code/lsp --ignore-scripts <pkg>@<pin>` through `CapturesProcessOutput::runCaptured` (no new proc_open token).
- `LspLauncher`'s AUTO_TABLE probe looks in `~/.candy-code/lsp/bin` (and `node_modules/.bin`) before PATH, through the existing `binaryPresent` seam.

## files.create
`candy-code/src/LSP/LspInstaller.php`, `candy-code/src/LSP/LspReleases.php`, `candy-code/tests/LSP/LspInstallerTest.php`

## files.modify
`candy-code/src/LSP/LspLauncher.php` (probe dir precedence, fromConfig)

## tests
create the file above (fake transport, no network: a checksum mismatch refuses and leaves nothing; the atomic install; the npm arm builds an argv with `--ignore-scripts`); modify `candy-code/tests/LSP/LspLauncherAutoDetectTest.php` (the install dir wins over PATH)

## docs/guards
none (the CLI and docs ride C-TOOL-5.d)

## risks
pins rot; the manifest needs an owner and a refresh cadence.

## ruling
LSP download policy: may candy-code download and run pinned third-party language-server binaries (and npm packages with `--ignore-scripts`), and which servers are on the list?
