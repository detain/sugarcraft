<?php

declare(strict_types=1);

namespace ChannelBridge\Teams;

use ChannelBridge\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * The Bot Connector REST reply path (§8.2): replies are NOT HTTP responses
 * to the webhook when the activity was a notification — the durable answer
 * rides `POST {serviceUrl}/v3/conversations/{conversationId}/activities`
 * with the bot's bearer token, addressed by the inbound activity's
 * `conversationReference`. (The webhook MAY answer 200 with an activity
 * inline; the REST path is the one that works for proactive sends and
 * after-the-fact approvals, so the bridge uses it uniformly.)
 */
final class ConnectorApi
{
    public function __construct(
        private readonly BotToken $tokens,
        private readonly Log $log,
    ) {
    }

    /**
     * @param array<string,mixed> $reference the inbound conversationReference
     * @param array<string,mixed> $activity  the activity to send
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function sendActivity(array $reference, array $activity): PromiseInterface
    {
        $serviceUrl = (string) ($reference['serviceUrl'] ?? '');
        $conversationId = (string) ($reference['conversation']['id'] ?? '');
        if ($serviceUrl === '' || $conversationId === '') {
            return \React\Promise\reject(new \RuntimeException('incomplete conversationReference'));
        }

        return $this->tokens->token()->then(function (string $token) use ($serviceUrl, $conversationId, $activity, $reference): PromiseInterface {
            $body = [
                'type' => $activity['type'] ?? 'message',
                'from' => ['id' => (string) ($reference['bot'] ?? $reference['bot']['id'] ?? ''), 'name' => 'candy-code'],
                'recipient' => $reference['user'] ?? null,
                'conversation' => $reference['conversation'] ?? [],
                'locale' => 'en-US',
            ] + $activity;
            if (($activity['type'] ?? 'message') === 'message' && isset($body['text']) && \is_string($body['text']) && $body['text'] !== '') {
                $body['textType'] = 'text';
            }
            $body = \array_filter($body, static fn (mixed $v): bool => $v !== null);

            return $this->post(
                \rtrim($serviceUrl, '/') . '/v3/conversations/' . \rawurlencode($conversationId) . '/activities',
                $token,
                $body,
            );
        });
    }

    /**
     * An adaptive card asking a permission question (§8.3). The Submit
     * actions carry `data` that comes back inside an invoke activity, so
     * the answer is bound to the card that showed it.
     *
     * @param array<string,mixed> $ask
     */
    public static function approvalCard(array $ask, string $summary): array
    {
        $askId = (string) ($ask['askId'] ?? '');
        $button = static fn (string $verdict, string $label, string $color): array => [
            'type' => 'Action.Submit',
            'title' => $label,
            'data' => ['askId' => $askId, 'verdict' => $verdict],
            'style' => $color,
        ];

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.5',
                    'body' => [
                        ['type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'text' => 'candy-code needs approval'],
                        ['type' => 'TextBlock', 'wrap' => true, 'text' => $summary],
                    ],
                    'actions' => [
                        $button('once', 'Approve once', 'good'),
                        $button('always', 'Always allow', 'default'),
                        $button('reject', 'Deny', 'destructive'),
                    ],
                ],
            ]],
        ];
    }

    /** @param array<string,mixed> $body */
    private function post(string $url, string $token, array $body): PromiseInterface
    {
        return (new Browser())->withBase(null)->post($url, [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ], (string) \json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR))->then(
            function (ResponseInterface $response): array {
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                if ($status >= 400) {
                    throw new \RuntimeException('connector answered ' . $status);
                }
                $decoded = \json_decode($raw, true);

                return \is_array($decoded) ? $decoded : [];
            },
            function (\Throwable $e): never {
                $this->log->warn('connector_send_failed', ['error' => $e->getMessage()]);
                throw new \RuntimeException('connector send failed: ' . $e->getMessage(), 0, $e);
            },
        );
    }
}
