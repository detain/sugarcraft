<?php

/**
 * English (default) translations for sugar-post.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient'        => 'Email must have at least one recipient (to, cc, or bcc)',
    'mailer.no_from'             => 'Email must have a from address',
    'mailer.send_cancelled'      => 'Send cancelled before completion',

    // SmtpTransport.php
    'smtp.send_failed'           => 'SMTP send failed: {message}',
    'smtp.connect_failed'        => 'Cannot connect to {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS negotiation failed',
    'smtp.not_connected'         => 'Not connected',
    'smtp.no_response'           => 'Server sent no response',
    'smtp.empty_response'        => 'Server sent empty response',
    'smtp.unexpected_response'   => 'SMTP unexpected response: {response}',
    'smtp.send_cancelled'        => 'SMTP send cancelled before completion',
    'smtp.write_error'           => 'Failed to write to SMTP server',
    'smtp.incomplete_write'      => 'Incomplete write to SMTP server',

    // Email.php
    'email.crlf_in_address'     => 'CRLF injection detected in email address',
    'email.invalid_address'     => 'Invalid email address: {addr}',
    'email.crlf_in_header'      => 'CRLF injection detected in {field} header',

    // ResendTransport.php
    'resend.send_cancelled'     => 'Resend send cancelled before completion',
    'resend.network_error'       => 'Resend network error: {error}',
    'resend.api_error'           => 'Resend API error ({status}): {body}',

    // bin/pop
    'attachment.unreadable'      => 'Attachment file is not readable: {path}',
    'attachment.no_path'        => 'Attachment path must be provided when attaching by filename',
    'attachment.invalid_cid'    => 'Invalid Content-ID format: {cid} (RFC 2392 requires msg-id format)',
    'cli.error'                  => 'Error: {message}',
    'cli.transport_error'        => 'Transport error: {message}',
    'cli.send_failed'            => 'Send failed: {message}',
    'cli.email_sent'             => '✓ Email sent via {transport}.',
    'cli.no_to_recipient'        => 'No --to recipient specified',
    'cli.attachment_not_found'   => 'Attachment file not found: {file}',
    'cli.no_transport'           => 'No transport configured. Set RESEND_API_KEY or POP_SMTP_HOST environment variable.',
];
