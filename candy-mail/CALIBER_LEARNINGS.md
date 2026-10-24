# Caliber Learnings

Accumulated patterns and anti-patterns for candy-mail development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

### 2026-06-01 — CancellationToken for best-effort I/O cancellation
Pattern: Use CancellationToken for best-effort I/O cancellation; true preemption requires async rewrite.
Source: step-35 ai/async-adopters

### 2026-06-30 — SmtpTransport synchronous retry limitation
Pattern: SmtpTransport::send() is synchronous and cannot leverage AsyncOps::retry() which expects PromiseInterface.
The current implementation catches exceptions and lets the caller retry if desired.
A full async rewrite (AsyncSmtpTransport) would be needed to enable automatic retry with backoff.
Source: findings/plan_candy-mail.md Item 6.1

### 2026-06-30 — CancellationToken callbacks never fire in blocking I/O
Pattern: CancellationToken::onCancel() callbacks cannot fire during blocking stream_socket_client/fwrite/fgets calls.
The send() method checks isCancelled() before operations but mid-operation cancellation is impossible.
This is an architectural limitation of synchronous I/O - true cancellation requires async transport.
Source: findings/plan_candy-mail.md Item 6.2

### 2026-06-30 — Resend API key environment variable fallback
Pattern: ResendTransport accepts empty constructor and reads RESEND_API_KEY from $_ENV or getenv().
This allows deployment without hardcoding API keys in configuration files.
Source: findings/plan_candy-mail.md Item 4.2

### 2026-06-30 — getenv() and $_ENV are different lookups
Pattern: use `$_ENV['VAR'] ?? getenv('VAR', true) ?: 'default'` for a total lookup.
Truth-checked 2026-10-23 (docs-fix-1023): a named `getenv($name)` reads ONLY
the real process environment — it never consults the `$_ENV`/`$_SERVER`
superglobals. Measured on PHP 8.3.6: with a name present in both superglobals
and absent from the environment, `getenv($name)` and `getenv($name, true)`
both returned `false`, and the name was missing from the no-argument
`getenv()` listing too. The second parameter is `local_only` semantics
(whether values registered only inside PHP — e.g. apache_setenv()/php.ini
`set_env` — are skipped), not a superglobal toggle; on plain CLI it made no
observable difference in the probe because putenv() writes the real
environment. `$_ENV` itself may be empty depending on `variables_order` (CLI
default often lacks `E`), which is why the chain must read the superglobal
explicitly and fall back to the real env. getenv() returns `false` when the
name is absent — hence the `?:` guard for the empty-string case. The original "behavior varies across PHP versions" note
referred to this split lookup, not to any version inconsistency in getenv().
Source: findings/plan_candy-mail.md Item 4.7

## 2026-10-24 — Accessor-deprecation era (bare twins, ruling 3b)
- **[pattern:accessor-deprecation-era]** `Attachment::getContent()` was renamed to its bare twin `content()`, kept as a one-line `@deprecated` BC forwarder marked `REMOVED-IN: 2.0.0` until candy-mail v2.0.0. The brief's "withContent exists as setter" premise was FALSE on disk — no `withContent` anywhere in the lib; the real near-collision is the public readonly `$content` property, which PHP keeps in a distinct namespace from the method. The two spellings are NOT synonyms and the era pinned it as its own leg: `$att->content` is the raw nullable backing field, `$att->content()` resolves bytes (eager set + defense-in-depth path re-read). `tests/AccessorDeprecationTest.php` owns the census + inverse leg + README table; a sixth get* without those rows goes red.
