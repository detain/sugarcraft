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
Truth-checked 2026-10-23 (docs-fix-1023): the second parameter DOES exist
(`mixed_return`, PHP >= 7.1). Without it, `getenv($name)` also consults the
`$_ENV`/`$_SERVER` superglobals (and php.ini `env=`/`set_env` entries), so a
test or framework that populates `$_ENV` WITHOUT putenv() changes the answer;
with `true`, it reads only the real process environment. `$_ENV` itself may be
empty depending on `variables_order` (CLI default often lacks `E`), which is
why the chain reads the superglobal first and falls back to the real env.
getenv() returns `false` when the name is absent — hence the `?:` guard for
the empty-string case. The original "behavior varies across PHP versions" note
referred to this split lookup, not to any version inconsistency in getenv().
Source: findings/plan_candy-mail.md Item 4.7
