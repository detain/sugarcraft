<?php

declare(strict_types=1);

/**
 * CandyMail CC/BCC, HTML body, and inline (CID) image example.
 *
 * Run: php examples/html-email.php
 * Prints an offline MIME preview unless RESEND_API_KEY is set.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Mail\{Email, Mailer, ResendTransport, SmtpTransport};
use SugarCraft\Core\Util\Sanitize;

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
<html lang="en">
<body style="font-family: sans-serif;">
  <h2>Hello!</h2>
  <p>This email has <strong>HTML</strong> formatting.</p>
  <p>CC and BCC recipients are also included.</p>
  <p><img src="cid:company-logo" alt="Company logo"></p>
</body>
</html>
HTML,
);

// Inline part: the image referenced above as cid:company-logo.
// withInlineAttachment() takes a path; the bytes come from a temp file so the
// example stays self-contained. CID embedding rides the SMTP transport's MIME
// builder - on Resend the reference is not carried (README: use SMTP for CID).
$logoPath = \sys_get_temp_dir() . '/candy-mail-example-logo.png';
\file_put_contents($logoPath, \base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
));
register_shutdown_function(static function () use ($logoPath): void {
    if (\is_file($logoPath)) {
        \unlink($logoPath);
    }
});
$email = $email->withInlineAttachment($logoPath, 'company-logo', 'logo.png');

$apiKey = envNonEmpty('RESEND_API_KEY');
if ($apiKey === null) {
    echo (new SmtpTransport('localhost'))->buildMimeMessage($email);
    echo "\n[offline preview] set RESEND_API_KEY to send this via the Resend API.\n";
    exit(0);
}

$mailer = new Mailer(new ResendTransport($apiKey));

// The state glyphs in the echoes below are decorative: the words carry the
// outcome, so success/failure reads correctly even where a screen reader,
// font, or locale substitution never renders the mark itself.
try {
    $mailer->send($email);
    echo "✓ HTML multi-recipient email sent.\n";
} catch (\Throwable $e) {
    echo '✗ Failed: ' . Sanitize::untrusted($e->getMessage()) . "\n";
    exit(1);
}
