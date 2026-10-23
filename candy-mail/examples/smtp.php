<?php

declare(strict_types=1);

/**
 * CandyMail SMTP example.
 *
 * Run: CANDY_MAIL_SMTP_HOST=smtp.gmail.com \
 *      CANDY_MAIL_SMTP_USERNAME=you@gmail.com \
 *      CANDY_MAIL_SMTP_PASSWORD=<app-password> \
 *      php examples/smtp.php
 *
 * Without CANDY_MAIL_SMTP_HOST it prints an offline MIME preview instead of
 * dialling. Gmail (and similar providers) require an APP PASSWORD — account
 * passwords stopped working for SMTP AUTH.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, SmtpTransport};

/** Read an env var, treating unset and empty alike. */
function envNonEmpty(string $name): ?string
{
    $value = $_ENV[$name] ?? \getenv($name, true);
    return \is_string($value) && $value !== '' ? $value : null;
}

$email = new Email(
    from:    [envNonEmpty('CANDY_MAIL_SMTP_USERNAME') ?? 'you@example.com'],
    to:      ['recipient@example.com'],
    subject: 'Hello via SMTP',
    body:    "Sent directly via SMTP from CandyMail!\n",
);

$host = envNonEmpty('CANDY_MAIL_SMTP_HOST');
if ($host === null) {
    echo (new SmtpTransport('localhost'))->buildMimeMessage($email);
    echo "\n[offline preview] set CANDY_MAIL_SMTP_HOST (+USERNAME/PASSWORD) to send via SMTP.\n";
    exit(0);
}

$transport = new SmtpTransport(
    host:     $host,
    port:     (int) (envNonEmpty('CANDY_MAIL_SMTP_PORT') ?? '587'),
    username: envNonEmpty('CANDY_MAIL_SMTP_USERNAME') ?? '',
    password: envNonEmpty('CANDY_MAIL_SMTP_PASSWORD') ?? '',
);

$mailer = new Mailer($transport);

try {
    $mailer->send($email);
    echo "✓ SMTP send succeeded.\n";
} catch (\Throwable $e) {
    echo "✗ SMTP failed: {$e->getMessage()}\n";
    exit(1);
}
