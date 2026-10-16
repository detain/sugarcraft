<?php

declare(strict_types=1);

/**
 * CandyMail SMTP example.
 *
 * Run: php examples/smtp.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, SmtpTransport};

$transport = new SmtpTransport(
    host:     'smtp.gmail.com',
    port:     587,
    username: 'your@gmail.com',
    password: 'your-app-password',
);

$mailer = new Mailer($transport);

$email = new Email(
    from:    ['your@gmail.com'],
    to:      ['recipient@example.com'],
    subject: 'Hello via SMTP',
    body:    "Sent directly via SMTP from CandyMail!\n",
);

try {
    $mailer->send($email);
    echo "✓ SMTP send succeeded.\n";
} catch (\Throwable $e) {
    echo "✗ SMTP failed: {$e->getMessage()}\n";
}
