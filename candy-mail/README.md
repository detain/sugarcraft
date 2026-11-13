<img src=".assets/icon.png" alt="candy-mail" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-mail)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-mail)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-mail?label=packagist)](https://packagist.org/packages/sugarcraft/candy-mail)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyMail

candy-mail — an email **sender** for PHP 8.3+, via the Resend HTTP API or direct SMTP.
Send-only by design: candy-mail composes and dispatches mail; it does not read,
store, or manage an inbox.

> Formerly published as `sugarcraft/sugar-post` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Post\` → `SugarCraft\Mail\`. <!-- @rename-keep -->

## Features

- **Dual transport** — send via the Resend HTTP API or raw SMTP
- **Send-path scope** — the upstream TUI parity leg is deliberately descoped
  2026-11-12 (owner ruling 6a) because the pop-parity surface this app commits to is
  composing and dispatching mail (CLI + library), not an interactive client; a TUI
  would duplicate the sugar-widgets surface instead of deepening the send path.
  Likewise the Mailgun leg is deliberately descoped 2026-11-12 (same ruling): the
  transport roster stays SMTP + Resend, and Mailgun accounts relay through their
  documented SMTP endpoints rather than a second API transport.
- **Email value object** — from, to, cc, bcc, subject, body, HTML body, reply-to, attachments,
  signature (the signature joins the plain-text body only — an HTML-only mail silently drops it)
- **HTML accessibility** — the authored HTML is embedded verbatim (nothing post-processes it),
  so set `<html lang="…">`, give images alt text, and keep a meaningful plain-text body: it is
  the reader-equivalent leg of the `multipart/alternative` for anyone your HTML does not reach.
  No `Content-Language` header is emitted — the language lives in the document, not the envelope.
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
- **PHP 8.3+** — pure PHP. `suggest`ed extensions, honestly scoped: `ext-curl` is
  needed only for the Resend transport, `ext-fileinfo` improves automatic MIME
  detection for path attachments, and `ext-openssl` is required for TLS — without
  it the SMTP transport refuses every encrypted connection (STARTTLS and port-465
  alike); only plain, unencrypted SMTP works without it.

## Install

```bash
composer require sugarcraft/candy-mail
```

Sibling `sugarcraft/*` requirements track `dev-master`, so the plain command above
only resolves when dev stability is allowed: pin the package with `composer require
sugarcraft/candy-mail:@dev`, or set `minimum-stability: "dev"` together with
`prefer-stable: true` in your consuming manifest. The transitive pull is the part
that bites a naive consumer: this package's own `sugarcraft/sugar-async` and
`sugarcraft/sugar-core` requires are themselves pinned to `dev-master`, so
allowing dev only for candy-mail still hits the stability wall on those siblings —
the `@dev` pin (or the manifest-wide pair) covers the whole closure. The global
install takes the same form: `composer global require sugarcraft/candy-mail:@dev`.

The `bin/candy-mail` bootstrap walks an autoload candidate ladder (package-local
`vendor/autoload.php` first, then the consumer project's `../../../autoload.php`), so
the CLI runs from a standalone clone, a composer install, or a built phar (which reads
its own bundled vendor). In the monorepo checkout, `php candy-mail/bin/candy-mail`
works after `scripts/refresh-deps.php`.

### Standalone phar

Run the single-file phar — no Composer, no `vendor/`: download `candy-mail.phar`
and its `candy-mail.phar.sha256` sidecar from the `candy-mail/dev` release,
verify, and run:

```bash
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-mail/dev/candy-mail.phar
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-mail/dev/candy-mail.phar.sha256
sha256sum -c candy-mail.phar.sha256
php candy-mail.phar --version
```

`dev` is a rolling prerelease channel: the archive is rebuilt and re-stamped on
every `master` push that touches this app, so the phar there carries the newest
such commit — the `dev-<shortsha>` `--version` prints is the commit it was
packed from.

Or build the phar from a checkout:

```bash
php -d phar.readonly=0 tools/build-phar.php [--out=dist/candy-mail.phar]
```

Three prerequisites: `-d phar.readonly=0` is build-only, never a runtime
requirement; the tree must be clean, committed, and non-detached (the integrity
preflight refuses anything else); and the packaged vendor must be the published,
`--no-dev` one — a linked-mode (symlinked-sibling) vendor would freeze
working-tree bytes into the artifact.

### Command-line consumers

After `composer require sugarcraft/candy-mail` in your project the bin lands at
`vendor/bin/candy-mail` next to your autoloader; the candidate ladder above
resolves it. For a PATH-visible copy, install it globally:

```bash
composer global require sugarcraft/candy-mail
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
| 465 | Implicit TLS (auto-detected on 465; `withImplicitTls()` to override) | Socket is TLS-wrapped at connect; STARTTLS never sent. |
| 587 | STARTTLS (**default**) | Plaintext connect, then `STARTTLS`; **required** — if the server does not advertise it or the upgrade fails, the send throws instead of downgrading. |
| 25 | STARTTLS upgraded opportunistically, enforced by default | Same default policy applies: a server that refuses STARTTLS fails the send as long as `requireTls` is on. |

- **Fail-closed credentials.** `AUTH LOGIN` never runs on an unencrypted channel —
  regardless of `requireTls`. There is no `allowInsecureAuth` escape hatch by design.
- **AUTH mechanisms.** The EHLO AUTH advertisement is parsed as a list
  (RFC 4954 — both `AUTH <sp>` and `AUTH=` shapes). SASL PLAIN is preferred
  and travels as a single command once the channel is encrypted; the LOGIN
  three-step stays the fallback for LOGIN-only relays. A server advertising
  only mechanisms this client does not speak (CRAM-MD5, OAUTHBEARER) gets the
  named refusal — never a blind command guess.
- **Opt out (trusted networks only).** `->withRequireTls(false)` restores the historical
  opportunistic behaviour for the STARTTLS upgrade itself; it does not re-open the
  credential floor above.
- **Self-signed / private CAs.** Peer verification is always on (never blanket-disable
  `verify_peer`). Point the handshake at your bundle with `->withCaFile('/path/ca.pem')`;
  it feeds both the implicit-TLS stream context and the STARTTLS upgrade.
- **Message integrity.** Attachment filenames and MIME types pass the same CR/LF gate as
  subjects and addresses; MIME part parameters are RFC 2045-quoted.
- **Secret masking.** `__debugInfo()` masks the SMTP password and the Resend API key.
- **Credentials are unexportable via `serialize()`/`var_export()` re-import.**
  Transports refuse re-import (`__serialize`/`__set_state` throw); construct a
  credential-holding transport where it is used.
- **Attachment ceilings.** 25 MiB (`Attachment::MAX_BYTES`) per attachment, regular files
  only — character devices like `/dev/zero` are refused before any read.
- **Wire format discipline.** Header lines fold at 998 octets using ≤75-char RFC 2047
  encoded-words; non-ASCII filenames ride RFC 2231 `filename*`; 8-bit bodies downgrade to
  quoted-printable when the server lacks 8BITMIME (RFC 6152); documents end with a
  CRLF-terminated close-delimiter (RFC 2046 §5.1); servers answering EHLO with a 5xx fall
  back to HELO (RFC 5321 §4.1.4) — 4xx refusals still abort.
- **Senders are never invented.** The CLI refuses to run without `--from` or
  `CANDY_MAIL_FROM`; an empty Resend sender list throws instead of fabricating
  `unknown@localhost`.

## CLI

```bash
# The examples below invoke the bare `candy-mail` (a `vendor/bin/candy-mail` from
# your project install, the composer-global bin, or the phar renamed onto your
# PATH); in a monorepo checkout run them as `php candy-mail/bin/candy-mail`.
candy-mail --from "me@example.com" --to "you@example.com" --subject "Hello"
# Body read from STDIN. Both --flag value and --flag=value work.

candy-mail --from "me@example.com" --to you@example.com --render < message.txt
# Offline preview: prints the composed MIME message (with a declared-boundary,
# RFC 2046-valid structure) and sends nothing — no transport env required.
```

`--version` prints the package version, `--help` (or `-h`) the usage, repeat `--to/--cc/--bcc/--attach`
to accumulate, and a bare `--` ends option parsing (later addresses are positional recipients).
Short aliases `-f` (`--from`), `-t` (`--to`), `-s` (`--subject`) and `-a` (`--attach`) are accepted
as `-f value`; the inline `=` form is long-option only (so `-f=x` is refused, use `--from=x` or `-f x`).
Positional recipients fill `--to` only when `--to` is absent — if you also pass `--to`, the
positional addresses after `--` are silently dropped (mixing is not additive).
Unknown options are rejected loudly, with a did-you-mean hint when a known flag is a
near miss.

Value switches: `--from/--subject/--body/--reply-to/--html/--helo/--ca-file/--timeout`
(the last three override their `CANDY_MAIL_SMTP_*` env rows, and the flags win over the
environment; `--timeout` is range-checked at parse time on every path). Transport
selection is a ladder, not a merge: a non-blank `RESEND_API_KEY` wins outright, the
Resend leg reads none of `--timeout/--helo/--ca-file` (each one supplied prints a
stderr note naming it), and the API transport keeps its own fixed 10 s connect /
30 s transfer budget — the CLI's timeout knob is SMTP-only. When both transports are
configured the ladder can silently spend the WRONG domain's identity (SPF/DKIM
alignment rides whichever transport answers), so `--transport=smtp|resend` forces the
choice: `smtp` ignores a present key outright, `resend` refuses without one. The
zero-flag recipe for a one-off is `env -u RESEND_API_KEY candy-mail …`. A value that begins with a dash needs the inline form
(`--subject=-50% off`) — a switch is never stolen as another switch's value. The CLI
requires a `--to` recipient: cc/bcc alone ends in a loud refusal (the library itself
permits cc/bcc-only sends over SMTP; the Resend API needs at least one `to`).

Embedding behind another bin? `require` the bootstrap and call
`exit(\SugarCraft\Mail\main($argv));` to keep exit codes honest.

Environment variables:

```bash
export RESEND_API_KEY=re_xxxxx                # Resend API key (enables the Resend transport)
export CANDY_MAIL_SMTP_HOST=smtp.gmail.com    # SMTP host (Resend key wins if both set —
                                              # force SMTP with --transport=smtp)
export CANDY_MAIL_SMTP_PORT=587               # SMTP port (default: 587)
export CANDY_MAIL_SMTP_USERNAME=user          # SMTP username
export CANDY_MAIL_SMTP_PASSWORD=pass          # SMTP password (app password for Gmail etc.)
export CANDY_MAIL_SMTP_REQUIRE_TLS=1          # default ON; ON words 1/true/yes/on, OFF words 0/false/no/off —
                                              # anything else is REFUSED, never guessed (a typo'd security
                                              # switch must not silently downgrade)
export CANDY_MAIL_SMTP_IMPLICIT_TLS=0         # set 1 for port-465 style direct TLS (same word vocabulary)
export CANDY_MAIL_SMTP_HELO=mail.example.com  # EHLO/HELO identity (letters, digits, dots, hyphens only)
export CANDY_MAIL_SMTP_CA_FILE=/path/ca.pem   # custom CA bundle for TLS verification
export CANDY_MAIL_SMTP_TIMEOUT=30             # connect + per-response seconds budget, 1..3600 (default 30);
                                              # range-checked at parse time — ignored (with a note) when Resend wins
export CANDY_MAIL_FROM=me@example.com         # Sender default — REQUIRED unless --from passed (never fabricated)
export CANDY_MAIL_SIGNATURE="Sent with CandyMail"  # Appended to the plain-text body (HTML-only mail drops it)
# Pre-rename POP_* exports are imported onto CANDY_MAIL_* equivalents whole-prefix
# (an already-set CANDY_MAIL_* wins); the 11 names this program reads also emit a
# one-time deprecation warning — off-roster POP_* vars map to names nothing consumes,
# staying inert and silent.
```
<!-- @rename-keep -->

### Running unattended

Cron jobs, systemd units, and containers have no terminal, and STDIN there is
an environment detail, not an intent: a closed non-tty stream (redirected from
`/dev/null`, or the default of a hardened unit) reads as an empty body. Since
a message with no body, no HTML part, and no attachment composes to zero MIME
parts, the CLI now **refuses** that shape loudly (exit 1) instead of mailing
or previewing a hollow envelope. Unattended senders therefore always pass
`--body` (or `--html`) explicitly, or pipe real content:

```bash
# crontab — body on the command line, credentials from an EnvironmentFile-
# style export block, never from an interactive terminal assumption:
*/15 * * * * candy-mail --from reports@example.com --to oncall@example.com \
    --subject "Nightly summary" --body "$(tail -n 20 /var/log/nightly.log)"

# systemd unit — feed the body through a pipe so the service reads content,
# not a tty; source the credential env from a root-owned 600 file.
ExecStart=/bin/sh -c '. /etc/candy-mail.env; printf "%%s" "$BODY_TEXT" | \
    /usr/local/bin/candy-mail --from bot@example.com --to team@example.com'

# container — -i keeps STDIN open so the pipe actually delivers:
docker run --rm -i candy-mail --from me@example.com --to you@example.com < body.txt
```

STDIN accumulation is capped at 4 MiB per run: a runaway producer
(`yes | candy-mail`) is refused at the cap before any connection is paid, the
same loud-over-truncating posture as `Attachment::MAX_BYTES`. Credentials come
from the environment in every unattended shape — the `CANDY_MAIL_SMTP_*`
variables above are read identically under cron, systemd, and containers;
the flags `--helo/--ca-file/--timeout` override their env rows when passed.

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success — includes `--help`, `--version`, and `--render` |
| 1 | Every refusal: bad input, missing/invalid config, TLS or relay failure |

(The build script `tools/build-phar.php` runs on a different scale — it exits
2 on a failed preflight; it is a build tool, not the shipped CLI.)
Differentiated refusal codes per failure class remain a separate open
CONV/CLI decision; until then, script authors branch on 0 vs 1 only.

### What a success actually means

A send that returns 0 (and prints `✓ Email sent via …`) means the message was
**accepted by the transport for delivery**, not that it reached an inbox: on
SMTP the 250 after the DATA terminator is the relay queueing the mail, on
Resend the API reply is an accept, and in both cases bounces and rejections
arrive later, out-of-band, at the envelope sender. `quit()` deliberately
swallows farewell errors — by then the message is already queued, and a
protocol blip during the goodbye must not latch a false failure over a
successful accept. For CI, `--render` is the pre-flight wire check: it prints
the exact MIME document without dialling anything. (The success banner's
literal value stays "Email sent" in all 16 catalogs — renaming it churns
every translation for a wording win the README now carries honestly; decision
recorded here per CM-18.)

Error **messages** follow the standard `LC_ALL`/`LC_MESSAGES`/`LANG` environment (16
locales ship); the usage/help text is English by design. Examples under `examples/`
are offline-safe: without real credentials they print the composed message instead of
dialling any API.

## Internationalisation

All user-visible errors route through the `SugarCraft\Mail\Lang` facade (sugar-core's
shared `T` registry, namespace `mail`, catalog in `lang/`). English is the default;
a host application opts in process-wide:

```php
\SugarCraft\Core\I18n\T::setLocale(\SugarCraft\Core\I18n\T::detect()); // LC_ALL → LC_MESSAGES → LANG → 'en'
```

The CLI performs this at startup; library embedders decide. Missing keys in an active
locale fall back to English; the en catalog is the last stop before the raw key.

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
for cancellation on every send path (`Mailer::send`, `SmtpTransport::send`, and
`ResendTransport::send` all apply the same check) via `CancellationToken`: an **entry
pre-check only**
(`isCancelled()` before the conversation starts). Blocking I/O never yields to the event
loop mid-send, so `onCancel()` callbacks cannot fire during a running send — true
mid-flight cancellation would require an async transport (documented in
`CALIBER_LEARNINGS.md`).

## Tests

```bash
cd candy-mail && composer install && vendor/bin/phpunit -c phpunit.xml
```

411 tests, 2,021 assertions (PHP 8.3, PHPUnit 10.5). The suite includes fork-based
loopback fake-server tests for the SMTP conversation; no external network is ever dialled.

## Deprecated accessors

Per the house bare-accessor convention (`AGENTS.md`: *"Bare accessors (no* `get`*)*), `Attachment::getContent()` was renamed to its bare twin `content()` — the method slot was unclaimed (no `withContent` setter exists; the public readonly `$content` property names the raw backing field, not a callable, and PHP keeps property and method namespaces distinct). The legacy spelling stays a byte-identical one-line BC forwarder marked `@deprecated` with `REMOVED-IN: 2.0.0`; it will be removed in candy-mail v2.0.0.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `Mail` | `Attachment` | `getContent()` | `content()` |

Note the distinction the rename preserves: the readonly property `$content` is the raw nullable backing field, while `content()` resolves the bytes (including the defense-in-depth path re-read). `tests/AccessorDeprecationTest.php` pins BC identity on live objects from both factories, the property/method distinction, the forwarder shape, a discovery-first `src/` census with an inverse leg that reddens any unmarked public `get*()`, and the README table agreement. Internal call sites in `src/` (`SmtpTransport`, `ResendTransport`) were switched to the bare form in the same commit.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
