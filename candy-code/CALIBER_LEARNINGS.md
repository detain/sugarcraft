# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **`McpMessage`** covers all four JSON-RPC 2.0 packet types: request, response, notification, error. Factory methods: `request()`, `notification()`, `success()`, `error()`.
- **`McpMessage::parse()`** validates `jsonrpc: "2.0"` presence and returns `null` for malformed input — callers handle null gracefully.
- **Polling loop** with `usleep(10000)` (10 ms) waits up to 100 attempts for a matching response id — avoids blocking the TUI while still being responsive.
- **`ClaudeCodeMcpClient::forClaudeCode()`** factory provides the canonical `command: 'claude', args: ['--mcp']` invocation for the official Claude Code MCP server.
- **`disconnect()`** closes pipes and calls `proc_close()` in a loop; `__destruct()` ensures cleanup if the client is abandoned.
- `Renderer` holds a `?Buffer $previousFrame`; on each render it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
- **Behaviour tests** for `Chat` drive `update()` with scripted `KeyMsg` / `MouseMsg` / `Tick` objects and assert the `[Model, ?Cmd]` tuple shape.
- **Coercion tests** for `Session` feed edge cases (missing file, empty string, wrong type) and assert the no-op / clamp / fresh-session outcome.
- **Generator tests** for `StreamingDirectoryLister` assert the yielded `[index, absolutePath]` pairs and handle early-exit by exhausting the generator normally.
- Command class uses DIRECT `echo` statements for output
- Output buffering (`ob_start()`/`ob_get_clean()`) happens in Chat handler
- Command returns exit code: 0 = success, 1 = failure
- `query` (required): The search query string
- `description` (required): "Web search: $query" or similar
- `safesearch` (optional): 0-2 filter level
- `time_range` (optional): "day", "month", or "year"
- Non-empty after trim
- Max 2000 characters
- **[env]** Packagist API: `Authorization: Bearer <username>:<apiToken>` plus `Content-Type: application/json`. `POST /api/create-package` and `PUT /api/packages/<name>` need the MAIN token. `POST /api/update-package` also accepts the SAFE token. The API has no abandon or delete endpoint, so abandoning a package is a manual step in the web UI. The user gets the token with `grep -A1 packagist /home/sites/mystage/auth_login_credentials.txt`. Keep it in a shell variable only, and never write it to a file, a commit or a plan.
- **[gotcha]** A `find /home/sites -maxdepth 4 -name composer.json …` sweep for external `sugarcraft/*` consumers takes longer than 60s, so a `timeout 60` run gets `Terminated`. Run it in the background. The known consumers are phlix-console-client, php-dup-finder, webhooks.interserver.net, and provirted (through its `composer-tui.json` bundle).
- **[gotcha]** `scripts/refresh-deps.php` accepts only `--root`, `--status`/`--verify-only`, `--mode=published|linked`, `--force`, `--dry-run` and `--libs=a,b`. The DEFAULT mode is `published`, which resolves siblings from Packagist and so tests the last pushed master, not the working tree. Pass `--mode=linked --libs=<lib>,<dependents>` to scope a relink.
- **[gotcha]** In candy-code, `tests/Prompt/PromptSnapshotDriftTest.php` pins the byte length and sha256/16 of every system-prompt slot in `tests/fixtures/prompt-snapshots/system-sections.txt`. Any wording change in the prompt turns it red. To fix it, copy the "Actual manifest" from the failure message into the fixture, check that only the slots you meant to change moved, and explain the regeneration in the commit message, as the test's docblock requires.
- **[pattern]** To regenerate golden-render fixtures (candy-code `tests/Tui/MediaForm/*`, `SessionPickerGoldenTest`), run phpunit with `UPDATE_GOLDENS=1`, then review the fixture diff before you commit.
- **[convention]** candy-code has about 22 `*DriftTest.php` guards (find them with `find candy-code/tests -name '*Drift*Test.php'`). `DocFigureProseDriftTest` pins prose needles taken from `lang/en.php` and the docs. When you change user-facing copy, update the lang string, the doc and the needle in the same commit, and never delete a needle. `ReadmeSuiteFigureDriftTest` needs `tests/Config/Support/suite-figure.json` regenerated (the command is in its `regenerate` field) and the README headline count updated from the same run.
- **[gotcha]** candy-code's user config dir is a HOME-rooted dot-dir (`Cli\Bootstrap::configDirPath()` = `homePath() . '/.<app>'`), not XDG. It holds `config.json`, `hooks.yaml`, `mcp-trust.json`, `policy-snapshot.json`, `session.db`, `server/server.lock` (a flock singleton) and caches. Policy paths go through `trustedConfigDirPath()`, which refuses to run on an unowned or world-writable HOME. `PolicySnapshot` stores content fingerprints, not paths.
- **[gotcha]** The CI `composer audit` step for candy-code fails only on HIGH or CRITICAL advisories. `composer audit` has no `--ignore=ID` flag. To accept a specific advisory, record it in the lib manifest with `composer config audit.ignore --json '["GHSA-…"]'`, and give the reason in the PR.
- **[gotcha]** `tools/gen-docs.php` also owns the library and app counts in `docs/index.html`, which it derives from the `docs/MATCHUPS.md` split. Moving a row between `## Libraries` and `## Apps` means re-running `php tools/gen-docs.php`, then `--check`, which acts as the CI drift guard.
- **[pattern]** Before revising a plan or brief written against an older commit, run `git log --oneline <plan-sha>..HEAD` and `git diff --name-status <plan-sha>..HEAD`. Then grep the added lines for new env vars, paths and brand literals rather than trusting the plan's inventory counts.
- **[pattern]** The 2026-10-24 accessor era renamed public `get*()` accessors in `candy-code/src` to bare twins, keeping one-line `@deprecated`/`REMOVED-IN: 2.0.0` BC forwarders (census roster: `candy-code/tests/Config/AccessorDeprecationTest.php` + the README "Accessor deprecation (2026-10-24)" table — flip both in lockstep). Owned interfaces were rewritten outright; bare-name collisions (e.g. `SkillRegistry::userInvocable` by-name lookup) and vendor contracts (`Listener::getAddress`) are documented carve-outs.
