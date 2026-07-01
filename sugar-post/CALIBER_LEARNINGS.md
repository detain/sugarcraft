# Caliber Learnings

Accumulated patterns and anti-patterns for sugar-post development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

### 2026-06-01 — CancellationToken for best-effort I/O cancellation
Pattern: Use CancellationToken for best-effort I/O cancellation; true preemption requires async rewrite.
Source: step-35 ai/async-adopters

### 2026-06-30 — SmtpTransport synchronous retry limitation
Pattern: SmtpTransport::send() is synchronous and cannot leverage AsyncOps::retry() which expects PromiseInterface.
The current implementation catches exceptions and lets the caller retry if desired.
A full async rewrite (AsyncSmtpTransport) would be needed to enable automatic retry with backoff.
Source: findings/plan_sugar-post.md Item 6.1

### 2026-06-30 — CancellationToken callbacks never fire in blocking I/O
Pattern: CancellationToken::onCancel() callbacks cannot fire during blocking stream_socket_client/fwrite/fgets calls.
The send() method checks isCancelled() before operations but mid-operation cancellation is impossible.
This is an architectural limitation of synchronous I/O - true cancellation requires async transport.
Source: findings/plan_sugar-post.md Item 6.2

### 2026-06-30 — Resend API key environment variable fallback
Pattern: ResendTransport accepts empty constructor and reads RESEND_API_KEY from $_ENV or getenv().
This allows deployment without hardcoding API keys in configuration files.
Source: findings/plan_sugar-post.md Item 4.2

### 2026-06-30 — getenv() consistency requires second parameter
Pattern: Use $_ENV['VAR'] ?? getenv('VAR', true) ?: 'default' for consistent behavior.
getenv() without second parameter returns false if not found, but behavior varies across PHP versions.
$_ENV is the superglobal array and is more consistent.
Source: findings/plan_sugar-post.md Item 4.7
