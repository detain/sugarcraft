<img src=".assets/icon.png" alt="candy-mail" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-mail)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-mail)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-mail?label=packagist)](https://packagist.org/packages/sugarcraft/candy-mail)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyMail

candy-mail — an email **sender** for PHP 8.3+, via the Resend API or direct SMTP.
Send-only by design: candy-mail composes and dispatches mail; it does not read,
store, or manage an inbox.

> Formerly published as `sugarcraft/sugar-post` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Post\` → `SugarCraft\Mail\`. <!-- @rename-keep -->

## Features

- **Dual transport** — send via the Resend HTTP API or raw SMTP
- **Email value object** — from, to, cc, bcc, subject, body, HTML body, reply-to, attachments
- **File attachments** — attach from disk paths (`withAttachment()`), or pass raw in-memory
  bytes with `Attachment::fromContent()` via the constructor's `attachments:` parameter
- **Inline attachments (CID)** — `withInlineAttachment()` embeds images addressed as
  `cid:…`; honoured by the SMTP transport's MIME builder. The Resend API payload does not
  carry `cid`, so inline references break there — use SMTP for CID embedding.
- **CC/BCC support** — full carbon-copy / blind carbon-copy routing (envelope RCPT for all,
  headers for visible sets)
- **Header-injection defense** — CR/LF in subjects, addresses, attachment names, and MIME
  types is refused, not folded
- **TLS by default** — STARTTLS is required on SMTP unless explicitly relaxed (see below);
  credentials are never sent over a plaintext channel
- **CLI** — one-shot sending with STDIN compose, `--render` for offline MIME preview, and
  translated error messages (the CLI itself; the library is transport-agnostic)
- **PHP 8.3+** — pure PHP; ext-curl is only needed for the Resend transport,
  ext-fileinfo improves automatic MIME detection for path attachments (both `suggest`ed)

## Install

```bash
composer require sugarcraft/candy-mail
```

## Quick Start

### Resend API

```php
use SugarCraft\Mail\{Email, Mailer, ResendTransport};

$transport = new ResendTransport('re_xxxxxxxxxxxxx'); // or new ResendTransport('') to read RESEND_API_KEY
$mailer = new Mailer($transport);

$email = new Email(
    from:    ['you@example.com'],
    to:      ['them@example.com'],
    subject: 'Hello from CandyMail',
    body:    'Sent via the Resend API.',
);

$mailer->send($email);
```

### SMTP

```php
use SugarCraft\Mail\{Email, Mailer, SmtpTransport};

// TLS is required by default: the send refuses to proceed (or to send
// credentials) unless the channel is encrypted.
$transport = new SmtpTransport(
    host:     'smtp.gmail.com',
    port:     587,
    username: 'you@gmail.com',
    password: 'your-16-char-app-password', // Gmail rejects raw account passwords
);
$mailer = new Mailer($transport);

$mailer->send(new Email(
    from:    ['you@gmail.com'],
    to:      ['them@example.com'],
    subject: 'Hello via SMTP',
    body:    'Sent directly via SMTP.',
));
```

### Attachment

```php
use SugarCraft\Mail\{Attachment, Email};

$email = (new Email(from: ['me@example.com'], to: ['you@example.com']))
    ->withAttachment('invoice.pdf', '/path/to/invoice.pdf');   // from disk (eagerly read)

// Raw in-memory bytes: build the Attachment and pass it in the constructor.
$email2 = new Email(
    from:        ['me@example.com'],
    to:          ['you@example.com'],
    attachments: [Attachment::fromContent($csvBytes, 'report.csv', 'text/csv')],
);
```

> **Note:** `--attach` / `withAttachment()` read arbitrary local paths. The caller is responsible for validating that passed paths are trusted. No path allow-list is enforced by design.

## TLS & security (SMTP)

| Port | Mode | What happens |
|------|------|--------------|
| 465 | Implicit TLS (`withImplicitTls()`) | Socket is TLS-wrapped at connect; STARTTLS never sent. |
| 587 | STARTTLS (**default**) | Plaintext connect, then `STARTTLS`; **required** — if the server does not advertise it or the upgrade fails, the send throws instead of downgrading. |
| 25 | STARTTLS opportune-but-required | Same default policy applies: a server that refuses STARTTLS fails the send while `requireTls` is on. |

- **Fail-closed credentials.** `AUTH LOGIN` never runs on an unencrypted channel —
  regardless of `requireTls`. There is no `allowInsecureAuth` escape hatch by design.
- **Opt out (trusted networks only).** `->withRequireTls(false)` restores the historical
  opportunistic behaviour for the STARTTLS upgrade itself; it does not re-open the
  credential floor above.
- **Self-signed / private CAs.** Peer verification is always on (never blanket-disable
  `verify_peer`). Point the handshake at your bundle with `->withCaFile('/path/ca.pem')`;
  it feeds both the implicit-TLS stream context and the STARTTLS upgrade.
- **Message integrity.** Attachment filenames and MIME types pass the same CR/LF gate as
  subjects and addresses; MIME part parameters are RFC 2045-quoted.
- **Secret masking.** `__debugInfo()` masks the SMTP password and the Resend API key.

## CLI

```bash
candy-mail --from "me@example.com" --to "you@example.com" --subject "Hello"
# Body read from STDIN. Both --flag value and --flag=value work.

candy-mail --to you@example.com --render < message.txt
# Offline preview: prints the composed MIME message (with a declared-boundary,
# RFC 2046-valid structure) and sends nothing — no transport env required.
```

`--version` prints the package version, `--help` the usage, repeat `--to/--cc/--bcc/--attach`
to accumulate, and a bare `--` ends option parsing (later addresses are positional recipients).
Unknown options are rejected loudly.

Environment variables:

```bash
export RESEND_API_KEY=re_xxxxx                # Resend API key (enables the Resend transport)
export CANDY_MAIL_SMTP_HOST=smtp.gmail.com    # SMTP host (Resend key wins if both set)
export CANDY_MAIL_SMTP_PORT=587               # SMTP port (default: 587)
export CANDY_MAIL_SMTP_USERNAME=user          # SMTP username
export CANDY_MAIL_SMTP_PASSWORD=pass          # SMTP password (app password for Gmail etc.)
export CANDY_MAIL_SMTP_REQUIRE_TLS=1          # default ON; set 0 only for trusted plaintext networks
export CANDY_MAIL_SMTP_IMPLICIT_TLS=0         # set 1 for port-465 style direct TLS
export CANDY_MAIL_FROM=me@example.com         # Pre-fill From address
export CANDY_MAIL_SIGNATURE="Sent with CandyMail"  # Appended to body
# Pre-rename POP_*/POP_SMTP_* exports are still read, with a deprecation warning. // @rename-keep
```

Error **messages** follow the standard `LC_ALL`/`LC_MESSAGES`/`LANG` environment (16
locales ship); the usage/help text is English by design. Examples under `examples/`
are offline-safe: without real credentials they print the composed message instead of
dialling any API.

## Internationalisation

All user-visible errors route through the `SugarCraft\Mail\Lang` facade (sugar-core's
shared `T` registry, namespace `mail`, catalog in `lang/`). English is the default;
a host application opts in process-wide:

```php
\SugarCraft\Core\I18n\T::setLocale(\SugarCraft\Core\I18n\T::detect()); // LC_ALL → LANG → 'en'
```

The CLI performs this at startup; library embedders decide. Missing keys in an active
locale fall back to English, never raw placeholders.

## Architecture

- `Email` — immutable email message value object
- `Attachment` — immutable file attachment (path or inline content)
- `Transport` — interface for sending implementations
- `ResendTransport` — sends via Resend REST API (HTTPS)
- `SmtpTransport` — sends via direct SMTP (TCP/TLS)
- `Mailer` — high-level API wrapping a Transport
- `Lang` — per-library translation facade over the shared sugar-core registry

## Shared foundations

candy-mail uses [sugar-async](https://github.com/detain/sugarcraft/tree/master/sugar-async)
for cancellation on SMTP sends via `CancellationToken`: an **entry pre-check only**
(`isCancelled()` before the conversation starts). Blocking I/O never yields to the event
loop mid-send, so `onCancel()` callbacks cannot fire during a running send — true
mid-flight cancellation would require an async transport (documented in
`CALIBER_LEARNINGS.md`).

## Tests

```bash
cd candy-mail && vendor/bin/phpunit -c phpunit.xml
```

308 tests, 1,125 assertions (PHP 8.3, PHPUnit 10.5). The suite includes fork-based
loopback fake-server tests for the SMTP conversation; no external network is ever dialled.

## Accessor deprecation (2026-10-24)

Per the house bare-accessor convention (`AGENTS.md`: *"Bare accessors (no* `get`*"), ruling 3b)*, `Attachment::getContent()` was renamed to its bare twin `content()` — the method slot was unclaimed (no `withContent` setter exists; the public readonly `$content` property names the raw backing field, not a callable, and PHP keeps property and method namespaces distinct). The legacy spelling stays a byte-identical one-line BC forwarder marked `@deprecated` with `REMOVED-IN: 2.0.0`; it will be removed in candy-mail v2.0.0.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `Mail` | `Attachment` | `getContent()` | `content()` |

Note the distinction the rename preserves: the readonly property `$content` is the raw nullable backing field, while `content()` resolves the bytes (including the defense-in-depth path re-read). `tests/AccessorDeprecationTest.php` pins BC identity on live objects from both factories, the property/method distinction, the forwarder shape, a discovery-first `src/` census with an inverse leg that reddens any unmarked public `get*()`, and the README table agreement. Internal call sites in `src/` (`SmtpTransport`, `ResendTransport`) were switched to the bare form in the same commit.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
