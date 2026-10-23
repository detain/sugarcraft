<?php

declare(strict_types=1);

/**
 * CandyMail attachment demo.
 *
 * Run: php examples/attachments.php
 * Prints an offline MIME preview unless RESEND_API_KEY is set (or use
 * examples/smtp.php with CANDY_MAIL_SMTP_HOST to send via SMTP).
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport, SmtpTransport};

/** Read an env var, treating unset and empty alike. */
function envNonEmpty(string $name): ?string
{
    $value = $_ENV[$name] ?? \getenv($name, true);
    return \is_string($value) && $value !== '' ? $value : null;
}

// Create a dummy attachment file
$tmpDir = sys_get_temp_dir() . '/candy-mail-demo-' . uniqid();
mkdir($tmpDir);
$pdfPath = "{$tmpDir}/invoice.pdf";
file_put_contents($pdfPath, '%PDF-1.4 fake pdf content');
$csvPath = "{$tmpDir}/data.csv";
file_put_contents($csvPath, "name,amount\nAcme Corp,500\n");
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmpDir}/*") ?: []) ?: rmdir($tmpDir));

$email = (new Email(
    from:    ['sender@example.com'],
    to:      ['recipient@example.com'],
    subject: 'Your invoice is attached',
    body:    "Please find your invoice attached.\n",
))
    ->withAttachment('invoice.pdf', $pdfPath)
    ->withAttachment('data.csv', $csvPath)
    ->withSignature('— CandyMail');

$apiKey = envNonEmpty('RESEND_API_KEY');
if ($apiKey === null) {
    echo (new SmtpTransport('localhost'))->buildMimeMessage($email);
    echo "\n[offline preview] set RESEND_API_KEY to send this via the Resend API.\n";
    exit(0);
}

$mailer = new Mailer(new ResendTransport($apiKey));

try {
    $mailer->send($email);
    echo "✓ Email with attachments sent.\n";
} catch (\Throwable $e) {
    echo "✗ Failed: {$e->getMessage()}\n";
    exit(1);
}
