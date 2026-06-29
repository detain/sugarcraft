<?php

declare(strict_types=1);

namespace SugarCraft\Post;

use SugarCraft\Async\CancellationToken;
use SugarCraft\Post\Lang;

/**
 * Sends email via the Resend REST API.
 *
 * @see https://resend.com/docs/api-reference/emails/send-email
 */
final class ResendTransport implements Transport
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function send(Email $email, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('resend.send_cancelled'));
        }

        $payload = $this->buildPayload($email);
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);

        $ch = \curl_init('https://api.resend.com/emails');
        \curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            \CURLOPT_POSTFIELDS     => $json,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 30,
        ]);

        $body  = \curl_exec($ch);
        $code  = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);

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
     * Build the Resend API payload array.
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

        // Attachments (Resend accepts base64-encoded content)
        $resendAtts = [];
        foreach ($email->attachments as $att) {
            $resendAtts[] = [
                'filename' => $att->filename,
                'content'  => \base64_encode($att->getContent()),
            ];
        }
        if ($resendAtts !== []) {
            $payload['attachments'] = $resendAtts;
        }

        return $payload;
    }

    private function firstAddr(array $addrs): string
    {
        return $addrs[0] ?? 'unknown@localhost';
    }
}
