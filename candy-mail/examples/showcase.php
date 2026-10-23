<?php

declare(strict_types=1);

/**
 * CandyMail API showcase — builds Email DTOs and prints their fields
 * so the structure is visible without needing SMTP / Resend access.
 *
 * Run: php examples/showcase.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\Email;

function dump(string $heading, Email $e): void
{
    echo "=== {$heading} ===\n";
    echo "  From       : " . implode(', ', $e->from) . "\n";
    echo "  To         : " . implode(', ', $e->to)   . "\n";
    if ($e->cc)  { echo "  CC         : " . implode(', ', $e->cc)  . "\n"; }
    if ($e->bcc) { echo "  BCC        : " . implode(', ', $e->bcc) . "\n"; }
    if ($e->replyTo) { echo "  Reply-To   : {$e->replyTo}\n"; }
    echo "  Subject    : " . ($e->subject ?? '(none)') . "\n";
    echo "  Plain body : " . strlen($e->body ?? '') . " bytes\n";
    if ($e->htmlBody !== null) {
        echo "  HTML body  : " . strlen($e->htmlBody) . " bytes\n";
    }
    if ($e->attachments) {
        echo "  Attachments: " . count($e->attachments) . "\n";
    }
    echo "\n";
}

dump('Plain-text email', new Email(
    from:    ['noreply@sugarcraft.dev'],
    to:      ['ada@example.com'],
    subject: 'Welcome to CandyMail',
    body:    "Hi Ada,\n\nThanks for installing CandyMail.\n\n— The team\n",
));

dump('HTML + plain-text multipart with CC / BCC', new Email(
    from:     ['noreply@sugarcraft.dev'],
    to:       ['team@example.com'],
    cc:       ['cc1@example.com', 'cc2@example.com'],
    bcc:      ['audit@example.com'],
    replyTo:  'replies@sugarcraft.dev',
    subject:  'Weekly digest',
    body:     "Plain-text fallback for clients without HTML support.\n",
    htmlBody: "<h2>Weekly digest</h2><p>Three new releases this week.</p>",
));

echo "=== Transports ===\n";
echo "  SmtpTransport    — host, port, user, pass; TLS required by default\n";
echo "                     (withImplicitTls/withRequireTls(false)/withCaFile)\n";
echo "  ResendTransport  — Resend API (RESEND_API_KEY env var)\n";
echo "  Mailer(\$tx)      — send(\$email): void, throws on failure\n";
