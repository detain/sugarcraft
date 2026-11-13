<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Async\CancellationToken;

/**
 * Sends email via the Mailgun REST API (POST /v3/{domain}/messages).
 *
 * The request is a multipart/form-data body assembled by hand
 * ({@see buildMultipart()}): attachments are in-memory bytes, and curl's
 * CURLFile would force a temp file per part. Building the bytes ourselves
 * also makes the wire shape assertable without a network.
 *
 * Same hardening as {@see ResendTransport}: TLS peer verification on, TLS 1.2
 * floor, ambient proxy autodetection switched off (the Basic credential must
 * not ride a relay the caller never chose), serialisation refused, key masked
 * in __debugInfo().
 *
 * SugarCraft-only transport: charmbracelet/pop speaks SMTP only, so there is
 * no upstream surface this mirrors - the contract is the Mailgun API reference.
 *
 * @see https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/messages
 */
final class MailgunTransport implements Transport
{
    public const REGION_US = 'us';
    public const REGION_EU = 'eu';

    private const BASE_US = 'https://api.mailgun.net';
    private const BASE_EU = 'https://api.eu.mailgun.net';

    private string $apiKey;
    private string $domain;
    private string $region;
    private string $baseUrl;

    /**
     * @param string $apiKey  Mailgun private API key. Empty reads MAILGUN_API_KEY
     *                        from the environment (the CLI feeds
     *                        CANDY_MAIL_MAILGUN_API_KEY in explicitly).
     * @param string $domain  The sending domain registered with Mailgun
     *                        (it names the URL path, so the grammar is strict).
     * @param string $region  'us' (default) or 'eu' - the EU region lives on a
     *                        different host and an account is bound to one.
     * @param string $baseUrl TEST-ONLY endpoint seam, same stance as
     *                        {@see ResendTransport}: empty keeps the regional
     *                        production host; the seam lets the response arms
     *                        run against a forked fake API.
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey = '',
        string $domain = '',
        string $region = self::REGION_US,
        string $baseUrl = '',
    ) {
        if ($apiKey === '') {
            $apiKey = $_ENV['MAILGUN_API_KEY'] ?? \getenv('MAILGUN_API_KEY', true) ?: '';
        }
        if ($apiKey !== '' && \preg_match('/^[\x21-\x7E]+$/', $apiKey) !== 1) {
            // The key becomes 'Authorization: Basic base64(api:key)'; base64
            // would neutralise CRLF, but a key that cannot be a real token is
            // a config error best caught where the caller still owns it.
            throw new \InvalidArgumentException(Lang::t('mailgun.invalid_api_key'));
        }
        if ($domain !== '' && \preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) !== 1) {
            // The domain is interpolated into the URL path: anything outside
            // the hostname grammar could redirect the credentialed POST.
            throw new \InvalidArgumentException(Lang::t('mailgun.invalid_domain', ['domain' => $domain]));
        }
        $region = \strtolower($region);
        if ($region !== self::REGION_US && $region !== self::REGION_EU) {
            throw new \InvalidArgumentException(Lang::t('mailgun.invalid_region', ['region' => $region]));
        }
        $this->apiKey  = $apiKey;
        $this->domain  = $domain;
        $this->region  = $region;
        $this->baseUrl = $baseUrl !== '' ? \rtrim($baseUrl, '/') : ($region === self::REGION_EU ? self::BASE_EU : self::BASE_US);
    }

    /** The full messages endpoint this transport posts to. */
    public function endpoint(): string
    {
        return $this->baseUrl . '/v3/' . $this->domain . '/messages';
    }

    public function send(Email $email, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('mailgun.send_cancelled'));
        }
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException(Lang::t('mailgun.no_api_key'));
        }
        if ($this->domain === '') {
            throw new \InvalidArgumentException(Lang::t('mailgun.no_domain'));
        }
        // Envelope shape before any HTTP work, as in the other transports.
        if ($email->from === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }
        if ($email->allRecipients() === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_recipient'));
        }
        if (!\function_exists('curl_init')) {
            throw new \RuntimeException(Lang::t('mailgun.curl_missing'));
        }

        [$boundary, $body] = $this->buildMultipart($email);

        $ch = \curl_init($this->endpoint());
        \curl_setopt_array($ch, $this->curlOptions($boundary, $body));

        $response = \curl_exec($ch);
        $code     = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error    = \curl_error($ch);
        unset($ch);

        if ($error !== '') {
            throw new \RuntimeException(Lang::t('mailgun.network_error', ['error' => $error]));
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(Lang::t('mailgun.api_error', [
                'status' => $code,
                'body'   => \substr((string) $response, 0, 500),
            ]));
        }
    }

    public function name(): string
    {
        return 'mailgun';
    }

    /** Mask the key in var_dump()/print_r(); parity with the other transports. */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->apiKey === '' ? '' : '****',
            'domain' => $this->domain,
            'region' => $this->region,
        ];
    }

    /**
     * @throws \RuntimeException always - the key must not ride a cache/session
     */
    public function __serialize(): array
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
    }

    /**
     * @param array<string,mixed> $properties
     *
     * @throws \RuntimeException always
     */
    public static function __set_state(array $properties): static
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
    }

    /**
     * @return array<int, mixed>
     */
    protected function curlOptions(string $boundary, string $body): array
    {
        return [
            \CURLOPT_POST           => true,
            \CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . \base64_encode('api:' . $this->apiKey),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            \CURLOPT_POSTFIELDS     => $body,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT        => 30,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_SSLVERSION     => \CURL_SSLVERSION_TLSv1_2,
            // Empty PROXY disables env proxy autodetection (see ResendTransport).
            \CURLOPT_PROXY          => '',
        ];
    }

    /**
     * The ordered list of form parts for the send call.
     *
     * Recipients repeat their field name (Mailgun's documented shape for
     * multiple addresses), extension headers ride `h:<Name>`, an inline
     * attachment goes in the `inline` field with its Content-ID as the part
     * filename (Mailgun resolves `cid:<filename>` against it), and
     * regular files in `attachment`.
     *
     * @return list<array{name: string, value: string, filename?: string, type?: string}>
     */
    protected function buildParts(Email $email): array
    {
        $first = $email->from[0] ?? null;
        if (!\is_string($first) || $first === '') {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }

        $parts = [['name' => 'from', 'value' => $first]];
        foreach ($email->to as $addr) {
            $parts[] = ['name' => 'to', 'value' => $addr];
        }
        foreach ($email->cc as $addr) {
            $parts[] = ['name' => 'cc', 'value' => $addr];
        }
        foreach ($email->bcc as $addr) {
            $parts[] = ['name' => 'bcc', 'value' => $addr];
        }
        $parts[] = ['name' => 'subject', 'value' => $email->subject ?? '(no subject)'];

        $text = $email->bodyWithSignature();
        if ($text !== null) {
            $parts[] = ['name' => 'text', 'value' => $text];
        }
        if ($email->htmlBody !== null) {
            $parts[] = ['name' => 'html', 'value' => $email->htmlBody];
        }
        if ($email->replyTo !== null) {
            $parts[] = ['name' => 'h:Reply-To', 'value' => $email->replyTo];
        }
        foreach ($email->headers as $name => $value) {
            $parts[] = ['name' => 'h:' . $name, 'value' => (string) $value];
        }

        foreach ($email->attachments as $att) {
            if ($att->cid !== null) {
                $cid = \trim(\preg_replace('/^cid:/i', '', $att->cid) ?? $att->cid, '<>');
                $parts[] = ['name' => 'inline', 'value' => $att->content(), 'filename' => $cid, 'type' => $att->mimeType];
                continue;
            }
            $parts[] = ['name' => 'attachment', 'value' => $att->content(), 'filename' => $att->filename, 'type' => $att->mimeType];
        }

        return $parts;
    }

    /**
     * Serialise the parts as multipart/form-data.
     *
     * @return array{0: string, 1: string} [boundary, body]
     */
    protected function buildMultipart(Email $email): array
    {
        $parts = $this->buildParts($email);

        // Re-roll until the delimiter occurs in no part value: a boundary
        // inside an attachment would truncate the part on the server side.
        do {
            $boundary = 'candymail' . \bin2hex(\random_bytes(12));
            $clash = false;
            foreach ($parts as $p) {
                if (\str_contains($p['value'], $boundary)) {
                    $clash = true;
                    break;
                }
            }
        } while ($clash);

        $body = '';
        foreach ($parts as $p) {
            $disp = 'form-data; name="' . self::quote($p['name']) . '"';
            if (isset($p['filename'])) {
                $disp .= '; filename="' . self::quote($p['filename']) . '"';
            }
            $body .= '--' . $boundary . "\r\n" . 'Content-Disposition: ' . $disp . "\r\n";
            if (isset($p['type'])) {
                $body .= 'Content-Type: ' . $p['type'] . "\r\n";
            }
            $body .= "\r\n" . $p['value'] . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        return [$boundary, $body];
    }

    /** Make a value safe inside a quoted Content-Disposition parameter. */
    private static function quote(string $v): string
    {
        return \str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $v);
    }
}
