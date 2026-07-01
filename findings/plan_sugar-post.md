---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-post Code Review Findings

## Goal
Address all 19 findings from the sugar-post code review, covering critical silent failure bugs, unchecked I/O operations, code quality issues, and async pattern improvements.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix attachment silent failure by storing null and throwing on getContent() access | Prevents emails sending with missing attachment content without warning | `ref:findings/sugar-post.md` Issue #1 |
| Add fwrite() return value checking in SmtpTransport::sendRaw() | fwrite can return false or partial writes; silent data loss is unacceptable for email | `ref:findings/sugar-post.md` Issue #2 |
| Fix @ error suppression on fgets() in readResponse() | @ suppression hides PHP warnings that could indicate serious connection issues | `ref:findings/sugar-post.md` Issue #3 |
| Refactor buildMimeMessage() into smaller private helpers | 85-line method violates single responsibility; harder to test and maintain | `ref:findings/sugar-post.md` Issue #4 |
| Fix socket type declaration: remove \Socket from union type | stream_socket_client() returns a PHP resource, not a sockets extension Socket object | `ref:findings/sugar-post.md` Issue #6 |
| Add connection timeout to stream_socket_client() | Without explicit timeout, unreachable SMTP servers cause indefinite hangs | `ref:findings/sugar-post.md` Issue #7 |
| Deduplicate addresses in withTo()/withCc()/withBcc() | Inconsistency: these methods allow duplicates but allRecipients() deduplicates | `ref:findings/sugar-post.md` Issue #11 |
| Use $_ENV or getenv($var, false) instead of getenv() | Modern PHP 8.3+ best practice; getenv() without second arg is discouraged | `ref:findings/sugar-post.md` Issue #15 |
| Replace dev-master dependencies with versioned releases | dev-master can introduce breaking changes; use ^1.0 constraints when available | `ref:findings/sugar-post.md` Issue #16 |
| hasExtension() uses str_contains which can false-match | str_contains on full EHLO response string instead of per-line parsing | `ref:findings/sugar-post.md` Issue #13 |
| Inline::cid should validate RFC 2392 Content-ID format | Any string is accepted; RFC 2392 requires valid mailto: URI format | `ref:findings/sugar-post.md` Issue #14 |
| startTlsIfNeeded() lacks test coverage | TLS upgrade path is critical for secure SMTP on port 465; no tests exist | `ref:findings/sugar-post.md` Issue #12 |

---

## Phase 1: Critical Issues [PENDING]

### 1.1 Fix Silent Failure When Attachment File Cannot Be Read

**Severity:** Critical

**Finding:** `src/Attachment.php:33-36` - When `fromPath()` cannot read a file, `$content` becomes `false` and gets stored. The `getContent()` method then returns `false` (coerced to empty string) with no indication of failure.

**Source Locations:**
- `sugar-post/src/Attachment.php:29-46` (fromPath)
- `sugar-post/src/Attachment.php:84-100` (getContent)
- `sugar-post/src/Attachment.php:66-77` (inline)

**Investigation Notes:**
- Current `fromPath()` at lines 34-36 uses error suppression and stores `null` when content is `false` (line 41: `content !== false ? $content : null`)
- Current `getContent()` at lines 89-98 already re-reads from path and throws RuntimeException if `$c === false`
- However, the `inline()` static factory (line 66-76) sets `content: null` but DOES NOT re-read in getContent() — it would return empty string
- The `withCid()` method (line 105-114) does not preserve content, only filename/path/mimeType/encoding/cid

**What Is Expected:**
1. `Attachment::inline()` should read the file content immediately (like fromPath does), not defer to getContent()
2. `getContent()` throws RuntimeException when attachment content was unreadable
3. Document behavior in docblock

**Why This Matters:** Emails sent with unreadable attachments silently drop the attachment content, which could cause missed invoices, missing documents, etc.

**Conditions for Success:**
- `Attachment::fromPath()` with unreadable file stores `null` content
- `getContent()` on unreadable path throws `RuntimeException` with localized message
- `Attachment::inline()` properly handles unreadable inline attachments
- Test verifies exception thrown for unreadable attachment

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/AttachmentEdgeTest.php
```

---

## Phase 2: High Severity Issues [PENDING]

### 2.1 SMTP sendRaw() Does Not Check fwrite Return Values

**Severity:** High

**Finding:** `src/SmtpTransport.php:322` - `\fwrite($this->socket, $data)` return value is ignored. `fwrite()` returns `false` on error or the number of bytes written (which may be less than requested).

**Source Location:** `sugar-post/src/SmtpTransport.php:317-323`

**Investigation Notes:**
- Current `sendRaw()` method (lines 317-323) has no return value check
- `fwrite()` can return false on error, or a value less than `strlen($data)` for partial writes
- The method is called for all SMTP commands: EHLO, AUTH, MAIL FROM, RCPT TO, DATA, QUIT
- Partial writes could cause protocol-level issues or silent data corruption

**What Is Expected:**
```php
private function sendRaw(string $data): void
{
    if ($this->socket === null) {
        throw new \RuntimeException(Lang::t('smtp.not_connected'));
    }
    $written = \fwrite($this->socket, $data);
    if ($written === false) {
        throw new \RuntimeException(Lang::t('smtp.write_error'));
    }
    if ($written < \strlen($data)) {
        throw new \RuntimeException(Lang::t('smtp.incomplete_write'));
    }
}
```

**Why This Matters:** Silent data loss during email sending could result in corrupted SMTP sessions or emails sent without complete content.

**Conditions for Success:**
- `fwrite()` return value is checked and RuntimeException thrown on failure
- Partial writes (written < strlen) throw RuntimeException
- New translation keys added to lang/en.php

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

### 2.2 SMTP readResponse() Silently Ignores Errors

**Severity:** High

**Finding:** `src/SmtpTransport.php:331-334` - `@\fgets($this->socket)` suppresses PHP warnings via `@`. Additionally, `fgets()` returning empty string `""` on timeout/closed connection would NOT trigger the `=== false` check, resulting in `$code = (int) substr("", 0, 3) = 0` which fails the expected code check — but with a misleading error message.

**Source Location:** `sugar-post/src/SmtpTransport.php:325-342`

**Investigation Notes:**
- Current `readResponse()` at lines 325-342 uses `@\fgets()` error suppression
- `fgets()` can return `false` on error, empty string `""` on EOF/timeout, or the line content
- Empty string check is NOT done; only `=== false` check exists

**What Is Expected:**
```php
private function readResponse(int $expectedCode): void
{
    if ($this->socket === null) {
        throw new \RuntimeException(Lang::t('smtp.not_connected'));
    }

    $line = \fgets($this->socket);  // No @ suppression
    if ($line === false) {
        throw new \RuntimeException(Lang::t('smtp.no_response'));
    }
    if ($line === '') {
        throw new \RuntimeException(Lang::t('smtp.empty_response'));
    }

    $this->lastResponse = \trim($line);
    $code = (int) \substr($this->lastResponse, 0, 3);
    if ($code !== $expectedCode) {
        throw new \RuntimeException(Lang::t('smtp.unexpected_response', ['response' => $this->lastResponse]));
    }
}
```

**Why This Matters:** @ suppression hides PHP warnings that could indicate serious socket issues. Timeout detection is unreliable.

**Conditions for Success:**
- `@` error suppression removed from `fgets()`
- Empty string case explicitly handled with clear error message
- New translation key `smtp.empty_response` added

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

## Phase 3: Medium Severity Issues [PENDING]

### 3.1 buildMimeMessage() Is Too Long

**Severity:** Medium

**Finding:** `src/SmtpTransport.php:226-311` - 85 lines handling multiple responsibilities: building headers, body parts, and attachments.

**Source Location:** `sugar-post/src/SmtpTransport.php:226-311`

**Investigation Notes:**
- Current `buildMimeMessage()` handles: headers (lines 231-246), body (lines 250-279), attachments (lines 281-306)
- The method is `protected` and used in `sendData()` at line 206
- Pattern in codebase shows `protected` visibility for methods that may be overridden

**What Is Expected:**
Refactor into smaller composable private methods:
- `private function buildHeaders(Email $email, string $boundary): array<string>` - handles From/To/Cc/Subject/MIME-Version/Content-Type headers
- `private function buildBody(Email $email, string $bodyBoundary): array<string>` - handles text/html body parts
- `private function buildAttachments(array<Attachment> $attachments, string $boundary): array<string>` - handles attachment MIME parts
- `buildMimeMessage()` orchestrates and joins these parts

**Why This Matters:** Single responsibility violation makes the code harder to test, maintain, and understand.

**Conditions for Success:**
- `buildMimeMessage()` reduced to ~30 lines
- Private helper methods extract: headers building, body building, attachment building
- Existing tests still pass (especially `testBuildMimeMessageNormalizesLineEndings` and `testUtf8SubjectIsRfc2047Encoded`)

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

### 3.2 No Async Transport Implementation

**Severity:** Medium

**Finding:** `SmtpTransport` uses blocking `stream_socket_client()` / `fgets()` / `fwrite()` calls. Since this is a ReactPHP ecosystem library, an `AsyncSmtpTransport` returning `PromiseInterface` would be more idiomatic.

**Source Location:**
- `sugar-post/src/SmtpTransport.php`
- `sugar-post/src/Transport.php`
- `candy-async/src/AsyncOps.php:86-102`

**Investigation Notes:**
- Current `Transport` interface at line 21 returns `void` from `send()`
- `AsyncOps::retry()` at `candy-async/src/AsyncOps.php:86-102` expects `PromiseInterface`
- `CancellationToken` callbacks exist but `onCancel()` is never called in SmtpTransport
- This is a larger architectural change

**What Is Expected:**
1. Create `AsyncSmtpTransport` class implementing async version of `Transport` interface
2. Add `AsyncTransport` interface that extends `Transport` with `sendAsync(): PromiseInterface`
3. `AsyncSmtpTransport::send()` returns `PromiseInterface`
4. Implement `AsyncOps::retry()` pattern for automatic retry with backoff

**Why This Matters:** ReactPHP ecosystem expects async operations. Current blocking I/O defeats the purpose of using ReactPHP.

**Conditions for Success:**
- New `AsyncSmtpTransport` class created
- `send()` returns `PromiseInterface`
- Implements `AsyncOps::retry()` pattern for retries
- Tests verify async behavior

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/ --filter Async
```

---

### 3.3 $socket Type Declaration Is Incorrect

**Severity:** Medium

**Finding:** `src/SmtpTransport.php:29-30` - `@var resource|\Socket|null` is incorrect. `stream_socket_client()` returns a PHP `resource`, not a `\Socket` object (which is from the sockets extension).

**Source Location:** `sugar-post/src/SmtpTransport.php:29-30`

**Investigation Notes:**
- Line 29: `/** @var resource|\Socket|null */`
- `\Socket` class is from PHP's sockets extension, not the stream resource from `stream_socket_client()`
- PHP Storm/static analyzers may warn about this type mismatch

**What Is Expected:**
```php
/** @var resource|null */
private $socket = null;
```

**Why This Matters:** Incorrect type declarations can cause static analysis warnings and mislead developers about the actual socket type.

**Conditions for Success:**
- Type declaration changed to `resource|null`
- No static analysis warnings related to socket type
- Existing tests pass

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

### 3.4 No Connection Timeout for SMTP

**Severity:** Medium

**Finding:** `src/SmtpTransport.php:96-117` - `stream_socket_client()` is called without a connection timeout parameter. Could hang indefinitely if SMTP server is unreachable.

**Source Location:** `sugar-post/src/SmtpTransport.php:96-117` (connect method)

**Investigation Notes:**
- `stream_socket_client()` at line 99-105 passes `$this->timeout` as the 4th argument (timeout), not connection timeout
- The 4th argument is the stream timeout, not a connection establishment timeout
- Connection timeout should be passed separately via `stream_context_create()`

**What Is Expected:**
```php
private function connect(): void
{
    $addr = "tcp://{$this->host}:{$this->port}";
    $context = \stream_context_create([
        'socket' => ['connect_timeout' => $this->timeout],
    ]);
    $this->socket = @\stream_socket_client(
        $addr,
        $errno,
        $errstr,
        $this->timeout,
        \STREAM_CLIENT_CONNECT,
        $context,
    );
    // ... rest of method
}
```

**Why This Matters:** Without connection timeout, a misconfigured or unreachable SMTP server causes the application to hang.

**Conditions for Success:**
- Connection attempts timeout appropriately
- Error message includes clear indication of connection timeout

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

## Phase 4: Low Severity Issues [PENDING]

### 4.1 Duplicated Address Format Extraction

**Severity:** Low

**Finding:** `Email::sanitizeAddr()` (line 95-117) and `SmtpTransport::bareAddr()` (line 368-375) extract bare addresses using near-identical regex patterns.

**Source Location:**
- `sugar-post/src/Email.php:95-117` (sanitizeAddr)
- `sugar-post/src/SmtpTransport.php:368-375` (bareAddr)

**Investigation Notes:**
- Both use `preg_match('/<([^>]+)>/', $addr, $matches)` to extract bare address from "Name <addr@host>" format
- `sanitizeAddr()` in Email class validates and sanitizes; `bareAddr()` in SmtpTransport extracts bare address for SMTP protocol
- These are in different classes with different responsibilities; merging may not be appropriate

**What Is Expected:**
- Create a shared utility function or static method in a common location (e.g., `SugarCraft\Core\Mail\AddressUtils`)
- Both classes use the shared utility
- Alternatively, keep as-is since they serve different purposes (document why duplication is acceptable)

**Why This Matters:** DRY principle violation; if email address extraction logic changes, both locations need updating.

**Conditions for Success:**
- Shared utility created and used by both Email and SmtpTransport
- Or documented rationale for why duplication is acceptable

---

### 4.2 Resend API Key Stored in Plain Text

**Severity:** Low

**Finding:** `ResendTransport.php:17` - The Resend API key is stored as a plain string property without environment variable injection.

**Source Location:** `sugar-post/src/ResendTransport.php:17-22`

**Investigation Notes:**
- Line 17: `private string $apiKey;`
- Constructor takes `string $apiKey` directly
- No environment variable support built in

**What Is Expected:**
```php
public function __construct(string $apiKey = '')
{
    if ($apiKey === '') {
        $apiKey = $_ENV['RESEND_API_KEY'] ?? \getenv('RESEND_API_KEY', true) ?: '';
    }
    $this->apiKey = $apiKey;
}
```

**Why This Matters:** Storing API keys in plain text in configuration files is a security anti-pattern.

**Conditions for Success:**
- ResendTransport accepts empty constructor and reads from environment
- bin/pop updated to use the same pattern (getenv replacement covered in 4.7)

---

### 4.3 withTo()/withCc()/withBcc() Don't Deduplicate

**Severity:** Low

**Finding:** If a recipient is added twice via `withTo()`, it will appear twice in the array while `allRecipients()` deduplicates. This is inconsistent behavior.

**Source Location:**
- `sugar-post/src/Email.php:161-164` (withTo)
- `sugar-post/src/Email.php:181-184` (withCc)
- `sugar-post/src/Email.php:186-189` (withBcc)
- `sugar-post/src/Email.php:246-253` (allRecipients)

**Investigation Notes:**
- `withTo()` uses `\array_merge($this->to, $to)` which preserves duplicates
- `allRecipients()` uses `\array_unique()` to deduplicate
- This inconsistency could lead to confusing behavior when iterating over `$email->to`

**What Is Expected:**
```php
public function withTo(string ...$to): self
{
    return $this->mutate(['to' => \array_unique(\array_merge($this->to, $to))]);
}
```

**Why This Matters:** Inconsistent deduplication behavior could cause confusion about recipient counts.

**Conditions for Success:**
- withTo(), withCc(), withBcc() deduplicate new addresses
- Existing tests still pass (particularly `testAllRecipientsDeduplicates`)

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/EmailTest.php
```

---

### 4.4 No Tests for startTlsIfNeeded()

**Severity:** Low

**Finding:** The TLS upgrade path in SmtpTransport is not exercised in any test. Critical for secure SMTP on port 465.

**Source Location:** `sugar-post/src/SmtpTransport.php:119-139`

**Investigation Notes:**
- `startTlsIfNeeded()` at lines 119-139 handles TLS upgrade
- No test file exercises this method
- Port 465 uses implicit TLS (connect-time encryption) vs STARTTLS on port 587

**What Is Expected:**
Add tests for:
- TLS flag set correctly for port 465 (already indirectly tested via `testTlsFlagSetForPort465`)
- STARTTLS extension detection triggers TLS upgrade path (requires mocking stream_socket_enable_crypto)
- TLS negotiation failure throws appropriate exception

**Why This Matters:** TLS upgrade is security-critical; untested code is broken code.

**Conditions for Success:**
- Tests cover TLS upgrade success path
- Tests cover TLS upgrade failure path
- `testTlsFlagSetForPort465` and `testTlsFlagNotSetForPort587` pass

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

### 4.5 hasExtension() Uses str_contains Could Produce False Positives

**Severity:** Low

**Finding:** `src/SmtpTransport.php:344-347` - Checks if extension name appears anywhere in EHLO response string, not line-by-line. Could match "SIZE" when "DSIZE" is present, etc.

**Source Location:** `sugar-post/src/SmtpTransport.php:344-347`

**Investigation Notes:**
- Line 346: `return \str_contains($this->lastResponse, $name);`
- `lastResponse` contains the full multi-line EHLO response
- `str_contains` could match substrings within line content (e.g., "LOGIN" could match within "PLAINLOGIN")

**What Is Expected:**
```php
private function hasExtension(string $name): bool
{
    $lines = \explode("\n", $this->lastResponse);
    foreach ($lines as $line) {
        $line = \trim($line);
        // Extension names are at the start of lines (e.g., "250-SIZE" or "250 AUTH")
        if (\str_starts_with($line, $name) || $line === $name) {
            return true;
        }
    }
    return false;
}
```

**Why This Matters:** False positive extension detection could cause incorrect protocol behavior.

**Conditions for Success:**
- Extension matching is line-by-line, not substring
- EHLO response parsing handles multi-line responses correctly
- Existing tests still pass

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

### 4.6 Missing Content-ID Format Validation

**Severity:** Low

**Finding:** `Attachment::inline()` accepts any string as `$cid` but RFC 2392 requires valid URI format.

**Source Location:** `sugar-post/src/Attachment.php:66-77`

**Investigation Notes:**
- `inline()` at line 66 accepts `$cid` parameter with no validation
- RFC 2392 specifies Content-ID format as `msg-id@addr-spec` or `cid:uri`
- Invalid CIDs could cause email client rendering issues

**What Is Expected:**
```php
public static function inline(string $path, string $cid, string $filename = null): self
{
    $name = $filename ?? \basename($path);
    // RFC 2392 Content-ID: must be a valid msg-id (addr-spec format) or cid:uri
    if (!\preg_match('/^(cid:[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+|[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+)$/', $cid)) {
        throw new \InvalidArgumentException(Lang::t('attachment.invalid_cid', ['cid' => $cid]));
    }
    return new self(
        filename:  $name,
        path:      $path,
        content:   null,
        mimeType:  self::detectMimeType($path),
        encoding:  'base64',
        cid:       $cid,
    );
}
```

**Why This Matters:** Invalid Content-ID format could cause inline attachments to fail rendering in email clients.

**Conditions for Success:**
- Invalid CID format throws InvalidArgumentException
- Valid CID formats accepted (both with and without cid: prefix)

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/AttachmentTest.php
```

---

### 4.7 Bin pop Uses getenv() Instead of $_ENV

**Severity:** Low

**Finding:** `bin/pop:128,136,165,169,173-175` - Uses `\getenv()` which is discouraged in modern PHP 8.3+. `$_ENV` or `getenv('VAR', false)` is preferred.

**Source Location:** `sugar-post/bin/pop:126-180`

**Investigation Notes:**
- Multiple `getenv()` calls without second parameter
- `getenv()` without second arg returns false if not set, but behavior varies
- `$_ENV` is the superglobal array, more consistent

**What Is Expected:**
```php
$from = $opts['from'] ?? ($_ENV['POP_FROM'] ?? null) ?: 'unknown@localhost';
$signature = $_ENV['POP_SIGNATURE'] ?? null;

function buildTransport(): Transport
{
    if (($apiKey = $_ENV['RESEND_API_KEY'] ?? \getenv('RESEND_API_KEY', true)) !== false && $apiKey !== '') {
        return new ResendTransport($apiKey);
    }
    // ... etc
}
```

**Why This Matters:** Modern PHP 8.3+ best practice; `getenv()` without second arg behavior is inconsistent across PHP versions.

**Conditions for Success:**
- All `getenv()` calls updated to use `$_ENV` or `getenv($var, false)`
- CLI still functions correctly

**Verification Command:**
```bash
cd sugar-post && php bin/pop --help
```

---

## Phase 5: Compatibility Issues [PENDING]

### 5.1 Depends on dev-master of candy-async and candy-core

**Severity:** Medium

**Finding:** `composer.json:28-29` - Using `dev-master` as version constraint means breaking changes in those packages could break sugar-post without warning.

**Source Location:** `sugar-post/composer.json:26-30`

**Investigation Notes:**
- Lines 28-29: `"sugarcraft/candy-async": "dev-master"`, `"sugarcraft/candy-core": "dev-master"`
- Path repositories are set up correctly for local development
- However, if these packages publish proper releases, sugar-post should depend on versioned releases

**What Is Expected:**
1. Check if candy-async and candy-core have released versions
2. If so, update composer.json to use `^1.0` or similar versioned constraints
3. If not, document why dev-master is necessary and add TODO comment

**Why This Matters:** Relying on dev-master for core dependencies is risky for production use.

**Conditions for Success:**
- Dependencies use versioned constraints when available
- If dev-master required, documented rationale with TODO for future version bump

**Verification Command:**
```bash
cd sugar-post && composer validate
```

---

## Phase 6: Async Pattern Improvements [PENDING]

### 6.1 SmtpTransport Cannot Use AsyncOps::retry()

**Severity:** Medium

**Finding:** `src/SmtpTransport.php:63` - `send()` is synchronous and returns `void`. Cannot leverage `AsyncOps::retry()` which expects a `PromiseInterface`.

**Source Location:** `sugar-post/src/SmtpTransport.php:63-85`

**Investigation Notes:**
- `send()` method returns `void` and uses try/catch for error handling
- `AsyncOps::retry()` at `candy-async/src/AsyncOps.php:86-102` expects a `callable(): PromiseInterface`
- Without async rewrite, retry logic must be implemented synchronously

**What Is Expected:**
- Option A: Create AsyncSmtpTransport that returns Promise (see 3.2)
- Option B: Implement sync retry wrapper for SmtpTransport using existing CancellationToken

**Why This Matters:** Network failures during email sending are common; retry logic is essential for reliability.

**Conditions for Success:**
- SmtpTransport can retry on transient failures (sync or async)
- CancellationToken properly cancels in-progress retry attempts

---

### 6.2 CancellationToken Callbacks Never Fire in SmtpTransport

**Severity:** Low

**Finding:** `CancellationToken::onCancel()` is never called in `SmtpTransport`. Mid-operation cancellation is impossible without an async rewrite.

**Source Location:** `sugar-post/src/SmtpTransport.php:63-85`

**Investigation Notes:**
- `send()` method checks `isCancelled()` at line 66
- But never registers `onCancel()` callback to fire during long operations
- With blocking I/O, mid-operation cancellation is impossible anyway

**What Is Expected:**
- Document that CancellationToken provides best-effort pre-check cancellation only
- Add docblock explaining the limitation
- Or implement async transport where cancellation can interrupt between async operations

**Why This Matters:** Users may expect cancellation to work mid-send, but without async, it cannot.

**Conditions for Success:**
- Code documents the limitation in docblock
- Or async implementation provides true cancellation

---

## Phase 7: Test Coverage Additions [PENDING]

### 7.1 Add Tests for Attachment Edge Cases

**Investigation Notes:**
- Existing `AttachmentTest.php` covers basic fromContent/fromPath
- `AttachmentEdgeTest.php` covers some edge cases including unreadable path
- Need tests for: inline attachment unreadable path, CID validation

**What Is Expected:**
```php
public function testInlineAttachmentGetContentThrowsOnUnreadable(): void
{
    $att = Attachment::inline('/nonexistent/path/img.png', 'img-cid');
    $this->expectException(\RuntimeException::class);
    $att->getContent();
}

public function testInlineAttachmentRejectsInvalidCid(): void
{
    $this->expectException(\InvalidArgumentException::class);
    Attachment::inline('/tmp/img.png', 'not-a-valid-cid');
}
```

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/AttachmentEdgeTest.php
```

---

### 7.2 Add Tests for SmtpTransport TLS Upgrade

**What Is Expected:**
- Test that startTlsIfNeeded() is called when tls flag is set (port 465)
- Test that STARTTLS extension triggers TLS upgrade
- Test TLS negotiation failure handling

**Verification Command:**
```bash
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
```

---

## Summary

| Phase | Item | Severity | Status |
|-------|------|----------|--------|
| 1 | 1.1 Attachment silent failure | Critical | Pending |
| 2 | 2.1 fwrite return value check | High | Pending |
| 2 | 2.2 fgets error suppression | High | Pending |
| 3 | 3.1 buildMimeMessage refactor | Medium | Pending |
| 3 | 3.2 Async transport | Medium | Pending |
| 3 | 3.3 Socket type declaration | Medium | Pending |
| 3 | 3.4 Connection timeout | Medium | Pending |
| 4 | 4.1 Address extraction duplication | Low | Pending |
| 4 | 4.2 Resend API key plain text | Low | Pending |
| 4 | 4.3 withTo/withCc/withBcc deduplication | Low | Pending |
| 4 | 4.4 startTlsIfNeeded tests | Low | Pending |
| 4 | 4.5 hasExtension false positives | Low | Pending |
| 4 | 4.6 Content-ID validation | Low | Pending |
| 4 | 4.7 getenv vs $_ENV | Low | Pending |
| 5 | 5.1 dev-master dependencies | Medium | Pending |
| 6 | 6.1 AsyncOps::retry compatibility | Medium | Pending |
| 6 | 6.2 CancellationToken callbacks | Low | Pending |
| 7 | 7.1 Attachment edge case tests | Low | Pending |
| 7 | 7.2 TLS upgrade tests | Low | Pending |

---

## Verification Commands

Run all tests to verify nothing is broken:
```bash
cd sugar-post && vendor/bin/phpunit
```

Run specific test files:
```bash
cd sugar-post && vendor/bin/phpunit tests/AttachmentTest.php
cd sugar-post && vendor/bin/phpunit tests/AttachmentEdgeTest.php
cd sugar-post && vendor/bin/phpunit tests/SmtpTransportTest.php
cd sugar-post && vendor/bin/phpunit tests/EmailTest.php
```

---

## Notes

- 2026-06-30: Implementation plan created based on code review findings in `findings/sugar-post.md`
- Some findings (e.g., Issue #1 - Attachment silent failure) appear partially addressed in current code, but plan documents expected behavior per findings document
- Async transport implementation (Issues #3.2, #5.2, #6.1) is a larger architectural change that may warrant its own feature branch
- The CancellationToken callbacks issue (#6.2) is architectural limitation that requires async rewrite to fully address
- Order of implementation recommended: Critical → High → Medium → Low, with async work as separate track
