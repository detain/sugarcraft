# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:bundle:two-stage-trust]** A bundle's channel entrypoint is never
  loaded on the launch that first names it: launch one *lists*, launch two
  *loads*, and the user tier `trustedBundleChannels` is the door. Tests that
  boot a channel must drive this two-stage rule (see `candy-code` bundle
  discovery) — do not assert load-on-first-listing.
- **[pattern:bundle:source-path-is-not-install-path]** The repository
  directory `candy-discord/` is source only. The runtime install path is
  `~/.candy-code/bundles/candy-discord` (placed by hand or by
  `candy-code bundles install`). Docs must never present the monorepo path as
  the install path.
- **[pattern:bundle:secrets-in-the-0600-store]** Bot tokens and pairing
  codes are secrets and never ride settings: the token lives in
  `~/.candy-code/channels.json` under `platforms.discord.accounts.<name>.token`
  (mode 0600), `DISCORD_BOT_TOKEN` is honored for the *default* account only,
  and the store wins over the env when both are set. Codes are registered as
  redaction secrets so they cannot echo into transcripts or logs. Settings
  carry policy, the store carries credentials — keep it that way.
- **[pattern:bundle:nonce-and-conversation-binding]** Every approval answer
  (button or text) must carry the per-ask nonce minted at presentation and
  arrive in the conversation the ask was presented in; forged, stale or
  cross-conversation answers are refused and logged, and the pending request
  is untouched. An empty approver roster is a degraded posture (once/deny in
  the ask's own conversation; `always` refused) — it is never a silent bypass.
- **[pattern:bundle:host-vendor-bootstrap]** `tests/bootstrap.php` may hop to
  `../candy-code/vendor/autoload.php` because the repo runs tests outside the
  marketplace path; the *runtime* `src/autoload.php` must know only its own
  subtree. CI installs the host vendor first for the bundle cells
  (`.github/workflows/ci.yml`, "Install host vendor (channel bundle tests)").
  Do not add a relative hop to the runtime autoload.
- **[pattern:bundle:intent-degrade-is-one-way]** Without the Message Content
  intent (or on a gateway 4013/4014 disallowed-intents close) the bundle
  degrades to mention-only handling for the process lifetime; it does not
  silently re-upgrade. Docs and tests name both spellings of the degrade.
- **[pattern:bundle:loop-pin-tests]** ReactPHP-driven legs (gateway reconnect,
  approval TTL ticks, queue flushes) are tested against the pinned loop from
  `sugar-testing` (`LoopPin::pinStableClock()` in `tests/bootstrap.php`) —
  under ext-uv a timer armed after synchronous idle is already overdue, so
  unpinned deadline tests flake green-to-red on host load.
- **[decision:bundle:docs-are-operator-truth]** README.md and
  `candy-code/docs/PLUGINS.md`'s worked example are maintained together and
  are the operator contract: endpoint/env precedence, failure taxonomy, log
  event names, and the exact command table. A code-visible default that no
  doc names is a docs bug (see the queue's discord-04/-05 rows and the
  SettingsHelpKeyDriftTest guard).
