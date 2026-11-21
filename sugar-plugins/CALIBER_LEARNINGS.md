# sugar-plugins — Caliber Learnings

## Accumulated Learnings

### 2026-11-18 — The library holds contracts, not the host
Pattern: `sugar-plugins` holds the plugin and channel interfaces, the launch context, the prompt-fence escape, and the generic mechanics: `PluginLoader` (the trust-gated `require_once` door), `JsonFileQuarantineLedger`, `PluginRegistry` and the `PluginManifest` reader. The host keeps the policy that feeds them: discovery roots, the trust settings and the `TrustList` contents, the notices, and its own stores.
Anti-pattern: do not add a `candy-code` require, and do not move host policy (discovery, trust settings, notices, a host's channels store) in here. Generic loader, ledger, registry or manifest code that a host would otherwise duplicate belongs here. The dependency runs one way: the host requires this package.
Source: plan_plugin.md §3.1, §3.3 (phase 1a, pure additions; loader, ledger, registry and manifest added in the same step)

### 2026-11-18 — PromptFence roster is a single authority
Pattern: `Support\PromptFence::tags()` is the one roster every splice escapes against. Adding a tag before the bytes that need it is free, and adding one after leaves a forgery window.
Anti-pattern: do not escape a payload with a second, local regex. Call `PromptFence::escape()`.
Source: plan_plugin.md §4.13
