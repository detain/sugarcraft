# sugar-plugins — Caliber Learnings

## Accumulated Learnings

### 2026-11-18 — The library holds contracts, not the host
Pattern: `sugar-plugins` holds the plugin and channel interfaces, the launch context and the prompt-fence escape. Loading under a trust gate, registering by name and quarantining stay with the host.
Anti-pattern: do not add a `candy-code` require, and do not move a host store or registry in here. The dependency runs one way: the host requires this package.
Source: plan_plugin.md §3.1, §3.3 (phase 1a, pure additions)

### 2026-11-18 — PromptFence roster is a single authority
Pattern: `Support\PromptFence::tags()` is the one roster every splice escapes against. Adding a tag before the bytes that need it is free, and adding one after leaves a forgery window.
Anti-pattern: do not escape a payload with a second, local regex. Call `PromptFence::escape()`.
Source: plan_plugin.md §4.13
