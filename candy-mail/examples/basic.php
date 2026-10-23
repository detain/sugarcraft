<?php

declare(strict_types=1);

/**
 * CandyMail basic usage — Resend API.
 *
 * Run: RESEND_API_KEY=re_xxx php examples/basic.php
 * Without RESEND_API_KEY it prints the composed MIME message instead of
 * dialling — the examples never send real mail unless you give them a key.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport, SmtpTransport};

/** Read an env var, treating unset and empty alike. */
function envNonEmpty(string $name): ?string
{
    $value = $_ENV[$name] ?? \getenv($name, true);
    return \is_string($value) && $value !== '' ? $value : null;
}

$email = new Email(
    from:    ['sender@example.com'],
    to:      ['recipient@example.com'],
    subject: 'Hello from CandyMail',
    body:    <<<BODY
Hello!

This email was sent via the Resend API using CandyMail.

Best,
The CandyMail Team
BODY,
);

$apiKey = envNonEmpty('RESEND_API_KEY');
if ($apiKey === null) {
    echo (new SmtpTransport('localhost'))->buildMimeMessage($email);
    echo "\n[offline preview] set RESEND_API_KEY to send this via the Resend API.\n";
    exit(0);
}

$mailer = new Mailer(new ResendTransport($apiKey));

try {
    $mailer->send($email);
    echo "✓ Email sent successfully.\n";
} catch (\Throwable $e) {
    echo "✗ Send failed: {$e->getMessage()}\n";
    exit(1);
}
