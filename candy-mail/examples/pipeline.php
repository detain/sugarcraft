<?php

declare(strict_types=1);

/**
 * CandyMail CLI pipeline demo — pipe markdown → email.
 *
 * Run: cat message.md | php examples/pipeline.php
 * Or:  echo "Hello world" | php examples/pipeline.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport};

// Read body from STDIN
$body = '';
while (!\feof(STDIN)) {
    $body .= \fgets(STDIN);
}
$body = \trim($body);

if ($body === '') {
    echo "No body from STDIN — try: echo 'Hello' | php examples/pipeline.php\n";
    exit(1);
}

$transport = new ResendTransport(\getenv('RESEND_API_KEY') ?: 're_placeholder');
$mailer = new Mailer($transport);

$email = new Email(
    from:    [\getenv('CANDY_MAIL_FROM') ?: 'sender@example.com'],
    to:      [$argv[1] ?? 'recipient@example.com'],
    subject: $argv[2] ?? 'Hello',
    body:    $body,
);

try {
    $mailer->send($email);
    echo "✓ Sent.\n";
} catch (\Throwable $e) {
    echo "✗ {$e->getMessage()}\n";
}
