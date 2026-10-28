# C-TOOL-5.c weld record

- Lane tip `abdc611d3` (base 4b771634d, single commit) → pick `14d37bbf5`, patch-id `7e40b53449fb` identical both sides. Zero manual conflict: `ReadPathCensusTest` and `TreeWideGuardRosterTest` auto-merged keep-both (shared-roster file with C-EXT-4.a pending — its pick lands later in this run; same keep-both rule applies there).
- Author/committer `detain@interserver.net` verified with separate `%ae`/`%ce` greps; zero Co-authored/Co-developed trailers.
- Durations: `scripts:` commit `0b74f4412` adds `candy-code/tests/LSP/LspInstallerTest.php 0.080 20` (single-file junit measure; tsv 1491→1492, set-equality gained exactly this file, 4 pre-existing desc-violations unchanged).
- No regeneration owed: no new slash command/tool/env key (the `lsp install` CLI verb + docs ride C-TOOL-5.d; raw English per LspLauncher `problems()` precedent, no Lang keys).

## Gate reconciliation (roster "expect 212T/1527A")

Measured on merged master (cwd=candy-code, plain pipe, -u LINES -u COLUMNS):

- Path form `tests/LSP tests/Support/ReadPathCensusTest.php` → **OK (199 tests, 1452 assertions)** — exactly the lane's as-built 197/1437 + the 2 FIX-1 pins.
- Substring filter `LspInstaller|LspLauncher|ReadPathCensus` → **72T/914A green** (lane worktree same filter: 72T/911A; +3A foreign merged drift).
- The roster's 212/1527 is the lane's own composite: reviewer window 210/1515 (= 197 as-built path run + 13 foreign `BinCandyCodeWiringTest` rows they added to the window) + 2 FIX-1 pins = 212. The 13 rows are foreign (C-TOOL-5.b/BIN wiring domain), not LspInstaller behaviour. Disclosed here per campaign law: report the measurement, not the inherited figure.
- `LspConnectionForkSafetyTest` (known co-shard timing flake family) untouched by this lane; the path-form run above passed 199/199 in-window.

## Scope carried (from lane REPORT)

- `LspReleases.php` pinned manifest (intelephense 1.18.5, typescript-language-server 6.0.2, pyright 1.1.414, rust-analyzer 2026-10-26; linux/x86_64 sha re-hashed from real asset 4c83…80ce) + `isAllowedUrl` https-only exact-host gate with redirect re-gate.
- `LspInstaller.php` explicit-only into PrivateDir 0700 `~/.candy-code/lsp`; hash_equals BEFORE decompress; staged copy→chmod 755→atomic rename; chmod-failure branch discards staged file (NIT-1 cure, structurally pinned); npm arm `--ignore-scripts` pinned pkg@ver, every token escapeshellarg via runCaptured (zero new proc_open, child-lifetimes rc=0); NO total request timeout (E646, pinned via assertArrayNotHasKey).
- `LspLauncher::fromConfig` install-root precedence: `<root>/bin`, `node_modules/.bin`, THEN PATH, absolute path stored; injected-probe contract byte-identical (behavioural trio + structural source-slice pin from MINOR-1 cure).
- Downstream: durations row here; figure re-pin at closeout; `lsp install` verb + docs → C-TOOL-5.d.
