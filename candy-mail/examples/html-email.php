<?php

declare(strict_types=1);

/**
 * CandyMail CC/BCC and HTML body example.
 *
 * Run: php examples/html-email.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport};

$transport = new ResendTransport(getenv('RESEND_API_KEY') ?: 're_placeholder');
$mailer = new Mailer($transport);

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

try {
    $mailer->send($email);
    echo "✓ HTML multi-recipient email sent.\n";
} catch (\Throwable $e) {
    echo "✗ Failed: {$e->getMessage()}\n";
}
