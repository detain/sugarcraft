<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Async\CancellationToken;

/**
 * Sends email via the Resend REST API.
 *
 * The HTTPS leg is pinned like the SMTP transports: peer verification on and
 * a TLS 1.2 protocol floor (see {@see curlOptions()}).
 *
 * SugarCraft-only transport: charmbracelet/pop speaks SMTP only, so there
 * is no upstream surface this mirrors - the contract is the Resend API
 * reference itself.
 *
 * @see https://resend.com/docs/api-reference/emails/send-email
 */
final class ResendTransport implements Transport
{
    public const API_URL = 'https://api.resend.com/emails';

    private string $apiKey;
    private string $apiUrl;

    /**
     * @param string $apiKey Resend API key. If empty, reads from RESEND_API_KEY env var.
     * @param string $apiUrl  TEST-ONLY endpoint seam (CM-08): empty keeps the
     *                        production API_URL. There is deliberately no
     *                        operator-facing base-URL story today — when proxy
     *                        or staging demand appears it becomes a documented
     *                        option, not a silent second constructor door.
     *                        The seam exists so the response arms (success,
     *                        api_error + truncation, network error) are
     *                        behavioural pins over a forked fake API instead
     *                        of reflection-only tautologies.
     */
    public function __construct(#[\SensitiveParameter] string $apiKey = '', string $apiUrl = '')
    {
        if ($apiKey === '') {
            $apiKey = $_ENV['RESEND_API_KEY'] ?? \getenv('RESEND_API_KEY', true) ?: '';
        }
        if ($apiKey !== '' && \preg_match('/^[\x21-\x7E]+$/', $apiKey) !== 1) {
            // CM-52 boundary parse: the key is emitted verbatim into
            // 'Authorization: Bearer <key>' by curlOptions(). A CRLF inside
            // an ambient RESEND_API_KEY would forge an EXTRA header on a
            // credential-bearing request - reject the shape at construction,
            // where the caller still owns the value, rather than mid-HTTP.
            // (Empty stays empty: send() owns that refusal.)
            throw new \InvalidArgumentException(Lang::t('resend.invalid_api_key'));
        }
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl !== '' ? $apiUrl : self::API_URL;
    }

    public function send(Email $email, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('resend.send_cancelled'));
        }

        // A transport that was never handed a credential must not dial the API
        // with an empty bearer. The CLI guards blank keys upstream of this
        // class; direct embedders get the same loud refusal here - the one
        // seam that actually spends the authenticated call.
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException(Lang::t('resend.no_api_key'));
        }

        // Envelope shape is checked BEFORE any HTTP work: a transport used
        // directly (not via Mailer) must refuse an empty envelope rather than
        // pay an authenticated API call for it.
        if ($email->from === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }
        if ($email->allRecipients() === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_recipient'));
        }

        if (!\function_exists('curl_init')) {
            // Fail loud with a named remedy instead of an undefined-function
            // Error mid-send (ext-curl is a suggested, not required, extension).
            throw new \RuntimeException(Lang::t('resend.curl_missing'));
        }

        $payload = $this->buildPayload($email);
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);

        $ch = \curl_init($this->apiUrl);
        \curl_setopt_array($ch, $this->curlOptions($json));

        $body  = \curl_exec($ch);
        $code  = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        unset($ch);

        if ($error !== '') {
            throw new \RuntimeException(Lang::t('resend.network_error', ['error' => $error]));
        }

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(Lang::t('resend.api_error', [
                'status' => $code,
                'body'   => \substr((string) $body, 0, 500),
            ]));
        }
    }

    public function name(): string
    {
        return 'resend';
    }

    /**
     * Mask the API key so var_dump()/print_r() of live object state shows
     * `****` (parity with SmtpTransport's password mask).
     *
     * Truth-flip: the old sentence promised reflection
     * never exposes the credential — false. serialize() and the (array) cast
     * and ReflectionProperty::getValue all read real property bytes:
     * serialize() is refused by __serialize() below, var_export()
     * resurrection by __set_state(), trace arguments by
     * #[\SensitiveParameter]; the raw text/cast/reflection read of a live
     * object cannot be hooked and must simply not be logged.
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->apiKey === '' ? '' : '****',
        ];
    }

    /**
     * Refuse serialisation outright — the API key would ride the wire
     * of any cache/session that stored this transport. Build it where used.
     *
     * @throws \RuntimeException always
     */
    public function __serialize(): array
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
    }

    /**
     * Refuse var_export() resurrection.
     *
     * @throws \RuntimeException always
     */
    public static function __set_state(array $properties): static
    {
        throw new \RuntimeException(Lang::t('transport.not_serializable'));
    }

    /**
     * Build the curl option map for the send request.
     *
     * Extracted so the network hardening — a bounded TCP-connect timeout and
     * explicit TLS peer/host verification — is assertable without issuing a
     * real HTTP request.
     *
     * @return array<int, mixed>
     */
    protected function curlOptions(string $json): array
    {
        return [
            \CURLOPT_POST           => true,
            \CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            \CURLOPT_POSTFIELDS     => $json,
            \CURLOPT_RETURNTRANSFER => true,
            // Cap the TCP handshake separately from the overall transfer so a
            // black-holed host fails fast instead of consuming the full timeout.
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT        => 30,
            // Verify the API's TLS certificate explicitly rather than trusting
            // curl's build-time defaults for this credential-bearing request.
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            // TLS 1.2 protocol floor, matching the SMTP transport's promise:
            // curl >= 7.34 treats this as a minimum and still negotiates 1.3
            // when the endpoint supports it.
            \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
            // An explicit empty PROXY switches off curl's ambient proxy
            // autodetection: http_proxy/ALL_PROXY in the environment would
            // silently route this bearer token through a third-party relay
            // the caller never chose. No-proxy is the safe default; honouring
            // CANDY_MAIL_PROXY is a later-if-demand decision, not a default.
            \CURLOPT_PROXY => '',
        ];
    }

    /**
     * Build the Resend API payload array.
     *
     * The published email payload fields for an attachment are filename,
     * content and content_type (https://resend.com/docs/api-reference/emails/send-email)
     * - the detected MIME type is forwarded so the provider pins the part
     * type. The payload is JSON, not MIME: it carries NO Content-ID field,
     * so an inline (cid) attachment cannot travel this transport at all -
     * building the payload refuses loudly instead of shipping a message
     * whose image reference could never resolve (the --render divergence
     * note, enforced at the wire).
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(Email $email): array
    {
        $payload = [
            'from'    => $this->firstAddr($email->from),
            'to'      => $email->to,
            'subject' => $email->subject ?? '(no subject)',
        ];

        $body = $email->bodyWithSignature();
        if ($email->htmlBody !== null) {
            $payload['html'] = $email->htmlBody;
            if ($body !== null) {
                $payload['text'] = $body;
            }
        } elseif ($body !== null) {
            $payload['text'] = $body;
        }

        if ($email->cc !== []) {
            $payload['cc'] = \implode(', ', $email->cc);
        }

        if ($email->bcc !== []) {
            $payload['bcc'] = \implode(', ', $email->bcc);
        }

        if ($email->replyTo !== null) {
            $payload['reply_to'] = $email->replyTo;
        }
        // The provider documents an arbitrary `headers` map on the send
        // payload (https://resend.com/docs/api-reference/emails/send-email);
        // the escape-hatch names ride it verbatim.
        if ($email->headers !== []) {
            $payload['headers'] = $email->headers;
        }

        // Attachments (Resend accepts base64-encoded content; content_type
        // carries the detected MIME type per the published field set).
        $resendAtts = [];
        foreach ($email->attachments as $att) {
            if ($att->cid !== null) {
                // An inline part's Content-ID has no JSON counterpart - a
                // silent drop would mail an <img src="cid:…"> that renders
                // as a broken placeholder forever. Loud refusal instead.
                throw new \InvalidArgumentException(Lang::t('resend.cid_unsupported', ['filename' => $att->filename]));
            }
            $resendAtts[] = [
                'filename'     => $att->filename,
                'content'      => \base64_encode($att->content()),
                'content_type' => $att->mimeType,
            ];
        }
        if ($resendAtts !== []) {
            $payload['attachments'] = $resendAtts;
        }

        return $payload;
    }

    private function firstAddr(array $addrs): string
    {
        // 'unknown@localhost' was a fabrication — it mailed every
        // senderless Resend payload from a forged address. Refuse instead;
        // Mailer/Email already guarantee a real from on the normal path.
        $first = $addrs[0] ?? null;
        if (!\is_string($first) || $first === '') {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }

        return $first;
    }
}
