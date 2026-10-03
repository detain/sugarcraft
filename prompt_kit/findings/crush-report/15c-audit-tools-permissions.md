# 15c — sugar-crush audit: tool execution and the safety layer

Scope: `src/Tools/` (built-ins, Concerns, PathJail, IgnoreRules, McpToolBridge), `src/Permissions/`, `src/Hooks/`, `src/Support/ProcessContainment.php`, `docs/PERMISSIONS.md`, `docs/HOOKS.md`.
Repro scripts: `/home/sites/crush-research-repos/_audit-scratch/15c/rNN_*.php`, run against a scratch root, never the real repo.

**No finding from this audit remains open.** Fixed findings have been removed from this appendix.

> **Correction to the known list.** Argument-scoped permission rules **are implemented**, in `PermissionRule::matches()` / `matchesShellSubject()`. `Bash(rm *)` deny denies, `Bash(git *)` allow does not grant all of Bash, and allow rules fail closed on `$(…)`, backticks and redirection. Sub-agent **preset grants** still match by name (synthesis Part II #23, roadmap 4.2).

---

## Coverage

**Read end-to-end (code):**
- Tools: `src/Tools/PathJail.php`, `IgnoreRules.php` (including the gitignore→PCRE compiler), `McpToolBridge.php`, `src/ToolRegistry.php` (legacy viewport registry, not on the engine path). Built-ins: `Read`, `Write`, `Edit`, `Bash`, `Grep`, `Glob`, `WebFetch`, `WebSearch`, `SkillTool`, `TaskTool`, `LspTool`, `Doctor`. Concerns: `CapturesProcessOutput`, `BuildsUnifiedDiff`, `TruncatesOutput`.
- Permissions: `PermissionGate`, `PermissionRule`, `SafetyClassifier`, `PermissionMode`, `DenialKind`, `ToolRefusal`, `PermissionDecision`/`Action`/`Reply`/`PromptStage`, `ToolDeclaration`.
- Hooks: `HookRegistry`, `HookManager`, `HookDispatcher` (dormant), `HookConfig`, `HookResult`, `HookContext`, `ScriptHook`, and the built-in hooks.
- Support: `ProcessContainment`, `ContainedPath`, `HookContextFiles`, `ParentProcessGuard`. Agents: `PathJail`, `PathJailConfig`, `SuspendedDelegations`.
- Docs: `docs/PERMISSIONS.md`, `docs/HOOKS.md`.

**Read in part:** the gate, settle, child/IPC and dispatch parts of `src/Runtime.php`; the permission and tool-gate parts of `src/Chat.php`, `EngineBackend.php`, `Bootstrap.php` and `NonInteractive.php`; `candy-mosaic/src/Detect.php` probe paths.

**Checked and found sound (no finding):**
- `PathJail::resolve`/`resolveForCreate`/`resolveDir`, `ContainedPath::within`/`below`, and `Agents\PathJail` (which delegates to them).
- CR/LF in the WebFetch URL: `parse_url()` replaces control characters with `_`, and `Location:` values arrive already split into lines.
- WebFetch's DNS pinning: one resolution, every answer checked, the literal dialled, SNI and Host kept on the name, every hop re-checked, non-http(s) redirects refused.
- `SuspendedDelegations`: id regex, `0600` files, `unserialize` with a class allow-list, and an owner/mode-verified directory. A foreign-owned `/tmp/sugarcrush-suspended-delegations` or `/tmp/sc-hook-ctx` disables resume and context retention, failing closed (DoS only).
- `HookContextFiles::bound()`: UTF-8-safe cut, `0600` plus rename, symlink/owner/mode refusal. Retained files are never deleted, by design (HOOKS.md), and can hold tool output containing secrets in the user's temp directory.
- `TruncatesOutput`: the `mb_strcut` cuts are UTF-8-safe on valid input, and the markers carry exact byte counts.
- `HookConfig`: strict schema, finite timeouts; project `hooks.yaml` is trust-gated. Matchers are case-insensitive and unanchored, which errs toward running deny hooks.
- `HookDispatcher`: not constructed anywhere in `src/`. Its `[exit-1]` "non-blocking deny" arm is unreachable because `ScriptHook` no longer emits the prefix.
- `ScriptHook` exit-code table: matches HOOKS.md.
- `LspTool`: jail applied, `is_file` check, JSON output capped. Only nit: a non-UTF-8 server answer makes `json_encode` return `false` and an empty body.

**Out of scope, pointed elsewhere:** MCP stdio env (known #34); `LspClient` internals.
