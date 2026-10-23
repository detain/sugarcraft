<?php

declare(strict_types=1);

/**
 * CandyMail CLI pipeline demo — pipe markdown → email.
 *
 * Run: cat message.md | php examples/pipeline.php
 * Or:  echo "Hello world" | php examples/pipeline.php
 * Prints an offline MIME preview unless RESEND_API_KEY is set.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport, SmtpTransport};

/** Read an env var, treating unset and empty alike. */
function envNonEmpty(string $name): ?string
{
    $value = $_ENV[$name] ?? \getenv($name, true);
    return \is_string($value) && $value !== '' ? $value : null;
}

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

$email = new Email(
    from:    [envNonEmpty('CANDY_MAIL_FROM') ?? 'sender@example.com'],
    to:      [$argv[1] ?? 'recipient@example.com'],
    subject: $argv[2] ?? 'Hello',
    body:    $body,
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
    echo "✓ Sent.\n";
} catch (\Throwable $e) {
    echo "✗ {$e->getMessage()}\n";
    exit(1);
}
