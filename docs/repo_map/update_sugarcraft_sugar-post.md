# Overview
sugar-post is a PHP email sending library ported from charmbracelet/pop (Go). It provides immutable Email/Attachment value objects, dual transport abstraction (Resend API + raw SMTP), and a CLI binary. The library is well-structured with proper i18n (16 locales), but its primary opportunity is expanding beyond send-only to become a comprehensive email composition pipeline. Biggest gaps vs ecosystem: no markdown rendering pipeline, no headless/non-interactive mode, no failed-email recovery, no Gmail smart defaults, no MIME message output for piping.

# Internal Capability Summary

## Architecture
- **6 source files**: Email.php (271L), Attachment.php (174L), Transport.php (22L), ResendTransport.php (116L), SmtpTransport.php (323L), Mailer.php (45L)
- **CLI binary**: bin/pop (221L) — manual argument parsing, no getopt
- **Transport interface**: Minimal 2-method contract (`send()`, `name()`)
- **Immutable pattern**: readonly properties + with*() builders returning new instances

## Current Features
- From/To/Cc/Bcc/ReplyTo/Subject/Body/HTML body + attachments
- Inline attachments (CID references)
- STDIN body for shell pipelines
- Env var configuration (RESEND_API_KEY, POP_SMTP_*)
- TLS (port 465 implicit, 587 STARTTLS)
- AUTH LOGIN authentication
- MIME multipart construction (RFC 2821)
- 16-locale i18n with Lang::t() facade
- Lazy attachment content loading
- MIME detection via mime_content_type + extension fallback map (35 extensions)

## Strengths
- Pure PHP SMTP implementation (no external dependencies)
- Immutable Email/Attachment value objects with PHP readonly
- Graceful file-not-found handling (returns empty string vs panic)
- Dual transport with minimal interface
- Clean separation: Email composition → Mailer validation → Transport delivery
- Full CC/BCC routing with proper MIME construction

## Weaknesses
- No markdown rendering (upstream uses goldmark)
- No Gmail smart defaults (auto-detect @gmail.com → smtp.gmail.com:587)
- No failed-email temp file recovery
- No shell completion scripts
- No man page generation
- No TTY detection for headless vs interactive use
- No raw MIME message output for piping to other agents (msmtp, etc.)
- No async/ReactPHP support for concurrent sending
- No SMTP connection pooling/keep-alive
- Transport tests only cover name() and constructor — no mock send() behavior
- No MIME message snapshot tests

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet_pop.md` + `pr_charmbracelet_pop.md` | Primary upstream | Gmail smart defaults, markdown pipeline, failed-email recovery, headless mode, env-var pattern | CRITICAL |
| `charmbracelet_bubbletea.md` + `pr_charmbracelet_bubbletea.md` | TUI framework reference | Declarative View, signal handling, testing patterns (teatest), concurrency lessons | HIGH |
| `charmbracelet_gum.md` | CLI patterns | Kong CLI framework, env-var-per-flag pattern (GUM_*), shell completion, fuzzy matching | HIGH |
| `charmbracelet_x.md` | Testing utilities | teatest golden file testing, vcr HTTP recording/playback | MEDIUM |
| `c9s_CLIFramework.md` | PHP CLI reference | Hierarchical commands, extension system, Levenshtein typo correction, shell completion | MEDIUM |
| `textualize_textual.md` | Alternative TUI | CSS-based styling, reactive state, widget composition, command palette | LOW |
| `sugarcraft_candy-shell.md` | Internal CLI patterns | Symfony Console, PHP 8 attributes, CANDYSHELL_* env fallback, Levenshtein suggester, completion generators | HIGH |

# Feature Gap Analysis

## Critical Priority

### 1. Headless/Non-Interactive Mode
**Description:** The CLI requires TTY detection and doesn't work in cron jobs or CI pipelines without a terminal.
**Why it matters:** Users cannot use sugar-post in automated scripts (cron, CI/CD, systemd services).
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #160
**Source PR:** PR #162 (31 lines, in production use a week before PR)
**Implementation ideas:**
- Add `--no-tty` / `-n` flag that skips TTY checks
- Detect stdin EOF vs interactive input
- Output errors to STDERR, not interactive prompts
**Expected impact:** Enables automated/headless use cases.

### 2. Raw MIME Message Output (Pipeline Composition)
**Description:** No way to output a composed MIME message without sending it — prevents piping to other delivery agents.
**Why it matters:** Users want `pop -o - | msmtp -a gmail` composition pattern.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #95
**Implementation ideas:**
- Add `-o` / `--output` flag to CLI
- Return raw MIME message as string from Mailer or a new `MimeWriter`
- Implement `Composer::render()` that outputs RFC 2821 message without sending
**Expected impact:** Enables composable pipeline architecture.

### 3. Markdown Rendering Pipeline
**Description:** No markdown → HTML conversion. Upstream uses goldmark with tables/strikethrough/linkify extensions.
**Why it matters:** Users write markdown expecting HTML email, get plain text.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #13
**Implementation ideas:**
- Integrate a PHP markdown parser (league/commonmark or cek炖's markdown)
- Share single rendering pipeline across Resend and SMTP transports
- Support unsafe extensions (tables, strikethrough) via flag
- Keep plaintext mode explicit (not automatic HTML conversion)
**Expected impact:** Matches developer workflow expectations (markdown-first composition).

### 4. Failed-Email Recovery
**Description:** No temp file saving when send fails. Upstream saves to `pop-YYYY-MM-DD-*.txt`.
**Why it matters:** Users lose composed email content on network failures.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #24
**Implementation ideas:**
- On RuntimeException in SmtpTransport::send() or ResendTransport::send()
- Save email body + metadata to temp file with dated filename
- Include error message in saved file
- Return path to saved file in exception or as return value
**Expected impact:** Prevents data loss on transient failures.

## High Priority

### 5. Gmail Smart Defaults
**Description:** No auto-detection of @gmail.com addresses pre-filling smtp.gmail.com:587.
**Why it matters:** Most common SMTP case requires manual config. Go pop has this since v0.2.0.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #19 + Issue #136
**Source PR:** PR #167 insight
**Implementation ideas:**
- In SmtpTransport or CLI transport auto-selection
- Check if username ends in @gmail.com
- Auto-fill host=smtp.gmail.com, port=587, encryption=tls
- Override with explicit env vars if set
**Expected impact:** Zero-config Gmail SMTP for most common case.

### 6. Optional SMTP Credentials
**Description:** SmtpTransport requires username/password even for anonymous relays.
**Why it matters:** Internal corporate relays, university mail systems reject auth when not needed.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #136 (11+ months open)
**Source PR:** PR #167 (switch gate from "both required" to "any SMTP setting present")
**Implementation ideas:**
- Make username/password optional in SmtpTransport constructor
- Only attempt AUTH LOGIN when both username AND password are non-empty
- SMTP envelope (MAIL FROM/RCPT TO) already works without auth
**Expected impact:** Unblocks anonymous relay use cases.

### 7. All Standard Headers via Env Vars
**Description:** No POP_BCC, POP_REPLY_TO, POP_PLAINTEXT env var equivalents.
**Why it matters:** Power users configure via env vars, not CLI flags.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #142 (POP_BCC), Issue #118 (Reply-To), Issue #115 (Plaintext)
**Source PR:** PR #147 (POP_BCC, 7 lines), PR #119 (Reply-To, 23 lines, open 15+ months)
**Implementation ideas:**
- Add POP_BCC env var support (merged Bcc default)
- Add POP_REPLY_TO env var support
- Add POP_PLAINTEXT env var (disable markdown→HTML auto-conversion)
- Pattern: every CLI flag has equivalent env var
**Expected impact:** Script-friendly configuration.

### 8. Shell Completion Scripts
**Description:** No bash/zsh/fish completion for CLI. Upstream pop uses Kong's built-in completion.
**Why it matters:** Tab completion improves CLI ergonomics significantly.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #23
**Implementation ideas:**
- Generate completion scripts via `pop completion bash|zsh|fish`
- Use Symfony Console's built-in completion support
- Auto-register completion in candy-shell style
**Expected impact:** Better CLI discoverability.

## Medium Priority

### 9. SMTP Connection Pooling/Keep-Alive
**Description:** Each send() creates new SMTP connection. No connection reuse.
**Why it matters:** Batch sending to same server is inefficient.
**Source:** Self-identified
**Implementation ideas:**
- Add SmtpConnectionPool with keep-alive support
- Reuse authenticated connection across multiple sends
- Add timeout for idle connection cleanup
**Expected impact:** Improved performance for bulk sending.

### 10. CC/BCC Merge Behavior Tests
**Description:** No tests for CC/BCC merge behavior in Email with*() builders.
**Source:** Self-identified — Test Suite Analysis gap
**Implementation ideas:**
- Add test: withCc() merges with existing CC
- Add test: withBcc() merges with existing BCC
- Add test: allRecipients() deduplicates across to/cc/bcc
**Expected impact:** Verification of correct routing behavior.

### 11. MIME Message Snapshot Tests
**Description:** No snapshot tests for raw MIME output.
**Source:** Self-identified — Test Suite Analysis gap
**Implementation ideas:**
- Add snapshot tests for buildMimeMessage() output
- Capture raw MIME bytes for known Email configurations
- Compare against stored .golden files
- Update with SNAPSHOT_UPDATE=1 env var
**Expected impact:** Regression prevention for MIME construction.

### 12. Async/Concurrent Sending
**Description:** No ReactPHP/async support for concurrent email sending.
**Why it matters:** Batch sending could be parallelized.
**Source:** Self-identified
**Implementation ideas:**
- Add AsyncMailer wrapper using ReactPHP promises
- `sendMany(array<Email> $emails): array{成功, 失敗}>`
- Use Promise\all() for concurrent dispatch
- Circuit breaker for failing servers
**Expected impact:** Improved throughput for bulk sending.

## Low Priority

### 13. Man Page Generation
**Description:** No `pop man` equivalent for generating ROFF man pages.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #23
**Implementation ideas:**
- Use Symfony Console's man page generation
- Or use bobthedoc/php-docblock-to-manpage
**Expected impact:** Traditional documentation format.

### 14. Interactive File Picker for Attachments
**Description:** No TUI file picker for CLI attachment selection.
**Source:** `docs/repo_map/charmbracelet_pop.md` — Feature list #17
**Implementation ideas:**
- Integrate sugar-bits FilePicker or candy-shell file command
- Use Bubble Tea filepicker as reference
**Expected impact:** TUI attachment selection (if TUI mode added).

### 15. Embedded Images / CID References
**Description:** Attachment::inline() exists but HTML email embedding pattern needs verification.
**Source:** Self-identified
**Implementation ideas:**
- Add example showing inline image embedding in HTML emails
- Test CID routing in multipart/alternative + multipart/mixed
**Expected impact:** Better HTML email support.

# Algorithm / Performance Opportunities

## Current Approach vs External

### 1. MIME Message Construction
**Current:** String concatenation with `\r\n` line endings, boundary via `bin2hex(random_bytes(16))`
**External (Go pop):** `strings.Builder` for efficient string building
**Why external may be better:** String concatenation creates many intermediate strings in PHP. Use a single `$lines = []` array, `implode("\r\n", $lines)` pattern which is more efficient than repeated `.=`.
**Tradeoffs:** Current approach is readable; optimization only matters for very large messages.
**Applicability:** MEDIUM — implement array-join pattern in buildMimeMessage().

### 2. MIME Boundary Generation
**Current:** `bin2hex(random_bytes(16))` for each message
**External (Go):** Same approach
**Tradeoffs:** None — this is standard practice.
**Applicability:** N/A

### 3. Attachment Content Loading
**Current:** Lazy loading via `getContent()` — reads from disk only when called
**External (Go pop):** `os.ReadFile` at construction time (loads entire file into memory)
**Why current is better:** Lazy loading defers I/O and avoids loading large attachments when not needed.
**Tradeoffs:** None — this is a genuine improvement.
**Applicability:** N/A — already better.

### 4. Email Address Parsing
**Current:** Simple `array_map('trim', $from)` in constructor
**External (Go pop):** Full RFC 5322 address parsing
**Why external may be better:** Simple trim doesn't handle quoted strings, comments, or group syntax.
**Tradeoffs:** Adding a full parser increases complexity significantly.
**Applicability:** LOW — only matters for edge-case addresses.

# Architecture Improvements

## 1. Separate Composer from Delivery Agent
**Problem:** Currently composition (Email building) and delivery (Transport) are tightly coupled in Mailer.
**Solution:** Introduce explicit `Composer` interface:
```php
interface Composer {
    public function compose(Email $email): MimeMessage;
}
interface DeliveryAgent {
    public function deliver(MimeMessage $message): void;
}
```
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Section 21 lesson 1
**Benefit:** Enables raw MIME output (compose without delivery) for piping.

## 2. Shared Rendering Pipeline
**Problem:** Markdown rendering differs between Resend and SMTP paths in Go pop.
**Solution:** Single `MarkdownRenderer` interface used by all transports:
```php
interface MarkdownRenderer {
    public function toHtml(string $markdown, bool $unsafe = false): string;
}
```
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #116 (markdown tables inconsistent)
**Benefit:** Consistent markdown support regardless of delivery backend.

## 3. Transport Registry
**Problem:** Manual transport selection in CLI. No way to enumerate available transports.
**Solution:** Add `TransportRegistry`:
```php
final class TransportRegistry {
    public static function make(string $name, array $config): Transport;
    public static function names(): array<string>;
}
```
**Benefit:** Extensible, testable transport selection.

## 4. Error Recovery with Context
**Problem:** Transport errors lose context about what was being sent.
**Solution:** Wrap transport errors with `EmailSendException`:
```php
final class EmailSendException extends RuntimeException {
    public function __construct(
        string $message,
        public readonly Email $email,
        public readonly ?string $savedFile = null,
    );
}
```
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #95 + saveTmp() pattern
**Benefit:** Callers can recover content from failed sends.

# API / Developer Experience Improvements

## 1. Typed Email Construction
**Problem:** Constructor accepts untyped arrays for from/to/cc/bcc.
**Solution:** Use named constructor pattern with proper typing:
```php
public static function from(
    string $from,
    array $to,
    ?string $subject = null,
    ?string $body = null,
): self {
    return new self(
        from: [$from],
        to: $to,
        subject: $subject,
        body: $body,
    );
}
```
**Benefit:** Better IDE support, compile-time safety.

## 2. Email Builder Pattern
**Problem:** with*() methods are fluent but not chainable for multi-field construction.
**Solution:** Add builder class:
```php
final class EmailBuilder {
    public function __construct(string $from, array $to) { ... }
    public function subject(string $s): self { ... }
    public function body(string $b): self { ... }
    public function cc(string ...$cc): self { ... }
    public function build(): Email { ... }
}
```
**Benefit:** Cleaner multi-field construction.

## 3. Transport DSN Support
**Problem:** No standard way to configure transport from a connection string.
**Solution:** Add DSN parsing:
```php
// pop://api-key@resend.com/emails
// smtp://user:pass@smtp.example.com:587
SmtpTransport::fromDsn('smtp://user:pass@smtp.gmail.com:587');
```
**Benefit:** Familiar pattern from Symfony Mailer.

## 4. Better Error Messages
**Problem:** SMTP errors use only errno/errstr from socket layer.
**Solution:** Include SMTP command context in errors:
```php
// "SMTP AUTH LOGIN failed: 535 Authentication credentials invalid (sent: AUTH LOGIN, received: 535)"
```
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #26
**Benefit:** Faster debugging.

# Documentation / Cookbook Opportunities

## 1. Gmail Sending Guide
**Content:** Step-by-step for Gmail SMTP with App Passwords
**Source:** `docs/repo_map/charmbracelet_pop.md` — Gmail smart defaults feature

## 2. Cron Job / CI Integration Guide
**Content:** Using `pop --no-tty` in scheduled tasks
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #160

## 3. Pipeline Composition Examples
**Content:** `pop -o - | msmtp -a gmail`, `pop -o - | mdeliver ...`, etc.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #95

## 4. Batch Sending Patterns
**Content:** Sending to multiple recipients efficiently
**Source:** Self-identified

## 5. HTML Email Best Practices
**Content:** Inline CSS, multipart/alternative, CID embedding
**Source:** Self-identified

# UX / TUI Improvements

## 1. TTY Detection with Fallback
**Problem:** CLI requires TTY even when running non-interactively.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #160
**Solution:**
- Check `posix_isatty(STDOUT)` for interactive vs pipe mode
- If not a TTY and required fields missing, error clearly
- Add `--no-tty` flag for explicit headless mode

## 2. Progress Feedback for Large Attachments
**Problem:** No progress indication when sending large files.
**Source:** Self-identified
**Solution:** Add optional progress callback:
```php
$mailer->send($email, onProgress: fn(int $sent, int $total) => ...);
```

## 3. Colored Output in Terminal
**Problem:** Success/error messages are plain text.
**Source:** `docs/repo_map/charmbracelet_gum.md` — lipgloss styling
**Solution:** Use candy-shine for colored output:
```php
// Success: green "Email sent via smtp"
```

# Testing / Reliability Improvements

## 1. Mock Transport Tests
**Problem:** No tests for actual send() behavior.
**Source:** `docs/repo_map/sugarcraft_sugar-post.md` — Test Suite Analysis
**Solution:** Add mock transports:
```php
final class FakeSmtpTransport implements Transport {
    public array $sentEmails = [];
    public function send(Email $email): void { $this->sentEmails[] = $email; }
}
```
**Source:** `docs/repo_map/sugarcraft_candy-shell.md` — FakeProcess pattern

## 2. HTTP Mock for ResendTransport
**Problem:** No HTTP layer testing for Resend API calls.
**Source:** `docs/repo_map/charmbracelet_x.md` — vcr package
**Solution:** Use php-vcr or GuzzleMock for recording/playback:
```php
$this->mockHttp->addResponse('/emails', 200, ['id' => 'test_123']);
$transport->send($email);
```

## 3. SMTP Integration Test
**Problem:** Real SMTP tests require live server.
**Solution:** Use Dumbster or similar test SMTP server:
```php
private static function createTestSmtpServer(): int {
    // Spawn PHP built-in server on random port
    // Use netcat pipe for SMTP protocol
}
```

## 4. Snapshot Tests for MIME Output
**Problem:** MIME construction has no regression coverage.
**Solution:** Golden file testing:
```php
public function testMimeOutputWithAttachment(): void {
    $email = Email::make(...)->withAttachment('test.pdf', '/path/to/test.pdf');
    $output = $this->smtpTransport->buildMimeMessage($email);
    $this->assertMatchesSnapshot($output, 'mime_with_attachment.txt');
}
```

# Ecosystem / Integration Opportunities

## 1. Symfony Mailer Integration
**Problem:** Users with existing Symfony Mailer usage can't easily switch.
**Solution:** Add Symfony Mailer transport bridge:
```php
use SugarCraft\Post\SmtpTransport;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;

$transport = Transport::fromDsn('sugarcraft+smtp://smtp.gmail.com:587');
$mailer = new Mailer($transport);
```

## 2. Laravel Notification Channel
**Problem:** Laravel users expect notification channel pattern.
**Solution:** Create sugar-post Laravel notification channel:
```php
class EmailNotification extends Notification {
    public function via(object $notifiable): array {
        return [SugarPostChannel::class];
    }
    public function toSugarPost(object $notifiable): Email { ... }
}
```

## 3. PSR-18 Compliance
**Problem:** Not compliant with PSR-18 (HTTP client interface).
**Solution:** Make ResendTransport implement `Psr\Http\Client\ClientInterface`:
```php
final class ResendTransport implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface { ... }
}
```

## 4. MIME Output for msmtp/mdeliver Integration
**Problem:** Users want to compose with sugar-post, deliver with other agents.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md` — Issue #95
**Solution:** Output RFC 2821 message:
```php
$message = $composer->render($email);
file_put_contents('php://stdout', $message);
```
Enable: `pop --output - --from a@b.com --to c@d.com < body.md`

# Notable PRs / Issues / Discussions

## PR #167: Allow SMTP without credentials (May 2026)
**Summary:** Switched SMTP gate from "both credentials required" to "any SMTP setting present"
**Lessons:** Don't require credentials the underlying library doesn't need. Gate on the minimal required set.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

## PR #162: Add initial no-tty implementation (Feb 2026)
**Summary:** 31-line PR for headless mode, fork was in production use a week before PR
**Lessons:** Community implements features themselves when PR review is slow. sugar-post needs faster review.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

## PR #147: Add support to POP_BCC environment variable (Sep 2025)
**Summary:** 7-line PR for BCC env var, mergeable
**Lessons:** Tiny PRs still sit open for months. sugar-post should prioritize small PRs.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

## Issue #136: Optional SMTP credentials (Jun 2025)
**Summary:** Required username+password even for anonymous relays, 11+ months open
**Lessons:** This gate caused years of pain in Go pop. sugar-post should never require optional components.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

## Issue #116: Markdown tables not rendered (Dec 2024)
**Summary:** Tables only render for Resend path, not SMTP — inconsistent rendering
**Lessons:** Without shared rendering pipeline, backends drift. Design shared rendering upfront.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

## Issue #115: Plain text option (Dec 2024)
**Summary:** Default to HTML without warning surprises users
**Lessons:** Default behavior should be explicit. sugar-post should default to plaintext unless user requests HTML.
**Source:** `docs/repo_map/pr_charmbracelet_pop.md`

# Recommended Roadmap

## Immediate Wins (1-2 weeks)
1. Add `--no-tty` / `-n` flag for headless mode
2. Add `POP_BCC`, `POP_REPLY_TO`, `POP_PLAINTEXT` env vars
3. Fix optional SMTP credentials (don't require when server doesn't need)
4. Add shell completion scripts
5. Add fake transport for testing

## Medium-Term (1-2 months)
6. Add raw MIME output (`--output -`)
7. Add failed-email temp file recovery
8. Add markdown rendering pipeline (league/commonmark)
9. Add Gmail smart defaults
10. Add MIME snapshot tests

## Major Architectural Upgrades (3-6 months)
11. Composer/DeliveryAgent separation
12. TransportRegistry for extensibility
13. AsyncMailer with ReactPHP
14. SMTP connection pooling
15. Symfony Mailer bridge

## Experimental Ideas
16. TUI composer (Bubble Tea style form for email composition)
17. IMAP receive support
18. Web-based DevTools inspector

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Optional SMTP credentials | HIGH | LOW | LOW | IMMEDIATE |
| Headless/non-interactive mode | HIGH | LOW | LOW | IMMEDIATE |
| POP_BCC, POP_REPLY_TO, POP_PLAINTEXT env vars | MEDIUM | LOW | LOW | IMMEDIATE |
| Shell completion scripts | MEDIUM | MEDIUM | LOW | IMMEDIATE |
| Fake transport for testing | MEDIUM | LOW | LOW | IMMEDIATE |
| Raw MIME output for piping | HIGH | MEDIUM | LOW | MEDIUM |
| Failed-email recovery | HIGH | MEDIUM | MEDIUM | MEDIUM |
| Markdown rendering pipeline | HIGH | HIGH | MEDIUM | MEDIUM |
| Gmail smart defaults | MEDIUM | LOW | LOW | MEDIUM |
| MIME snapshot tests | MEDIUM | LOW | LOW | MEDIUM |
| Composer/DeliveryAgent separation | HIGH | HIGH | MEDIUM | LONG-TERM |
| SMTP connection pooling | MEDIUM | MEDIUM | MEDIUM | LONG-TERM |
| AsyncMailer with ReactPHP | MEDIUM | HIGH | HIGH | LONG-TERM |
| Symfony Mailer bridge | MEDIUM | MEDIUM | LOW | LONG-TERM |
| TUI composer | HIGH | VERY HIGH | HIGH | EXPERIMENTAL |
| IMAP receive support | HIGH | VERY HIGH | HIGH | EXPERIMENTAL |

# Final Strategic Assessment

sugar-post is a well-structured email sending library that successfully ports the core sending logic of charmbracelet/pop to PHP. Its immutability patterns, dual-transport abstraction, and pure-PHP SMTP implementation are solid technical foundations. The library is particularly strong as a dependency-free email sender for PHP applications that need SMTP or Resend API without the weight of PHPMailer or SwiftMailer.

However, the package is conspicuously missing features that the upstream Go pop has developed over 2+ years, creating a growing gap. Most critically, the **lack of headless mode** and **raw MIME output** prevents sugar-post from being used in the automated/pipeline workflows that are increasingly central to modern DevOps. The **markdown rendering gap** means developers who expect markdown→HTML email composition will be disappointed.

The most strategic immediate improvements are:
1. **Fix the SMTP credential gate** — This has caused years of pain in the Go upstream and should be fixed before release
2. **Add headless mode + raw MIME output** — These unlock automation/pipeline use cases that define the library's value proposition
3. **Adopt the env-var-per-flag pattern** — Every CLI flag needs a `POP_*` env var equivalent, matching the upstream pattern

The medium-term priority should be a **shared markdown rendering pipeline** — without it, the Resend and SMTP paths will inevitably drift in markdown support, creating the same inconsistency bug that plagued Go pop (issue #116).

Architecture-wise, the **Composer/DeliveryAgent separation** is the most important structural improvement — it cleanly enables the raw MIME output feature and sets up a more extensible transport system.

Against the broader ecosystem, sugar-post fills a genuine niche in the PHP ecosystem. There is no competing pure-PHP email sending library with the same combination of immutability, dual-transport, and dependency-free SMTP implementation. This positioning is defensible and valuable.

The 2.5-year release gap in the upstream Go pop (issue #87) is a cautionary tale about sporadic releases — sugar-post should commit to regular small releases rather than large infrequent ones.
