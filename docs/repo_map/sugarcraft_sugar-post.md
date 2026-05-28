# SugarCraft/sugar-post

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-post
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1.0 Ready
- **Upstream:** [charmbracelet/pop](https://github.com/charmbracelet/pop) (Go, ~2.8k stars, MIT)
- **Description:** PHP port of charmbracelet/pop — send emails from PHP via Resend API or direct SMTP. Compose from STDIN, attach files, CC/BCC routing, HTML + plain-text multipart, fluent immutable interface.

---

## Architecture Overview

### Package Structure (6 source files)

```
sugar-post/src/
  Email.php           — Immutable value object (271 lines)
  Attachment.php     — Immutable attachment (174 lines)
  Transport.php       — Interface (22 lines)
  ResendTransport.php — Resend API transport (116 lines)
  SmtpTransport.php   — Raw SMTP transport (323 lines)
  Mailer.php          — High-level sender (45 lines)
sugar-post/bin/pop    — CLI binary (221 lines)
sugar-post/lang/      — 16 locales (en.php + 15 translations)
```

### Dependency Graph

```
sugar-post
  └── sugarcraft/candy-core  (dev-master, path-repo)
       └── sugarcraft/candy-shine (styling)
```

No heavy dependencies. Pure PHP with minimal requirements (cURL for Resend, stream_socket_client for SMTP).

---

## Core Component Analysis

### 1. Email (`src/Email.php` — 271 lines)

**Immutable value object** holding all email fields as `readonly` properties.

**Properties:**
```php
public readonly array  $from;         // list<string>
public readonly array  $to;           // list<string>
public readonly array  $cc;           // list<string>
public readonly array  $bcc;          // list<string>
public readonly ?string $subject;
public readonly ?string $body;         // Plain-text body
public readonly ?string $htmlBody;     // HTML alternative
public readonly ?string $replyTo;
public readonly array  $attachments;   // list<Attachment>
public readonly ?string $signature;    // Appended to body
```

**Constructor (lines 41–63):** Trims all address fields via `array_map('trim', $from)`.

**Factory methods:**
- `Email::make($from, $to, $subject, $body)` — Variadic convenience for simple emails
- `Email::make('a@b.com', 'c@d.com')` → single-sender/single-recipient shortcut

**Fluent builders (with* methods, lines 86–214):**
```php
->withFrom(string $from): self
->withTo(string ...$to): self      // merges with existing to
->withSubject(string): self
->withBody(string): self
->withHtmlBody(string): self
->withCc(string ...$cc): self       // merges
->withBcc(string ...$bcc): self    // merges
->withReplyTo(string): self
->withAttachment(string $filename, string $path = null): self  // from path or content
->withInlineAttachment(string $path, string $cid, string $filename = null): self
->withSignature(string): self
```

**Derived accessors:**
- `bodyWithSignature(): ?string` — Returns body + "\n\n" + signature (line 223)
- `allRecipients(): list<string>` — to + cc + bcc deduplicated (line 239)

**Private helper (line 253):** `with(string $prop, mixed $value)` for simple single-field replacements.

**Comparison to upstream Go pop:**
- Go `pop` uses a `NewEmail()` constructor struct with all fields, then builder-style `With*()` methods
- PHP port uses named constructor params (PHP 8 named args) as primary API
- Go has a `Send()` method directly on Email that auto-selects transport; PHP separates `Mailer::send()`
- Go has Gmail smart-default detection in SMTP; PHP does not (the CLI does via env check in `bin/pop`)

### 2. Attachment (`src/Attachment.php` — 174 lines)

**Immutable attachment** with three factory methods:

```php
// From disk file — auto-detects MIME
Attachment::fromPath(string $path, string $filename = null): self

// From raw bytes — explicit MIME required
Attachment::fromContent(string $content, string $filename, string $mimeType = 'application/octet-stream'): self

// Inline/embedded image
Attachment::inline(string $path, string $cid, string $filename = null): self
```

**Properties:**
```php
public readonly string      $filename;
public readonly ?string     $path;       // null if from content
public readonly ?string     $content;    // null if from path
public readonly string      $mimeType;
public readonly string      $encoding;   // always 'base64'
public readonly ?string     $cid;        // Content-ID for inline
```

**Lazy content loading (line 82):** `getContent()` reads from disk only when called, not at construction:
```php
public function getContent(): string {
    if ($this->content !== null) { return $this->content; }
    if ($this->path !== null) {
        $c = @\file_get_contents($this->path);  // suppressed warning for missing files
        return $c !== false ? $c : '';
    }
    return '';
}
```

**MIME detection (line 128):** Two-tier detection:
1. `mime_content_type()` if available and not `application/octet-stream`
2. Extension-based fallback using `EXT_TO_MIME` map of 35 common extensions (line 143)

**MIME type map (selection):**
```php
'txt'=>'text/plain', 'pdf'=>'application/pdf',
'png'=>'image/png', 'jpg'=>'image/jpeg', 'gif'=>'image/gif', 'webp'=>'image/webp', 'svg'=>'image/svg+xml',
'doc'=>'application/msword', 'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
'xls'=>'application/vnd.ms-excel', 'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
'ppt'=>'application/vnd.ms-powerpoint', 'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
```

**CID mutation (line 100):** `withCid(string $cid): self` for inline embedding.

**Error handling:** File read failures (missing files) return null content and empty string from `getContent()` — no exceptions thrown at construction.

**Comparison to upstream Go pop:**
- Go uses `os.ReadFile` which panics on missing files; PHP suppresses and gracefully degrades
- Go uses `http.DetectContentType` for sniffing; PHP uses `mime_content_type` + extension map
- Go inline attachments use `attachment{...}` struct directly; PHP has `Attachment::inline()` factory

### 3. Transport Interface (`src/Transport.php` — 22 lines)

Minimal interface defining the transport contract:

```php
interface Transport {
    public function send(Email $email): void;   // throws \RuntimeException on failure
    public function name(): string;            // for logging/debugging
}
```

**Design note:** The interface is intentionally minimal. No `supports(Email): bool` or priority ranking. Choice of transport is made at Mailer construction time, not runtime dispatch.

### 4. ResendTransport (`src/ResendTransport.php` — 116 lines)

**Sends email via Resend REST API** (`https://api.resend.com/emails`).

**Constructor:** Takes `string $apiKey` only.

**send() method (line 23):**
```php
$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $this->apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
// Throws RuntimeException on curl error or non-2xx response
```

**Payload builder (line 67):** `buildPayload(Email $email): array`
```php
[
    'from'    => $email->from[0] ?? 'unknown@localhost',
    'to'      => $email->to,
    'subject' => $email->subject ?? '(no subject)',
    'html'    => $email->htmlBody,       // set if present
    'text'    => $email->bodyWithSignature(),  // set if htmlBody present or plain body
    'cc'      => implode(', ', $email->cc),    // if non-empty
    'bcc'     => implode(', ', $email->bcc),   // if non-empty
    'reply_to'=> $email->replyTo,               // if set
    'attachments' => [                          // if any
        ['filename' => $att->filename, 'content' => base64_encode($att->getContent())]
    ],
]
```

**Comparison to upstream Go pop:**
- Go uses `resend.SendEmailRequest` struct with `From`, `To[]`, `Cc[]`, `Bcc[]`, `Subject`, `HtmlBody`, `TextBody`, `ReplyTo`, `Attachments[]`
- Go attachments use `resend.Attachment{Filename, Content}` (base64-encoded []byte)
- Go uses official `resend-go` client library; PHP uses raw curl — no SDK dependency
- PHP does NOT implement Gmail smart-default detection (Go has this in `sendSMTPEmail()`)

### 5. SmtpTransport (`src/SmtpTransport.php` — 323 lines)

**Raw SMTP client** — implements SMTP protocol directly over `stream_socket_client`, no external dependencies.

**Constructor parameters:**
```php
public function __construct(
    string $host,
    int $port = 587,
    string $username = '',
    string $password = '',
    int $timeout = 30,
)
```

**TLS behavior (line 40):** `tls = ($port === 465)`. Port 465 = implicit TLS. Port 587 = STARTTLS.

**send() flow (line 43):**
```
connect() → helo() → startTlsIfNeeded() → authenticateIfNeeded()
→ sendMailFrom() → sendRcptTo() (for each allRecipients)
→ sendData() → quit()
```

**connect() (line 71):**
```php
$addr = "tcp://{$this->host}:{$this->port}";
$this->socket = @stream_socket_client($addr, $errno, $errstr, $this->timeout, ...);
stream_set_timeout($this->socket, $this->timeout);
$this->readResponse(220);
$this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
$this->readResponse(250);
```

**startTlsIfNeeded() (line 94):**
```php
if ($this->tls || $this->hasExtension('STARTTLS')) {
    $this->sendRaw("STARTTLS\r\n");
    $this->readResponse(220);
    $context = stream_context_get_default(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    // Re-EHLO after TLS
    $this->sendRaw("EHLO {$this->getHeloHost()}\r\n");
    $this->readResponse(250);
}
```

**authenticateIfNeeded() (line 119):** AUTH LOGIN method:
```
AUTH LOGIN
334 (username base64 prompt) → send base64(username)
334 (password base64 prompt) → send base64(password)
235 (success)
```

**buildMimeMessage() (line 193):** Constructs RFC 2821 MIME message:
```php
$boundary = bin2hex(random_bytes(16));   // MIME boundary

// Headers
From: addrListHeader($email->from)
To: addrListHeader($email->to)
Cc: ... (if cc non-empty)
Subject: ...
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="$boundary"
Reply-To: ... (if set)

// Body part
if ($htmlBody !== null) {
    Content-Type: multipart/alternative; boundary="$bodyBoundary"
    --$bodyBoundary
    Content-Type: text/plain; charset="utf-8"
    Content-Transfer-Encoding: 7bit
    [plain text body with signature]

    --$bodyBoundary
    Content-Type: text/html; charset="utf-8"
    Content-Transfer-Encoding: 7bit
    [html body]
    --$bodyBoundary--
}

// Attachment parts
foreach ($email->attachments as $att) {
    --$boundary
    Content-Type: {mimeType}; name="{filename}"
    Content-Transfer-Encoding: base64
    Content-Disposition: attachment; filename="{filename}"
    [Content-ID: <{cid}>]  (if inline)
    [base64 encoded content]
}

--$boundary--
```

**Read response handling (line 290):** Reads single-line response, checks 3-digit code against expected:
```php
$line = fgets($this->socket);
$code = (int) substr(trim($line), 0, 3);
if ($code !== $expectedCode) { throw new RuntimeException(...); }
```

**hasExtension() (line 309):** Checks if last EHLO response contained an extension name (e.g., `STARTTLS`, `AUTH`, `SIZE`).

**Error handling:** All socket errors throw `\RuntimeException` via Lang::t() with i18n messages.

**Comparison to upstream Go pop:**
- Go uses `xhit/go-simple-mail/v2` library for SMTP; PHP implements protocol directly
- Go supports `sendSMTPEmail()` with `smtp.Username/Password/TLSConfig`
- PHP uses `stream_socket_client` for connection; Go uses `net/smtp.Dial`
- Both implement AUTH LOGIN and STARTTLS
- Go uses `strings.Builder` for MIME; PHP uses `\implode("\r\n", $lines)`
- PHP does not implement the Gmail smart-default (Go auto-fills `smtp.gmail.com:587` for `@gmail.com` usernames)

### 6. Mailer (`src/Mailer.php` — 45 lines)

**High-level sender** wrapping a Transport with validation:

```php
final class Mailer {
    private Transport $transport;

    public function send(Email $email): void {
        if ($email->to === [] && $email->cc === [] && $email->bcc === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_recipient'));
        }
        if ($email->from === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }
        $this->transport->send($email);
    }

    public function transportName(): string { return $this->transport->name(); }
}
```

**Validation:** Checks at least one recipient exists and from address is set. Note: SMTP transport would fail anyway at RCPT TO stage, but this provides fast validation before network I/O.

**Comparison to upstream Go pop:**
- Go `pop` has `pop.Send()` that auto-selects transport and sends directly (no separate Mailer class)
- Go does validation inline; PHP separates Mailer as a distinct layer
- Go has `pop.SendEmail()` for direct send without TUI; PHP's Mailer is the equivalent

### 7. CLI (`bin/pop` — 221 lines)

**Standalone CLI binary** for sending email from terminal/shell scripts.

**Argument parsing (line 69):** Manual parsing without getopt (cross-platform):
```
--from / -f <addr>       Sender
--to / -t <addr>          Recipient (may repeat, fills from positional if empty)
--cc <addr>              CC (may repeat)
--bcc <addr>             BCC (may repeat)
--subject / -s <s>        Subject
--body <text>            Explicit body text
--attach / -a <file>       Attachment (may repeat)
--reply-to <addr>         Reply-to
--html <html>            HTML body
--help / -h               Show help
```

**STDIN compose (line 32):** If `--body` not given, reads all of STDIN and trims:
```php
if ($body === '' && !feof(STDIN)) {
    while (!feof(STDIN)) { $body .= fgets(STDIN); }
    $body = trim($body);
}
```

**Transport auto-selection (line 166):**
```php
function buildTransport(): Transport {
    if ($apiKey = getenv('RESEND_API_KEY')) !== false && $apiKey !== '') {
        return new ResendTransport($apiKey);
    }
    $host = getenv('POP_SMTP_HOST') ?: '';
    if ($host !== '') {
        return new SmtpTransport(
            host: $host,
            port: (int)(getenv('POP_SMTP_PORT') ?: 587),
            username: getenv('POP_SMTP_USERNAME') ?: '',
            password: getenv('POP_SMTP_PASSWORD') ?: '',
        );
    }
    throw new \RuntimeException(Lang::t('cli.no_transport'));
}
```

**Email building (line 129):** Merges CLI args + env vars (POP_FROM, POP_SIGNATURE):
```php
$email = new Email(
    from:    [$from],
    to:      $to,
    subject: $opts['subject'] ?? '(no subject)',
    body:    $body,
    cc:      $opts['cc'] ?? [],
    bcc:     $opts['bcc'] ?? [],
    htmlBody: $opts['html'] ?? null,
    replyTo: $opts['reply_to'] ?? null,
);
if ($signature !== null) { $email = $email->withSignature($signature); }
```

**Comparison to upstream Go pop CLI:**
- Go uses Cobra for argument parsing; PHP uses manual parsing
- Go has `pop < message.md --from ... --to ...` pipe mode via `hasStdin()` check
- Go has interactive TUI mode (Bubble Tea); PHP has no TUI (this is a library-only port)
- Go has `pop man` for man page generation; PHP has no equivalent
- Go has `pop completion` for shell completions; PHP has no equivalent
- Go saves failed emails to temp files; PHP has no equivalent

---

## Test Suite Analysis

**5 test files, ~373 lines total:**

### EmailTest (`tests/EmailTest.php` — 156 lines)
- Construction: basic, whitespace trimming
- with* builders: from, to, cc, bcc, subject, body, htmlBody, replyTo, signature
- Derived: bodyWithSignature (with/without body, with/without signature), allRecipients (dedup)

### SmtpTransportTest (`tests/SmtpTransportTest.php` — 58 lines)
- Name formatting: host:port, custom port, default port
- Constructor credential storage
- TLS flag: port 465 sets tls=true, port 587=tls=false

### ResendTransportTest (`tests/ResendTransportTest.php` — 36 lines)
- Name returns 'resend'
- Constructor stores API key

### AttachmentTest (`tests/AttachmentTest.php` — 93 lines)
- fromContent: filename, mimeType, encoding, content, cid=null
- fromPath: filename, content
- fromPath with custom filename
- MIME detection (PNG header detection)
- inline attachment: cid, filename
- withCid: returns new instance with cid
- inline overrides cid on fromContent

### EmailFactoryTest (`tests/EmailFactoryTest.php` — 77 lines)
- Email::make: full args, minimal args
- withAttachment: adds attachment, uses path for MIME, filename-only fallback
- Edge: AttachmentEdgeTest (empty content, missing file, unknown extension, explicit MIME)

**Notable gaps:**
- No mock tests for actual send() behavior (no HTTP mock for ResendTransport, no socket mock for SmtpTransport)
- No tests for CC/BCC merge behavior in Email
- No tests for MIME message output (snapshot or otherwise)
- No integration tests

---

## i18n Support

**16 locales** in `lang/`: ar, cs, de, es, fr, it, ja, ko, nl, pl, pt, pt-br, ru, tr, zh-cn, en (default).

**Translation keys** (lang/en.php, 36 lines):
- `mailer.no_recipient` — "Email must have at least one recipient (to, cc, or bcc)"
- `mailer.no_from` — "Email must have a from address"
- `smtp.send_failed` — "SMTP send failed: {message}"
- `smtp.connect_failed` — "Cannot connect to {addr}: {errstr} ({errno})"
- `smtp.starttls_failed` — "STARTTLS negotiation failed"
- `smtp.not_connected` — "Not connected"
- `smtp.no_response` — "Server sent no response"
- `smtp.unexpected_response` — "SMTP unexpected response: {response}"
- `resend.network_error` — "Resend network error: {error}"
- `resend.api_error` — "Resend API error ({status}): {body}"
- `cli.error`, `cli.transport_error`, `cli.send_failed`, `cli.email_sent`
- `cli.no_to_recipient`, `cli.attachment_not_found`, `cli.no_transport`

**Lang.php facade (src/Lang.php, 22 lines):**
```php
final class Lang extends BaseLang {
    protected const NAMESPACE = 'post';
    protected const DIR = __DIR__ . '/../lang';
}
```
Delegates to `SugarCraft\Core\I18n\T` with 'post' namespace baked in.

---

## Innovation Points (SugarCraft Enhancements Over Upstream Go pop)

1. **Immutable Email value object** — PHP's readonly properties enforce immutability at language level; Go's pop uses mutable struct with With*() returning new struct (potential for accidental mutation)

2. **Lazy attachment content loading** — `Attachment::getContent()` only reads file when needed; Go reads entire file into memory at `makeAttachments()` call

3. **Graceful file-not-found handling** — `fromPath()` with missing file returns null content and empty string; Go panics with `os.ReadFile` error

4. **MIME type fallback chain** — PHP tries `mime_content_type()` first, then 35-entry extension map; Go uses single `http.DetectContentType` which is less accurate for common types

5. **Minimal transport interface** — `Transport` interface is 2 methods; Go mixes transport selection into `pop.Send()` and `NewEmail()` with env var checks

6. **Transport-agnostic Mailer validation** — Mailer checks recipient/from before calling transport; Go sends directly without a separate validation layer

7. **STDIN body for CLI** — clean shell pipeline composition; Go also supports this but the PHP implementation is cleaner (explicit feof check vs Go's stdin detection)

8. **Fluent attachment building** — `->withAttachment('name', 'path')` chains naturally; Go's `WithAttachments()` takes a slice of paths

9. **Signature append helper** — `bodyWithSignature()` produces body + "\n\n" + signature; Go has no equivalent and manually appends in the send path

10. **No external SMTP library dependency** — `stream_socket_client` + raw protocol implementation vs Go's `xhit/go-simple-mail/v2` dependency

---

## Gaps vs Upstream Go pop

1. **No TUI** — Go pop is a full Bubble Tea TUI application; sugar-post is library-only
2. **No Markdown → HTML conversion** — Go uses goldmark; PHP has no markdown rendering
3. **No Gmail smart defaults** — Auto-detecting `@gmail.com` and pre-filling `smtp.gmail.com:587`
4. **No failed-email temp file recovery** — Go saves failed email bodies to `pop-YYYY-MM-DD-*.txt`
5. **No shell completion** — Go has `pop completion bash/zsh/fish`
6. **No man page generation** — Go has `pop man`
7. **No interactive filepicker** — Go uses Bubble Tea's filepicker; PHP has no TUI for this
8. **No CC/BCC hiding** — Go hides Bcc field by default; PHP sends all addresses explicitly
9. **No SMTP `localhost` fallback** — Go has `gmailSuffix`/`gmailSMTPHost`/`gmailSMTPPort` constants

---

## File References

### Source Files
- `/home/sites/sugarcraft/sugar-post/src/Email.php` — 271 lines
- `/home/sites/sugarcraft/sugar-post/src/Attachment.php` — 174 lines
- `/home/sites/sugarcraft/sugar-post/src/Transport.php` — 22 lines
- `/home/sites/sugarcraft/sugar-post/src/ResendTransport.php` — 116 lines
- `/home/sites/sugarcraft/sugar-post/src/SmtpTransport.php` — 323 lines
- `/home/sites/sugarcraft/sugar-post/src/Mailer.php` — 45 lines
- `/home/sites/sugarcraft/sugar-post/bin/pop` — 221 lines
- `/home/sites/sugarcraft/sugar-post/src/Lang.php` — 22 lines

### Test Files
- `/home/sites/sugarcraft/sugar-post/tests/EmailTest.php` — 156 lines
- `/home/sites/sugarcraft/sugar-post/tests/AttachmentTest.php` — 93 lines
- `/home/sites/sugarcraft/sugar-post/tests/EmailFactoryTest.php` — 77 lines
- `/home/sites/sugarcraft/sugar-post/tests/AttachmentEdgeTest.php` — 49 lines
- `/home/sites/sugarcraft/sugar-post/tests/SmtpTransportTest.php` — 58 lines
- `/home/sites/sugarcraft/sugar-post/tests/ResendTransportTest.php` — 36 lines

### Examples
- `/home/sites/sugarcraft/sugar-post/examples/basic.php` — Resend API quickstart
- `/home/sites/sugarcraft/sugar-post/examples/smtp.php` — SMTP with credentials
- `/home/sites/sugarcraft/sugar-post/examples/attachments.php` — File attachment demo
- `/home/sites/sugarcraft/sugar-post/examples/html-email.php` — HTML + CC/BCC
- `/home/sites/sugarcraft/sugar-post/examples/pipeline.php` — STDIN compose
- `/home/sites/sugarcraft/sugar-post/examples/showcase.php` — Full showcase

### i18n
- `/home/sites/sugarcraft/sugar-post/lang/en.php` — 36 keys
- `/home/sites/sugarcraft/sugar-post/lang/` — 15 additional locales

### Config
- `/home/sites/sugarcraft/sugar-post/phpunit.xml` — PHPUnit 10 config
- `/home/sites/sugarcraft/sugar-post/composer.json` — Package metadata

---

## Analysis

**sugar-post** is a focused, well-structured PHP port of the email-sending portion of charmbracelet/pop. It deliberately omits the TUI (Bubble Tea interactive interface) and focuses on the library-level email composition and sending logic, which is what most PHP applications need.

**Strengths:**
- Clean immutable Email/Attachment value objects with readonly properties
- Dual transport abstraction (Resend API + raw SMTP) with a minimal interface
- Pure PHP SMTP implementation without external dependencies
- Full CC/BCC/Reply-To support with proper MIME multipart construction
- Graceful error handling with i18n error messages
- 16-language i18n support
- STDIN compose for shell pipeline use
- Lazy attachment content loading
- No external SMTP library dependency (self-contained protocol implementation)

**Design decisions consistent with SugarCraft patterns:**
- `final` classes throughout
- `declare(strict_types=1)` on every file
- `readonly` properties for immutable state
- `with*()` fluent builders returning new instances
- Private `mutate()` helper pattern for immutable updates
- PSR-4 namespaced under `SugarCraft\Post`
- PHPUnit 10 tests with behavior/coercion patterns
- `Lang::t()` wrapping for i18n

**Weaknesses:**
- No mock-based tests for send() behavior (transport tests only cover name() and constructor)
- No MIME message snapshot tests
- No markdown support (upstream goldmark pipeline)
- No Gmail smart defaults
- No failed-email recovery (temp file saving)
- SmtpTransport reads entire attachment content into memory (same as Go)
- No SMTP Keep-Alive or connection pooling
- No async/ReactPHP support for concurrent sending

**Strategic position:** sugar-post fills a genuine gap in the PHP ecosystem — a lightweight, dependency-free email sending library with both SMTP and Resend API support. It is not trying to be a full邮件 client (no TUI), focusing instead on the library portion that PHP applications actually need. The pure-PHP SMTP implementation is particularly valuable as it removes the need for `ext-mail` or external libraries like PHPMailer/SwiftMailer for simple use cases.

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/charmbracelet_pop.md` — Primary upstream (Go, full TUI email client)
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-shell.md` — Shell wrapper patterns relevant to CLI design
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-shine.md` — Styling, note: upstream pop uses goldmark for Markdown→ANSI (related to HTML body concept)
