<?php

declare(strict_types=1);

/**
 * CandyMail CC/BCC and HTML body example.
 *
 * Run: php examples/html-email.php
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

$email = new Email(
    from:     ['sender@example.com'],
    to:       ['primary@example.com'],
    cc:       ['cc1@example.com', 'cc2@example.com'],
    bcc:      ['bcc@example.com'],
    subject:  'Multi-recipient HTML email',
    body:     'This is the plain-text fallback.',
    htmlBody: <<<'HTML'
<html>
<body style="font-family: sans-serif;">
  <h2>Hello!</h2>
  <p>This email has <strong>HTML</strong> formatting.</p>
  <p>CC and BCC recipients are also included.</p>
</body>
</html>
HTML,
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
    echo "✓ HTML multi-recipient email sent.\n";
} catch (\Throwable $e) {
    echo "✗ Failed: {$e->getMessage()}\n";
    exit(1);
}
