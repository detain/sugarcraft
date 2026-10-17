<img src=".assets/icon.png" alt="candy-mail" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-mail)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-mail)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-mail?label=packagist)](https://packagist.org/packages/sugarcraft/candy-mail)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyMail

> Formerly published as `sugarcraft/sugar-post` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Post\` → `SugarCraft\Mail\`. <!-- @rename-keep -->

candy-mail — an email sender for PHP 8.3+, via the Resend API or direct SMTP.

## Features

- **Dual transport** — send via Resend HTTP API or raw SMTP
- **Email value object** — from, to, cc, bcc, subject, body, HTML body, reply-to, attachments
- **File attachments** — attach files from paths or raw content with MIME detection
- **Inline attachments** — embed images inline (CID references)
- **CC/BCC support** — full carbon-copy / blind carbon-copy routing
- **STDIN compose** — read email body from STDIN for shell pipeline use
- **Environment config** — `RESEND_API_KEY`, `CANDY_MAIL_SMTP_*`, `CANDY_MAIL_FROM`, `CANDY_MAIL_SIGNATURE`
- **PHP 8.3+** — pure PHP, no extensions required beyond cURL (for Resend transport)

## Install

```bash
composer require sugarcraft/candy-mail
```

## Quick Start

### Resend API

```php
use SugarCraft\Mail\{Email, Mailer, ResendTransport};

$transport = new ResendTransport('re_xxxxxxxxxxxxx');
$mailer = new Mailer($transport);

$email = new Email(
    from:    'you@example.com',
    to:      ['them@example.com'],
    subject: 'Hello from CandyMail',
    body:    'Sent via the Resend API.',
);

$mailer->send($email);
```

### SMTP

```php
use SugarCraft\Mail\{Email, Mailer, SmtpTransport};

$transport = new SmtpTransport('smtp.gmail.com', 587, 'username', 'password');
$mailer = new Mailer($transport);

$mailer->send(new Email(
    from:    'you@gmail.com',
    to:      ['them@gmail.com'],
    subject: 'Hello via SMTP',
    body:    'Sent directly via SMTP.',
));
```

### Attachment

```php
$email = new Email(/* ... */);
$email = $email->withAttachment('invoice.pdf', '/path/to/invoice.pdf');
$mailer->send($email);
```

> **Note:** `--attach` / `withAttachment()` read arbitrary local paths. The caller is responsible for validating that passed paths are trusted. No path allow-list is enforced by design.

## CLI

```bash
candy-mail --from "me@example.com" --to "you@example.com" --subject "Hello"
# Body read from STDIN
```

Environment variables:

```bash
export RESEND_API_KEY=re_xxxxx                # Resend API key
export CANDY_MAIL_SMTP_HOST=smtp.gmail.com           # SMTP host
export CANDY_MAIL_SMTP_PORT=587                      # SMTP port (default: 587)
export CANDY_MAIL_SMTP_USERNAME=user                 # SMTP username
export CANDY_MAIL_SMTP_PASSWORD=pass                 # SMTP password
export CANDY_MAIL_FROM=me@example.com                # Pre-fill From address
export CANDY_MAIL_SIGNATURE="Sent with CandyMail"    # Appended to body
# Pre-rename POP_*/POP_SMTP_* exports are still read, with a deprecation warning. // @rename-keep
```

## Architecture

- `Email` — immutable email message value object
- `Attachment` — immutable file attachment (path or inline content)
- `Transport` — interface for sending implementations
- `ResendTransport` — sends via Resend REST API (HTTPS)
- `SmtpTransport` — sends via direct SMTP (TCP/TLS)
- `Mailer` — high-level API wrapping a Transport

## Shared foundations

candy-mail uses [sugar-async](https://github.com/detain/sugarcraft/tree/master/sugar-async) for best-effort cancellation on SMTP sends via `CancellationToken` (pre-write `isCancelled()` check + `onCancel` callback).

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
