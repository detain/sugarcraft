<?php

/**
 * English (default) translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient' => 'Email must have at least one recipient (to, cc, or bcc)',
    'mailer.no_from' => 'Email must have a from address',
    'mailer.send_cancelled' => 'Send cancelled before it started',

    // SmtpTransport.php
    'smtp.send_failed' => 'SMTP send failed: {message}',
    'smtp.connect_failed' => 'Cannot connect to {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'STARTTLS negotiation failed',
    'smtp.tls_required' => 'TLS is required but could not be established: {host} did not offer STARTTLS',
    'smtp.auth_insecure' => 'Refusing to authenticate over an insecure (unencrypted) SMTP channel',
    'smtp.not_connected' => 'Not connected',
    'smtp.no_response' => 'Server sent no response',
    'smtp.empty_response' => 'Server sent empty response',
    'smtp.unexpected_response' => 'SMTP unexpected response: {response}',
    'smtp.send_cancelled' => 'SMTP send cancelled before it started',
    'smtp.write_error' => 'Failed to write to SMTP server',
    'smtp.incomplete_write' => 'Incomplete write to SMTP server',
    'smtp.invalid_helo_host' => 'HELO host {helo} is not a valid hostname (letters, digits, dots and hyphens only)',
    'smtp.auth_unavailable' => 'The SMTP server {host} did not advertise AUTH; refusing to relay unauthenticated while credentials are configured',
    'smtp.response_too_long' => 'SMTP server response exceeded the size limit',
    'smtp.response_timeout' => 'SMTP server response exceeded the time budget',
    'transport.not_serializable' => 'A transport holding credentials cannot be serialized or re-imported; construct it where it is used',

    // Email.php
    'email.crlf_in_address' => 'CRLF injection detected in email address',
    'email.invalid_address' => 'Invalid email address: {addr}',
    'email.crlf_in_header' => 'CRLF injection detected in {field} header',
    'email.invalid_mime_type' => 'Invalid attachment MIME type (expected type/subtype): {mime}',
    'email.invalid_header_char' => 'The {field} header contains forbidden control characters (printable text only)',

    // ResendTransport.php
    'resend.send_cancelled' => 'Resend send cancelled before it started',
    'resend.curl_missing' => 'ext-curl is required for the Resend transport (SMTP transport needs no extensions for plain SMTP; ext-openssl for TLS)',
    'resend.no_api_key' => 'Resend transport has no API key: pass one to the constructor or set RESEND_API_KEY',
    'resend.network_error' => 'Resend network error: {error}',
    'resend.api_error' => 'Resend API error ({status}): {body}',

    // bin/candy-mail
    'attachment.unreadable' => 'Attachment file is not readable: {path}',
    'attachment.no_path' => 'Attachment path must be provided when attaching by filename',
    'attachment.invalid_cid' => 'Invalid Content-ID format: {cid} (RFC 2392 requires msg-id format)',
    'attachment.not_a_file' => 'Attachment path {path} is not a regular file',
    'attachment.too_large' => 'Attachment exceeds the {limit}-byte limit',
    'cli.error' => 'Error: {message}',
    'cli.transport_error' => 'Transport error: {message}',
    'cli.send_failed' => 'Send failed: {message}',
    'cli.email_sent' => '✓ Email sent via {transport}.',
    'cli.no_to_recipient' => 'No --to recipient specified',
    'cli.attachment_not_found' => 'Attachment file not found: {file}',
    'cli.no_transport' => 'No transport configured. Set RESEND_API_KEY or CANDY_MAIL_SMTP_HOST environment variable.',
    'cli.unknown_option' => 'Unknown option: {option}',
    'cli.did_you_mean' => 'Did you mean {suggestion}?',
    'cli.invalid_port' => 'Invalid SMTP port: {port} (must be between 1 and 65535)',
    'cli.missing_value' => 'Option {option} requires a value (use --{option}=VALUE for values that begin with a dash)',
    'cli.no_sender' => 'No sender address: pass --from=ADDRESS or set CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => 'Environment variable {name} has an invalid flag value {value}; use one of 1/true/yes/on or 0/false/no/off',
    'cli.ca_file_unreadable' => 'CA file {file} is not a readable regular file',
    'cli.invalid_timeout' => 'Timeout {timeout} is not a number of seconds between 1 and 3600',
    'cli.render_resend_divergence' => 'Note: --render previews the SMTP MIME envelope; your configured transport is Resend, which posts a JSON payload instead. The bytes below are not what ships.',
    'cli.empty_message' => 'Nothing to send: the message carries no body, no HTML part, and no attachment. Pass --body, pipe input, or use --html.',
    'cli.stdin_too_large' => 'STDIN input exceeded the {limit}-byte cap; trim the input or attach it as a file.',
];
