<?php

declare(strict_types=1);

/**
 * SugarPost attachment demo.
 *
 * Run: php examples/attachments.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Post\{Email, Mailer, ResendTransport};

// Create a dummy attachment file
$tmpDir = sys_get_temp_dir() . '/pop-demo-' . uniqid();
mkdir($tmpDir);
$pdfPath = "{$tmpDir}/invoice.pdf";
file_put_contents($pdfPath, '%PDF-1.4 fake pdf content');
$csvPath = "{$tmpDir}/data.csv";
file_put_contents($csvPath, "name,amount\nAcme Corp,500\n");
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmpDir}/*") ?: []) ?: rmdir($tmpDir));

$transport = new ResendTransport(getenv('RESEND_API_KEY') ?: 're_placeholder');
$mailer = new Mailer($transport);

$email = (new Email(
    from:    ['sender@example.com'],
    to:      ['recipient@example.com'],
    subject: 'Your invoice is attached',
    body:    "Please find your invoice attached.\n",
))
    ->withAttachment('invoice.pdf', $pdfPath)
    ->withAttachment('data.csv', $csvPath)
    ->withSignature('— SugarPost');

try {
    $mailer->send($email);
    echo "✓ Email with attachments sent.\n";
} catch (\Throwable $e) {
    echo "✗ Failed: {$e->getMessage()}\n";
}
