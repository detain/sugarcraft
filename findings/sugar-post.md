# Code Review: sugar-post

**Library:** sugarcraft/sugar-post
**Type:** Email sending library (SMTP + Resend API)
**Reviewer:** Code Audit
**Date:** 2026-06-29

---

## Overview

`sugar-post` provides an immutable `Email` value object, `Attachment` for file handling, a `Transport` interface with two implementations (SMTP and Resend API), and a `Mailer` high-level sender. The library follows PSR-12, uses strict types, immutable patterns with `with*()` builders via the `Mutable` trait, and integrates with `candy-async` for cancellation tokens.

---

## Critical Issues

### 1. Silent Failure When Attachment File Cannot Be Read (`src/Attachment.php:33-36`)

```php
$prev = \error_reporting(E_ALL & ~\E_WARNING);
$content = @\file_get_contents($path);
\error_reporting($prev);
```

When `fromPath()` cannot read a file (e.g., permissions denied), `$content` becomes `false`. The constructor stores this `false` as `$this->content`, then `getContent()` returns `false` (coerced to empty string) with no indication of failure. This silently swallows errors.

**Recommendation**: Store `null` explicitly when content is `false`, then throw `RuntimeException` in `getContent()` when content is accessed but was unreadable.

---

## High Severity Issues

### 2. SMTP `sendRaw()` Does Not Check `fwrite` Return Values (`src/SmtpTransport.php:322`)

```php
private function sendRaw(string $data): void
{
    if ($this->socket === null) {
        throw new \RuntimeException(Lang::t('smtp.not_connected'));
    }
    \fwrite($this->socket, $data);  // No return value check!
}
```

`fwrite()` returns `false` on error or the number of bytes written (which may be less than requested for partial writes). The code ignores both cases. For a library sending emails, this could silently drop data.

**Recommendation**: Check return value and throw on failure.

### 3. SMTP `readResponse()` Silently Ignores Errors (`src/SmtpTransport.php:331-334`)

```php
$line = \fgets($this->socket);  // @ - suppresses warnings
if ($line === false) {
    throw new \RuntimeException(Lang::t('smtp.no_response'));
}
```

The `@` error suppression means PHP warnings for invalid socket state are suppressed. `fgets()` can also return an empty string `""` on timeout or closed connection, which would NOT trigger the `false` check and would cause `$code = (int) substr("", 0, 3) = 0` which would fail the `250` check. This is technically handled but the error message would be misleading.

---

## Medium Severity Issues

### 4. `buildMimeMessage()` Is Too Long (`src/SmtpTransport.php:226-311`, 85 lines)

The method handles multiple responsibilities: building headers, body parts, and attachments. Should be refactored into smaller private helpers.

### 5. No Async Transport Implementation

`SmtpTransport` uses blocking `stream_socket_client()` / `fgets()` / `fwrite()` calls. Since this is a ReactPHP ecosystem library, consider adding `AsyncSmtpTransport` that returns a `PromiseInterface`.

### 6. `$socket` Type Declaration Is Incorrect (`src/SmtpTransport.php:29-30`)

```php
/** @var resource|\Socket|null */
private $socket = null;
```

`stream_socket_client()` returns a PHP `resource`, not a `Socket` object. The `\Socket` class is from the `sockets` extension, not this stream.

### 7. No Connection Timeout for SMTP (`src/SmtpTransport.php:96-117`)

`stream_socket_client()` is called without a connection timeout parameter. Could hang indefinitely if SMTP server is unreachable.

### 8. No Size Limit on Attachments

`Attachment::getContent()` reads entire file into memory. For large attachments, this could cause memory issues.

---

## Low Severity Issues

### 9. Duplicated Logic: Address Format Extraction

Both `Email::sanitizeAddr()` and `SmtpTransport::bareAddr()` extract bare addresses using near-identical regex patterns. Could be refactored into a shared utility.

### 10. Resend API Key Stored in Plain Text

The Resend API key is stored as a plain string property. Could use environment variable injection.

### 11. `withTo()` / `withCc()` / `withBcc()` Don't Deduplicate

If a recipient is added twice, it will appear twice in the array while `allRecipients()` deduplicates. Inconsistency.

### 12. No Tests for `startTlsIfNeeded()`

The TLS upgrade path is not exercised in any test. Critical for secure SMTP on port 465.

### 13. `hasExtension()` Uses `str_contains` Could Produce False Positives

Checks if extension name appears anywhere in EHLO response string, not line-by-line.

### 14. Missing `Content-ID` Format Validation

`Attachment::inline()` accepts any string as `$cid` but RFC 2392 requires valid URI format.

### 15. Bin `pop` Uses `getenv()` Instead of `$_ENV`

For modern PHP 8.3+, `$_ENV` or `getenv('VAR', false)` is preferred.

---

## Compatibility Issues with Other SugarCraft Libs

### 16. Depends on `dev-master` of `candy-async` and `candy-core`

Using `dev-master` as a version constraint means the library could break if those packages make breaking changes.

### 17. No `candy-async` AsyncSmtpTransport Implementation

Since the ecosystem is ReactPHP-based, an async SMTP transport returning `PromiseInterface` would be more idiomatic.

---

## Async Pattern Improvements

### 18. SmtpTransport Cannot Use `AsyncOps::retry()`

The current `SmtpTransport::send()` is synchronous and returns `void`. It cannot leverage `AsyncOps::retry()` which expects a `PromiseInterface`.

### 19. CancellationToken Callbacks Never Fire in SmtpTransport

`CancellationToken::onCancel()` is never called in `SmtpTransport`. Mid-operation cancellation is impossible without an async rewrite.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| Critical | 1 | Silent attachment read failure |
| High | 2 | Unchecked fwrite return, @ suppressed errors on fgets |
| Medium | 4 | Overlong buildMimeMessage(), no async SMTP, incorrect socket type, no connection timeout |
| Low | 7+ | Duplicated address extraction, getenv vs $_ENV, no TLS tests, etc. |

**Top Fixes:**
1. Fix the attachment read failure to throw instead of silently returning `false`
2. Add `fwrite()` return value checks to `SmtpTransport::sendRaw()`
3. Refactor `buildMimeMessage()` into smaller composable methods
4. Consider an async SMTP transport for the ReactPHP ecosystem
