<img src=".assets/icon.png" alt="sugar-post" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-post)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-post)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-post?label=packagist)](https://packagist.org/packages/sugarcore/sugar-post)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarPost

PHP port of [charmbracelet/pop](https://github.com/charmbracelet/pop) — send emails from PHP via Resend API or direct SMTP.

## Features

- **Dual transport** — send via Resend HTTP API or raw SMTP
- **Email value object** — from, to, cc, bcc, subject, body, HTML body, reply-to, attachments
- **File attachments** — attach files from paths or raw content with MIME detection
- **Inline attachments** — embed images inline (CID references)
- **CC/BCC support** — full carbon-copy / blind carbon-copy routing
- **STDIN compose** — read email body from STDIN for shell pipeline use
- **Environment config** — `RESEND_API_KEY`, `POP_SMTP_*`, `POP_FROM`, `POP_SIGNATURE`
- **PHP 8.1+** — pure PHP, no extensions required beyond cURL (for Resend transport)

## Install

```bash
composer require sugarcraft/sugar-post
```

## Quick Start

### Resend API

```php
use SugarCraft\Post\{Email, Mailer, ResendTransport};

$transport = new ResendTransport('re_xxxxxxxxxxxxx');
$mailer = new Mailer($transport);

$email = new Email(
    from:    'you@example.com',
    to:      ['them@example.com'],
    subject: 'Hello from SugarPost',
    body:    'Sent via the Resend API.',
);

$mailer->send($email);
```

### SMTP

```php
use SugarCraft\Post\{Email, Mailer, SmtpTransport};

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

## CLI

```bash
pop --from "me@example.com" --to "you@example.com" --subject "Hello"
# Body read from STDIN
```

Environment variables:

```bash
export RESEND_API_KEY=re_xxxxx                # Resend API key
export POP_SMTP_HOST=smtp.gmail.com           # SMTP host
export POP_SMTP_PORT=587                      # SMTP port (default: 587)
export POP_SMTP_USERNAME=user                 # SMTP username
export POP_SMTP_PASSWORD=pass                 # SMTP password
export POP_FROM=me@example.com                # Pre-fill From address
export POP_SIGNATURE="Sent with SugarPost"    # Appended to body
```

## Architecture

- `Email` — immutable email message value object
- `Attachment` — immutable file attachment (path or inline content)
- `Transport` — interface for sending implementations
- `ResendTransport` — sends via Resend REST API (HTTPS)
- `SmtpTransport` — sends via direct SMTP (TCP/TLS)
- `Mailer` — high-level API wrapping a Transport

## License

[MIT](LICENSE)
